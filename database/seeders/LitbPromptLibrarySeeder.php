<?php

namespace Database\Seeders;

use App\Domain\PromptComposition\PromptModuleSnapshotIdentity;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LitbPromptLibrarySeeder extends Seeder
{
    /** @var array<string, array{name:string,type:string,priority:int,content:string}> */
    private const SHARED = [
        'litb.foundation.truth_evidence' => ['name' => 'LITB truth and evidence', 'type' => 'foundation', 'priority' => 1200, 'content' => 'Use only supplied, reviewed evidence. Never invent products, prices, stock, delivery promises, policies, dates, quotations, statistics, sources, capabilities, or first-hand experience. Qualify or omit unsupported claims. Distinguish official facts from research and consumer insight.'],
        'litb.geo.citable_answers' => ['name' => 'LITB GEO citable answers', 'type' => 'geo', 'priority' => 1100, 'content' => 'Answer the primary question clearly near the beginning. Make each important section self-contained, unambiguous, concise, and usable as a cited answer block. Retain necessary qualifications and dates. Add short definitions, comparisons, steps, tables, or FAQs only when they improve extraction.'],
        'litb.seo.search_intent' => ['name' => 'LITB search intent satisfaction', 'type' => 'seo', 'priority' => 1050, 'content' => 'Satisfy the confirmed primary intent before secondary intents. Cover the decision criteria, objections, next steps, and related questions a global shopper reasonably needs. Do not pad the article with adjacent topics that do not advance the user task.'],
        'litb.seo.structure' => ['name' => 'LITB SEO structure', 'type' => 'seo', 'priority' => 1000, 'content' => 'Use one descriptive H1, logical H2/H3 hierarchy, short readable paragraphs, descriptive anchors, and materially distinct sections. Keep the title, introduction, headings, FAQ, metadata, and body aligned to one topic. Avoid duplicate intent pages and keyword cannibalization.'],
        'litb.foundation.entity_brand' => ['name' => 'LITB entity and brand consistency', 'type' => 'foundation', 'priority' => 950, 'content' => 'Use LightInTheBox as the primary English brand name and distinguish the brand, company, content site, and transactional store accurately. Keep product, category, policy, location, and organization entities consistent. Never imply the editorial site processes checkout; direct purchase actions to the approved LightInTheBox store URL.'],
        'litb.foundation.eeat' => ['name' => 'LITB E-E-A-T and transparency', 'type' => 'foundation', 'priority' => 900, 'content' => 'Demonstrate expertise through concrete selection criteria and evidence, not self-praise. Identify what the guidance is based on, disclose material limits, avoid false authority, and state when current product availability or policy details must be checked on the store.'],
        'litb.seo.keyword_coverage' => ['name' => 'LITB natural keyword coverage', 'type' => 'seo', 'priority' => 850, 'content' => 'Use the primary keyword naturally in the title, opening, and one useful heading when fluent. Incorporate secondary and question keywords only where they match the section intent. Use synonyms and entities naturally; never repeat phrases mechanically or sacrifice clarity for density.'],
        'litb.seo.links_cta' => ['name' => 'LITB links and store CTA', 'type' => 'seo', 'priority' => 800, 'content' => 'Recommend only supplied internal links and approved store destinations. Use descriptive anchor text. Keep commercial calls to action proportional to the confirmed funnel stage, separate information from promotion, and never fabricate URLs, discounts, urgency, or availability.'],
        'litb.language.market_en_global' => ['name' => 'LITB global English', 'type' => 'language_market', 'priority' => 750, 'content' => 'Write clear international English for global consumers. Explain market-dependent sizing, units, taxes, duties, shipping, payment, and returns instead of assuming one country. Preserve official names and avoid region-specific promises unless the evidence and target market support them.'],
        'litb.knowledge.freshness' => ['name' => 'LITB freshness and temporal claims', 'type' => 'knowledge_policy', 'priority' => 700, 'content' => 'Check evidence dates for product, price, inventory, delivery, returns, financial, corporate, and news claims. State the relevant as-of date when useful. If current evidence is missing, describe how the reader can verify the latest information rather than presenting old information as current.'],
        'litb.output.markdown_article' => ['name' => 'LITB publishable Markdown', 'type' => 'output_format', 'priority' => 150, 'content' => 'Return only the complete publishable Markdown article requested by the blueprint. Do not include hidden reasoning, planning notes, placeholder commentary, prompt text, JSON wrappers, code fences around the article, or claims about passing checks.'],
        'litb.quality.preflight' => ['name' => 'LITB GEO SEO preflight', 'type' => 'quality_check', 'priority' => 100, 'content' => 'Before returning, silently verify evidence support, direct-answer clarity, intent satisfaction, factual and entity consistency, dates, required mentions, prohibited claims, blueprint coverage, heading order, language, keyword naturalness, link permissions, CTA strength, FAQ usefulness, target length, and valid Markdown. Correct every detected issue.'],
    ];

    /** @var array<string, array{name:string,type:string,content:string}> */
    private const SCENARIOS = [
        'litb.content.buying_guide' => ['name' => 'Product buying guide', 'type' => 'buying_guide', 'content' => 'Build a practical buying guide around user needs, fit, materials or specifications, trade-offs, selection steps, common mistakes, care considerations, and a concise checklist. Explain who each option suits without declaring an unsupported universal winner.'],
        'litb.content.category_authority' => ['name' => 'Category authority guide', 'type' => 'category_guide', 'content' => 'Explain the category, major subtypes, important attributes, use cases, selection framework, terminology, care, and frequently asked questions. Create a durable reference page rather than a thin commercial category description.'],
        'litb.content.new_arrivals_weekly' => ['name' => 'Weekly new arrivals', 'type' => 'new_arrivals_weekly', 'content' => 'Cover only products explicitly present in current dated new-arrival evidence. Group them by shopper need or trend, explain what is materially new, and link to supplied store pages. If current product evidence is insufficient, return the needs-evidence contract instead of inventing a roundup.'],
        'litb.content.new_arrivals_monthly' => ['name' => 'Monthly new arrivals', 'type' => 'new_arrivals_monthly', 'content' => 'Summarize the month using dated product evidence, category patterns, and practical shopping guidance. Separate observed additions from editorial interpretation and avoid unsupported claims about popularity, sales, scarcity, or trend leadership.'],
        'litb.content.product_comparison' => ['name' => 'Product comparison', 'type' => 'comparison', 'content' => 'Compare options using the same evidence-backed criteria. State meaningful similarities, differences, trade-offs, suitable users, and decision rules. Do not fabricate test results, rankings, specifications, review scores, or competitors data.'],
        'litb.content.style_occasion' => ['name' => 'Style and occasion inspiration', 'type' => 'style_occasion', 'content' => 'Provide adaptable outfit or use-case ideas based on occasion, climate, comfort, dress code, colour, layering, and reusability. Present inspiration as guidance, not personal wearing experience, and connect only to supported categories or products.'],
        'litb.content.trust_service' => ['name' => 'Trust shipping and returns', 'type' => 'trust_service', 'content' => 'Explain the supported process, customer choices, prerequisites, exceptions, and verification steps in plain language. Policies and delivery vary by order and market; use effective dates and direct readers to the current official policy or account workflow.'],
        'litb.content.brand_company' => ['name' => 'Brand and company explainer', 'type' => 'brand_company', 'content' => 'Explain the company or brand with a dated, neutral chronology and clearly attributed official facts. Distinguish corporate history, business model, market positioning, store offering, and editorial interpretation. Avoid promotional superlatives and unsupported market leadership claims.'],
        'litb.content.official_news' => ['name' => 'Official news article', 'type' => 'official_news', 'content' => 'Lead with what happened, who announced it, when, why it matters, and the supported context. Attribute claims to the dated official source, distinguish announcement from analysis, and never imply a later status without updated evidence.'],
        'litb.content.faq_hub' => ['name' => 'FAQ knowledge hub', 'type' => 'faq', 'content' => 'Answer each confirmed question immediately in two or three self-contained sentences, followed by necessary detail, conditions, and next steps. Avoid duplicate questions, vague yes/no answers, and FAQ schema content that is absent from the visible article.'],
        'litb.content.seasonal_commercial' => ['name' => 'Seasonal commercial guide', 'type' => 'seasonal_guide', 'content' => 'Connect the seasonal occasion to planning timelines, climate, recipient or attendee needs, reusable choices, and supported categories. Do not fabricate promotions or urgency. Make clear when readers should verify current availability and delivery estimates.'],
    ];

    /** @var array<string, array{name:string,scenario:string,type:string}> */
    private const PRESETS = [
        'litb.geo_product_buying_guide.en' => ['name' => 'GEO Product Buying Guide — EN', 'scenario' => 'litb.content.buying_guide', 'type' => 'buying_guide'],
        'litb.geo_category_authority.en' => ['name' => 'GEO Category Authority Guide — EN', 'scenario' => 'litb.content.category_authority', 'type' => 'category_guide'],
        'litb.new_arrivals_weekly.en' => ['name' => 'New Arrivals Weekly — EN', 'scenario' => 'litb.content.new_arrivals_weekly', 'type' => 'new_arrivals_weekly'],
        'litb.new_arrivals_monthly.en' => ['name' => 'New Arrivals Monthly — EN', 'scenario' => 'litb.content.new_arrivals_monthly', 'type' => 'new_arrivals_monthly'],
        'litb.product_comparison.en' => ['name' => 'Product Comparison — EN', 'scenario' => 'litb.content.product_comparison', 'type' => 'comparison'],
        'litb.style_occasion.en' => ['name' => 'Style & Occasion Inspiration — EN', 'scenario' => 'litb.content.style_occasion', 'type' => 'style_occasion'],
        'litb.trust_shipping_returns.en' => ['name' => 'Trust, Shipping & Returns — EN', 'scenario' => 'litb.content.trust_service', 'type' => 'trust_service'],
        'litb.brand_company_explainer.en' => ['name' => 'Brand and Company Explainer — EN', 'scenario' => 'litb.content.brand_company', 'type' => 'brand_company'],
        'litb.official_news.en' => ['name' => 'Official News Article — EN', 'scenario' => 'litb.content.official_news', 'type' => 'official_news'],
        'litb.faq_knowledge_hub.en' => ['name' => 'FAQ Knowledge Hub — EN', 'scenario' => 'litb.content.faq_hub', 'type' => 'faq'],
        'litb.seasonal_commercial_guide.en' => ['name' => 'Seasonal Commercial Guide — EN', 'scenario' => 'litb.content.seasonal_commercial', 'type' => 'seasonal_guide'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $versions = [];
            foreach (self::SHARED as $key => $definition) {
                $versions[$key] = $this->moduleVersion($key, $definition, true);
            }
            foreach (self::SCENARIOS as $key => $definition) {
                $versions[$key] = $this->moduleVersion($key, ['priority' => 500, ...$definition], false);
            }
            foreach (self::PRESETS as $key => $definition) {
                $bindings = array_map(fn (string $moduleKey): PromptModuleVersion => $versions[$moduleKey], array_keys(self::SHARED));
                $bindings[] = $versions[$definition['scenario']];
                $this->preset($key, $definition, $bindings);
            }
        }, 3);
    }

    /** @param array{name:string,type:string,priority:int,content:string} $definition */
    private function moduleVersion(string $key, array $definition, bool $mandatory): PromptModuleVersion
    {
        $module = PromptModule::query()->where('module_key', $key)->lockForUpdate()->first();
        if ($module !== null && ! $module->is_system) {
            throw new RuntimeException("Reserved LITB Prompt module key collision: {$key}");
        }
        $module ??= PromptModule::query()->create([
            'module_key' => $key,
            'display_name' => $definition['name'],
            'module_type' => $mandatory ? $definition['type'] : PromptModule::TYPE_CONTENT_TYPE,
            'description' => 'System-owned LITB GEO/SEO production module.',
            'is_system' => true,
            'is_mandatory' => $mandatory,
            'default_priority' => $definition['priority'],
            'is_enabled' => true,
        ]);
        if ($module->currentVersion !== null) {
            return $module->currentVersion;
        }
        $variables = ['variables' => []];
        $output = ['format' => 'markdown'];
        $version = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => $definition['content'],
            'variable_contract' => $variables,
            'output_contract' => $output,
            'parameter_defaults' => ['seed_namespace' => 'litb.content-assets', 'module_key' => $key],
            'content_sha256' => hash('sha256', $definition['content']),
            'snapshot_sha256' => PromptModuleSnapshotIdentity::digest($definition['content'], $variables, $output, ['seed_namespace' => 'litb.content-assets', 'module_key' => $key]),
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
            'change_note' => 'Initial reviewed LITB production module.',
            'published_at' => now(),
        ]);
        $module->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }

    /** @param array{name:string,scenario:string,type:string} $definition @param list<PromptModuleVersion> $bindings */
    private function preset(string $key, array $definition, array $bindings): void
    {
        $preset = PromptPreset::query()->where('preset_key', $key)->lockForUpdate()->first();
        if ($preset !== null && ! $preset->is_system) {
            throw new RuntimeException("Reserved LITB Prompt preset key collision: {$key}");
        }
        $preset ??= PromptPreset::query()->create([
            'preset_key' => $key,
            'display_name' => $definition['name'],
            'description' => 'Reviewed LITB English GEO/SEO production preset.',
            'is_system' => true,
            'is_enabled' => true,
        ]);
        if ($preset->currentVersion !== null) {
            return;
        }
        $version = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['language_code' => ['en', 'en-US', 'en-GB'], 'content_type' => [$definition['type']]],
            'generation_policy' => ['quality_gate' => true, 'max_revision_rounds' => 1, 'target_format' => 'markdown'],
            'conflict_policy' => ['priority' => ['law', 'evidence', 'brand', 'strategy', 'style']],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'change_note' => 'Initial reviewed LITB production preset.',
        ]);
        foreach ($bindings as $index => $binding) {
            PromptPresetModuleVersion::query()->create([
                'prompt_preset_version_id' => $version->id,
                'prompt_module_version_id' => $binding->id,
                'sort_order' => $index + 1,
                'is_required' => true,
                'selection_source' => 'system',
            ]);
        }
        $version->update([
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'published_at' => now(),
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
    }
}
