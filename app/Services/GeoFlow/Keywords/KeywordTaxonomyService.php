<?php

namespace App\Services\GeoFlow\Keywords;

use App\Models\Keyword;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KeywordTaxonomyService
{
    /** @param array<string,mixed> $data */
    public function createNode(KeywordTaxonomy $taxonomy, array $data): KeywordNode
    {
        return DB::transaction(function () use ($taxonomy, $data): KeywordNode {
            $taxonomy = KeywordTaxonomy::query()->lockForUpdate()->findOrFail($taxonomy->getKey());
            $parent = $this->lockedParent($data['parent_id'] ?? null);
            if ($parent && (int) $parent->taxonomy_id !== (int) $taxonomy->id) {
                $this->reject('parent_cross_taxonomy');
            }

            $depth = $parent ? ((int) $parent->depth + 1) : 0;
            $this->assertMaximumDepth($depth);
            $name = $this->requiredName($data['name'] ?? null);
            $slug = $this->slug($data['slug'] ?? $name);
            $this->assertSlugAvailable($taxonomy, $slug);
            $path = ($parent ? $parent->path.'/' : '').$slug;
            if (mb_strlen($path) > 1000) {
                $this->reject('path_too_long');
            }

            return KeywordNode::query()->create([
                'taxonomy_id' => $taxonomy->id,
                'parent_id' => $parent?->id,
                'name' => $name,
                'slug' => $slug,
                'path' => $path,
                'depth' => $depth,
                'sort_order' => $this->sortOrder($data['sort_order'] ?? 0),
            ]);
        });
    }

    public function moveNode(KeywordNode $node, ?KeywordNode $parent, int $sortOrder): KeywordNode
    {
        return DB::transaction(function () use ($node, $parent, $sortOrder): KeywordNode {
            $node = KeywordNode::query()->lockForUpdate()->findOrFail($node->getKey());
            $parent = $parent
                ? KeywordNode::query()->lockForUpdate()->findOrFail($parent->getKey())
                : null;
            if ($parent && (int) $parent->taxonomy_id !== (int) $node->taxonomy_id) {
                $this->reject('parent_cross_taxonomy');
            }
            if ($parent && (int) $parent->id === (int) $node->id) {
                $this->reject('parent_self');
            }
            if ($parent && str_starts_with((string) $parent->path, (string) $node->path.'/')) {
                $this->reject('parent_descendant');
            }

            /** @var Collection<int,KeywordNode> $subtree */
            $subtree = KeywordNode::query()
                ->where('taxonomy_id', $node->taxonomy_id)
                ->where(function ($query) use ($node): void {
                    $query->where('path', $node->path)->orWhere('path', 'like', $node->path.'/%');
                })
                ->orderBy('depth')
                ->lockForUpdate()
                ->get();
            $newDepth = $parent ? ((int) $parent->depth + 1) : 0;
            $height = (int) $subtree->max(fn (KeywordNode $descendant): int => (int) $descendant->depth) - (int) $node->depth;
            $this->assertMaximumDepth($newDepth + $height);

            $oldPath = (string) $node->path;
            $newPath = ($parent ? $parent->path.'/' : '').$node->slug;
            if (mb_strlen($newPath) + $this->longestDescendantSuffixLength($subtree, $oldPath) > 1000) {
                $this->reject('path_too_long');
            }

            foreach ($subtree as $descendant) {
                $suffix = substr((string) $descendant->path, strlen($oldPath));
                $attributes = [
                    'path' => $newPath.$suffix,
                    'depth' => $newDepth + ((int) $descendant->depth - (int) $node->depth),
                ];
                if ((int) $descendant->id === (int) $node->id) {
                    $attributes['parent_id'] = $parent?->id;
                    $attributes['sort_order'] = $this->sortOrder($sortOrder);
                }
                $descendant->forceFill($attributes)->save();
            }

            return $node->fresh();
        });
    }

    /** @param array<int,mixed> $keywordIds */
    public function assignKeywords(KeywordNode $node, array $keywordIds, ?int $primaryKeywordId = null): void
    {
        DB::transaction(function () use ($node, $keywordIds, $primaryKeywordId): void {
            $node = KeywordNode::query()->lockForUpdate()->findOrFail($node->getKey());
            $keywordIds = $this->keywordIds($keywordIds);
            if ($primaryKeywordId !== null && ! in_array($primaryKeywordId, $keywordIds, true)) {
                $this->reject('primary_keyword_not_assigned');
            }
            $found = Keyword::query()->whereIn('id', $keywordIds)->lockForUpdate()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            sort($found);
            $expected = $keywordIds;
            sort($expected);
            if ($found !== $expected) {
                $this->reject('keyword_not_found');
            }

            $membership = [];
            foreach ($keywordIds as $keywordId) {
                $membership[$keywordId] = ['is_primary' => $keywordId === $primaryKeywordId];
            }
            $node->keywords()->sync($membership);
        });
    }

    public function deleteNode(KeywordNode $node): void
    {
        DB::transaction(function () use ($node): void {
            $node = KeywordNode::query()->lockForUpdate()->findOrFail($node->getKey());
            if ($node->children()->lockForUpdate()->exists()) {
                $this->reject('delete_has_children');
            }
            if ($node->clusters()->lockForUpdate()->exists()) {
                $this->reject('delete_has_clusters');
            }
            $node->delete();
        });
    }

    private function lockedParent(mixed $parentId): ?KeywordNode
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        if (filter_var($parentId, FILTER_VALIDATE_INT) === false || (int) $parentId < 1) {
            $this->reject('parent_not_found');
        }

        return KeywordNode::query()->lockForUpdate()->findOrFail((int) $parentId);
    }

    private function assertSlugAvailable(KeywordTaxonomy $taxonomy, string $slug): void
    {
        if (KeywordNode::query()->where('taxonomy_id', $taxonomy->id)->where('slug', $slug)->lockForUpdate()->exists()) {
            $this->reject('slug_taken');
        }
    }

    private function assertMaximumDepth(int $depth): void
    {
        if ($depth > $this->maximumDepth()) {
            $this->reject('max_depth_exceeded');
        }
    }

    private function maximumDepth(): int
    {
        return max(0, (int) config('geoflow.keyword_taxonomy_max_depth', 5));
    }

    private function requiredName(mixed $value): string
    {
        if (! is_string($value) || ($name = trim($value)) === '' || mb_strlen($name) > 150) {
            $this->reject('invalid_name');
        }

        return $name;
    }

    private function slug(mixed $value): string
    {
        if (! is_string($value) || ($slug = Str::slug($value)) === '' || mb_strlen($slug) > 150) {
            $this->reject('invalid_slug');
        }

        return $slug;
    }

    private function sortOrder(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            $this->reject('invalid_sort_order');
        }

        return (int) $value;
    }

    /** @param array<int,mixed> $keywordIds
     * @return array<int,int>
     */
    private function keywordIds(array $keywordIds): array
    {
        $ids = [];
        foreach ($keywordIds as $keywordId) {
            if (filter_var($keywordId, FILTER_VALIDATE_INT) === false || (int) $keywordId < 1) {
                $this->reject('invalid_keyword_id');
            }
            $ids[] = (int) $keywordId;
        }

        return array_values(array_unique($ids));
    }

    /** @param Collection<int,KeywordNode> $subtree */
    private function longestDescendantSuffixLength(Collection $subtree, string $oldPath): int
    {
        return (int) $subtree->max(static fn (KeywordNode $node): int => strlen((string) $node->path) - strlen($oldPath));
    }

    private function reject(string $key): never
    {
        throw new DomainException('keyword_taxonomy.'.$key);
    }
}
