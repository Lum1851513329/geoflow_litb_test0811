<?php

namespace App\Domain\PromptComposition;

use App\Models\ContentBrief;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Support\Collection;

final class PromptPresetSelector
{
    /** @return Collection<int, PromptPresetVersion> */
    public function eligibleVersions(ContentBrief $brief): Collection
    {
        $context = $this->context($brief);

        return PromptPreset::query()
            ->where('is_enabled', true)
            ->whereNotNull('current_version_id')
            ->with('currentVersion.preset')
            ->orderBy('id')
            ->get()
            ->map(function (PromptPreset $preset): ?PromptPresetVersion {
                $version = $preset->currentVersion;

                return $version !== null
                    && (int) $version->prompt_preset_id === (int) $preset->id
                    ? $version
                    : null;
            })
            ->filter(fn (?PromptPresetVersion $version): bool => $version !== null
                && $this->hasEligibleLifecycle($version)
                && $this->matchingScore($version, $context) >= 0)
            ->sortByDesc(fn (PromptPresetVersion $version): int => $this->matchingScore($version, $context))
            ->values();
    }

    public function eligibleVersion(ContentBrief $brief, int $versionId): ?PromptPresetVersion
    {
        if ($versionId <= 0) {
            return null;
        }

        $version = PromptPresetVersion::query()
            ->with('preset')
            ->whereKey($versionId)
            ->first();

        return $version !== null && $this->isEligible($brief, $version) ? $version : null;
    }

    public function isEligible(ContentBrief $brief, PromptPresetVersion $version): bool
    {
        $preset = $version->preset;

        return $preset !== null
            && $preset->is_enabled
            && (int) $preset->current_version_id === (int) $version->id
            && (int) $version->prompt_preset_id === (int) $preset->id
            && $this->hasEligibleLifecycle($version)
            && $this->matchingScore($version, $this->context($brief)) >= 0;
    }

    /** @return array<string, string> */
    public function context(ContentBrief $brief): array
    {
        return [
            'content_type' => (string) $brief->content_type,
            'language_code' => (string) $brief->language_code,
            'market_code' => (string) $brief->market_code,
        ];
    }

    private function hasEligibleLifecycle(PromptPresetVersion $version): bool
    {
        return in_array($version->lifecycle_status, [
            PromptPresetVersion::STATUS_CANARY,
            PromptPresetVersion::STATUS_PRODUCTION,
        ], true);
    }

    /** @param array<string, string> $context */
    private function matchingScore(PromptPresetVersion $version, array $context): int
    {
        $rules = is_array($version->matching_rules) ? $version->matching_rules : [];
        $score = 0;
        foreach ($rules as $field => $allowedValues) {
            if (! array_key_exists($field, $context)) {
                return -1;
            }
            $allowedValues = is_array($allowedValues) ? $allowedValues : [$allowedValues];
            if (! in_array('*', $allowedValues, true)
                && ! in_array($context[$field], $allowedValues, true)) {
                return -1;
            }
            $score++;
        }

        return $score;
    }
}
