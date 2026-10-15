<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class GenerationKnowledgeScopeResolver
{
    public function normalize(array $data, string $defaultMode = 'legacy_manual'): array
    {
        $mode = $data['knowledge_mode'] ?? $defaultMode;
        if (! in_array($mode, ['legacy_manual', 'brand_auto', 'general', 'mixed'], true)) {
            throw ValidationException::withMessages(['knowledge_mode' => 'Unknown knowledge mode.']);
        }
        $result = ['knowledge_mode' => $mode];
        foreach (['knowledge_brand_id', 'knowledge_site_id', 'knowledge_directory_id'] as $key) {
            $value = $data[$key] ?? null;
            $result[$key] = ($value === null || $value === '') ? null : $this->positiveId($value, $key);
        }
        if (in_array($mode, ['legacy_manual', 'general'], true)
            && $result['knowledge_brand_id'] === null
            && $result['knowledge_site_id'] === null
            && $result['knowledge_directory_id'] === null) {
            return $result;
        }
        $brandId = $result['knowledge_brand_id'];
        if ($brandId === null || ! KnowledgeBrand::whereKey($brandId)->exists()) {
            throw ValidationException::withMessages(['knowledge_brand_id' => 'Select an existing knowledge brand.']);
        }
        $sites = KnowledgeSite::where('brand_id', $brandId);
        if ($result['knowledge_site_id'] === null) {
            $ids = $sites->limit(2)->pluck('id');
            if ($ids->count() !== 1) {
                throw ValidationException::withMessages(['knowledge_site_id' => 'Select a source site; the brand must have exactly one site for automatic resolution.']);
            }
            $result['knowledge_site_id'] = (int) $ids[0];
        } elseif (! $sites->whereKey($result['knowledge_site_id'])->exists()) {
            throw ValidationException::withMessages(['knowledge_site_id' => 'Source site does not belong to this brand.']);
        }
        $directoryId = $result['knowledge_directory_id'];
        $seen = [];
        while ($directoryId !== null) {
            $directory = KnowledgeDirectory::where('brand_id', $brandId)->find($directoryId);
            if (! $directory || isset($seen[$directoryId])) {
                throw ValidationException::withMessages(['knowledge_directory_id' => 'Directory ancestry must belong to the brand and contain no cycles.']);
            }
            $seen[$directoryId] = true;
            $directoryId = $directory->parent_id;
        }

        return $result;
    }

    public function resolve(array $data, string $languageCode = 'en'): KnowledgeScope
    {
        $data = $this->normalize(array_merge($data, ['knowledge_mode' => 'brand_auto']));
        try {
            return new KnowledgeScope($data['knowledge_brand_id'], $data['knowledge_site_id'], $languageCode, $data['knowledge_directory_id']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['language_code' => $exception->getMessage()]);
        }
    }

    public function eligibleQuery(KnowledgeScope $scope): Builder
    {
        $this->normalize(['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $scope->brandId, 'knowledge_site_id' => $scope->siteId, 'knowledge_directory_id' => $scope->directoryId]);
        $query = KnowledgeBase::query()->where('brand_id', $scope->brandId)->where('site_id', $scope->siteId)
            ->where('language_code', $scope->languageCode)->where('review_status', 'reviewed')
            ->where('lifecycle_status', 'active')->where('generation_eligible', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_date')->orWhereDate('effective_date', '<=', today()))
            ->where(fn (Builder $q) => $q->whereNull('review_due_at')->orWhereDate('review_due_at', '>=', today()));
        if ($scope->directoryId !== null) {
            $ids = [$scope->directoryId];
            $frontier = $ids;
            while ($frontier !== []) {
                $frontier = KnowledgeDirectory::where('brand_id', $scope->brandId)->whereIn('parent_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $ids = array_merge($ids, $frontier);
            }
            $query->whereIn('primary_directory_id', $ids);
        }

        return $query;
    }

    public function positiveId(mixed $value, string $key): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1 || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([$key => 'IDs must be positive integers.']);
        }

        return (int) $value;
    }
}
