<?php

namespace App\Services\GeoFlow\LitbAssets;

use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\Keyword;
use App\Models\PromptPreset;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LitbQuarterlyBriefImporter
{
    public function __construct(private readonly LitbQuarterlyBriefCatalog $catalog) {}

    public function import(?int $adminId = null): LitbAssetManifest
    {
        $manifest = new LitbAssetManifest();
        foreach ($this->catalog->definitions() as $definition) {
            DB::transaction(function () use ($definition, $adminId, $manifest): void {
                $brief = ContentBrief::query()->where('asset_key', $definition['asset_key'])->lockForUpdate()->first();
                $digest = hash('sha256', json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if ($brief !== null) {
                    if (($brief->asset_provenance['definition_sha256'] ?? null) === $digest) {
                        $manifest->skipped($definition['asset_key']);
                    } else {
                        $manifest->conflict($definition['asset_key'], 'operator_or_catalog_change_requires_review');
                    }

                    return;
                }
                if (! PromptPreset::query()->where('preset_key', $definition['prompt_preset_key'])->where('is_enabled', true)->exists()) {
                    throw new RuntimeException('litb_briefs.prompt_preset_missing:'.$definition['prompt_preset_key']);
                }
                $keyword = Keyword::query()->where('normalized_keyword', mb_convert_case($definition['primary_keyword'], MB_CASE_FOLD, 'UTF-8'))
                    ->where('language_code', 'en')->orderByDesc('id')->first();
                $provenance = [
                    'namespace' => 'litb.content-assets',
                    'definition_sha256' => $digest,
                    'pillar' => $definition['pillar'],
                    'primary_keyword' => $definition['primary_keyword'],
                    'secondary_keywords' => $definition['secondary_keywords'],
                    'question_keywords' => $definition['question_keywords'],
                    'slug' => $definition['slug'],
                    'seo_title' => $definition['seo_title'],
                    'meta_description' => $definition['meta_description'],
                    'geo_direct_answer' => $definition['geo_direct_answer'],
                    'faq_questions' => $definition['faq_questions'],
                    'evidence_group_keys' => $definition['evidence_group_keys'],
                    'internal_link_targets' => $definition['internal_link_targets'],
                    'store_landing_page_type' => $definition['store_landing_page_type'],
                    'schema_types' => $definition['schema_types'],
                    'refresh_days' => $definition['refresh_days'],
                    'risk_flags' => $definition['risk_flags'],
                    'prompt_preset_key' => $definition['prompt_preset_key'],
                ];
                $brief = ContentBrief::query()->create([
                    'asset_key' => $definition['asset_key'],
                    'asset_provenance' => $provenance,
                    'topic' => $definition['topic'],
                    'market_code' => $definition['market_code'],
                    'language_code' => $definition['language_code'],
                    'audience' => $definition['audience'],
                    'core_question' => $definition['core_question'],
                    'business_goal' => $definition['business_goal'],
                    'content_type' => $definition['content_type'],
                    'planned_publish_at' => $definition['planned_publish_at'],
                    'primary_keyword_id' => $keyword?->id,
                    'required_mentions' => $definition['required_mentions'],
                    'prohibited_information' => $definition['prohibited_information'],
                    'campaign_window' => ['quarter' => '2026-Q3', 'refresh_days' => $definition['refresh_days']],
                    'reference_urls' => [],
                    'cta_strength' => $definition['cta_strength'],
                    'status' => ContentBrief::STATUS_STRATEGY_READY,
                    'created_by_admin_id' => $adminId,
                ]);
                $strategy = ContentStrategyVersion::forceCreate([
                    'content_brief_id' => $brief->id,
                    'version' => 1,
                    'strategy' => [
                        'audience' => $definition['audience'],
                        'core_question' => $definition['core_question'],
                        'primary_intent' => $this->intent($definition['pillar']),
                        'secondary_intents' => ['informational'],
                        'decision_stage' => $definition['funnel_stage'],
                        'content_type' => $definition['content_type'],
                        'content_goal' => $definition['business_goal'],
                        'cta_strength' => $definition['cta_strength'],
                        'target_keyword_cluster' => null,
                        'primary_keyword' => $definition['primary_keyword'],
                        'secondary_keywords' => $definition['secondary_keywords'],
                        'questions_to_answer' => [$definition['core_question'], ...$definition['faq_questions']],
                        'geo_direct_answer' => $definition['geo_direct_answer'],
                        'risks' => $definition['risk_flags'],
                        'evidence_gaps' => in_array('needs_current_product_evidence', $definition['risk_flags'], true) ? ['Current dated product evidence required before generation.'] : [],
                        'prompt_preset_key' => $definition['prompt_preset_key'],
                    ],
                    'suggestion_snapshot' => ['source' => 'litb-quarterly-reviewed-catalog', 'definition_sha256' => $digest],
                    'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
                    'change_note' => 'Reviewed LITB quarterly content catalog.',
                    'created_by_admin_id' => $adminId,
                    'confirmed_by_admin_id' => $adminId,
                    'confirmed_at' => now(),
                ]);
                ContentBlueprintVersion::forceCreate([
                    'content_brief_id' => $brief->id,
                    'content_strategy_version_id' => $strategy->id,
                    'version' => 1,
                    'blueprint' => [
                        'working_title' => $definition['working_title'],
                        'target_length' => 1800,
                        'sections' => $definition['sections'],
                        'required_entities' => ['LightInTheBox'],
                        'faq_questions' => $definition['faq_questions'],
                        'seo_title' => $definition['seo_title'],
                        'meta_description' => $definition['meta_description'],
                        'schema_types' => $definition['schema_types'],
                    ],
                    'validation_report' => [],
                    'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
                    'change_note' => 'Reviewed LITB quarterly content catalog.',
                    'created_by_admin_id' => $adminId,
                    'confirmed_by_admin_id' => $adminId,
                    'confirmed_at' => now(),
                ]);
                $manifest->created($definition['asset_key']);
            }, 3);
        }

        return $manifest;
    }

    private function intent(string $pillar): string
    {
        return in_array($pillar, ['product_comparison', 'category_authority', 'product_education'], true)
            ? 'commercial'
            : 'informational';
    }
}
