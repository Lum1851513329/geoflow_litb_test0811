<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;

use function Laravel\Ai\agent;

/**
 * Picks which of a preset's optional `section_pool` sections belong in a
 * given article, so articles under the same preset stop sharing an
 * identical section-by-section skeleton (the 2026-09-21 heading_style_hint
 * fix in ContentBriefQuickCreateService only varied heading wording, not
 * which sections exist). A preset that has no `section_pool` on its
 * blueprint_template is entirely unaffected — no AI call, no behavior
 * change, same `sections` list as before.
 */
final class BlueprintSectionPlanner
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * @param  array<string, mixed>  $template
     * @return list<array<string, mixed>>
     */
    public function planSections(?AiModel $model, string $topic, string $coreQuestion, array $template): array
    {
        $pool = (array) ($template['section_pool'] ?? []);
        if ($pool === []) {
            return (array) ($template['sections'] ?? []);
        }

        $core = array_values((array) ($template['core_sections'] ?? []));
        $slots = max(1, (int) ($template['pool_slots'] ?? 3));

        $selectedKeys = $model !== null ? $this->askModel($model, $topic, $coreQuestion, $pool, $slots) : null;
        $selectedKeys ??= $this->deterministicFallback($topic, $pool, $slots);

        $poolByKey = collect($pool)->keyBy(fn (array $section): string => (string) $section['key']);
        $middle = array_values(array_filter(array_map(
            fn (string $key): ?array => $poolByKey->get($key),
            $selectedKeys,
        )));

        $sections = [];
        if (isset($core[0])) {
            $sections[] = $core[0];
        }
        array_push($sections, ...$middle);
        if (isset($core[1])) {
            $sections[] = $core[1];
        }

        return $sections;
    }

    /**
     * @param  list<array<string, mixed>>  $pool
     * @return list<string>|null
     */
    private function askModel(AiModel $model, string $topic, string $coreQuestion, array $pool, int $slots): ?array
    {
        $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $key = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($baseUrl === '' || $key === '') {
            return null;
        }
        $driver = OpenAiRuntimeProvider::resolveChatDriver($baseUrl, (string) ($model->model_id ?? ''));
        $provider = OpenAiRuntimeProvider::registerProvider('blueprint_section', $driver, $baseUrl, $key);

        $poolKeys = [];
        $candidates = '';
        foreach ($pool as $section) {
            $sectionKey = (string) ($section['key'] ?? '');
            if ($sectionKey === '') {
                continue;
            }
            $poolKeys[] = $sectionKey;
            $heading = (string) ($section['heading'] ?? '');
            $purpose = (string) ($section['purpose'] ?? '');
            $candidates .= "- {$sectionKey}: {$heading} — {$purpose}\n";
        }
        if ($poolKeys === []) {
            return null;
        }

        $prompt = "Pick the {$slots} candidate sections most relevant to this specific article topic, ordered by how they should appear in the article.\n\n"
            ."Topic: {$topic}\nCore question: {$coreQuestion}\n\nCandidate sections:\n{$candidates}\n"
            .'Return strict JSON only in this shape: {"sections":["section-key-1","section-key-2"]}. '
            .'Use only the exact keys listed above, pick at most '.$slots.', do not invent new keys, no commentary.';

        try {
            $response = agent('You are an expert SEO/GEO content structure strategist.')
                ->prompt($prompt, [], $provider, (string) ($model->model_id ?? ''));
        } catch (\Throwable) {
            return null;
        }

        $text = trim((string) ($response->text ?? ''));
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $text) ?? $text);
        $payload = json_decode($text, true);
        if (! is_array($payload) || ! is_array($payload['sections'] ?? null)) {
            return null;
        }

        $selected = [];
        foreach ($payload['sections'] as $sectionKey) {
            $sectionKey = (string) $sectionKey;
            if ($sectionKey !== '' && in_array($sectionKey, $poolKeys, true) && ! in_array($sectionKey, $selected, true)) {
                $selected[] = $sectionKey;
            }
        }

        return $selected !== [] ? array_slice($selected, 0, $slots) : null;
    }

    /**
     * Topic-seeded, deterministic, idempotent per topic — used whenever the
     * AI call is unavailable or returns something unusable, so section
     * composition still varies per topic without depending on the AI call
     * succeeding.
     *
     * @param  list<array<string, mixed>>  $pool
     * @return list<string>
     */
    private function deterministicFallback(string $topic, array $pool, int $slots): array
    {
        $scored = [];
        foreach ($pool as $section) {
            $sectionKey = (string) ($section['key'] ?? '');
            if ($sectionKey === '') {
                continue;
            }
            $scored[$sectionKey] = crc32($topic.'|'.$sectionKey);
        }
        asort($scored);

        return array_slice(array_keys($scored), 0, $slots);
    }
}
