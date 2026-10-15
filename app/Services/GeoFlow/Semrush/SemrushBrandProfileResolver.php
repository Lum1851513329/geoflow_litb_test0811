<?php

namespace App\Services\GeoFlow\Semrush;

use App\Models\Category;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use InvalidArgumentException;

/**
 * Builds the immutable brand context used by Semrush preview and conversion.
 * The resolver deliberately reads only configured database records and global
 * evidence settings; it contains no brand-specific category or preset names.
 */
final class SemrushBrandProfileResolver
{
    /** @return array<string,mixed> */
    public function resolve(int $brandId, ?int $siteId = null): array
    {
        $brand = KnowledgeBrand::query()->find($brandId);
        if ($brand === null) {
            throw new InvalidArgumentException('Unknown knowledge brand.');
        }

        $sites = KnowledgeSite::query()->where('brand_id', $brand->id)->orderBy('id')->get();
        if ($siteId !== null) {
            $site = $sites->firstWhere('id', $siteId);
            if ($site === null) {
                throw new InvalidArgumentException('Selected knowledge site does not belong to the brand.');
            }
        } elseif ($sites->count() === 1) {
            $site = $sites->first();
        } else {
            throw new InvalidArgumentException('Select a knowledge site when the brand has multiple sites.');
        }

        $directories = KnowledgeDirectory::query()
            ->where('brand_id', $brand->id)
            ->orderBy('path')
            ->get(['id', 'brand_id', 'parent_id', 'key', 'path', 'name'])
            ->map(static fn (KnowledgeDirectory $directory): array => [
                'id' => (int) $directory->id,
                'parent_id' => $directory->parent_id !== null ? (int) $directory->parent_id : null,
                'key' => (string) $directory->key,
                'path' => (string) $directory->path,
                'name' => (string) $directory->name,
            ])->values()->all();

        $presets = PromptPreset::query()
            ->where('is_enabled', true)
            ->whereHas('currentVersion', fn ($query) => $query
                ->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)
                ->whereNotNull('blueprint_template'))
            ->with('currentVersion')
            ->orderBy('preset_key')
            ->get()
            ->map(static function (PromptPreset $preset): array {
                $version = $preset->currentVersion;
                $defaults = is_array($version?->default_brief_fields) ? $version->default_brief_fields : [];
                $rules = is_array($version?->matching_rules) ? $version->matching_rules : [];
                $contentTypes = array_values(array_unique(array_merge(
                    self::stringList($defaults['content_type'] ?? null),
                    self::stringList($rules['content_type'] ?? null),
                )));

                return [
                    'id' => (int) $preset->id,
                    'key' => (string) $preset->preset_key,
                    'display_name' => (string) $preset->display_name,
                    'content_types' => $contentTypes,
                ];
            })->values()->all();

        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug'])
            ->map(static fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
            ])->values()->all();

        $aliases = array_values(array_unique(array_filter([
            mb_strtolower((string) $brand->key),
            mb_strtolower((string) $brand->name),
            ...preg_split('/[^a-z0-9]+/i', (string) $brand->name, -1, PREG_SPLIT_NO_EMPTY),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '')));

        $officialDomains = array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            array_merge([(string) $site->canonical_host], (array) config('external_evidence.official_domains', [])),
        ))));
        $generalDomains = array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            (array) config('external_evidence.general_domains', []),
        ))));

        $profile = [
            'brand' => ['id' => (int) $brand->id, 'key' => (string) $brand->key, 'name' => (string) $brand->name],
            'site' => ['id' => (int) $site->id, 'key' => (string) $site->key, 'canonical_host' => (string) $site->canonical_host, 'role' => (string) $site->role],
            'directories' => $directories,
            'categories' => $categories,
            'presets' => $presets,
            'official_domains' => $officialDomains,
            'general_domains' => $generalDomains,
            'aliases' => $aliases,
            'profile_version' => 1,
        ];
        $profile['snapshot_hash'] = hash('sha256', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $profile;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value)));
    }
}
