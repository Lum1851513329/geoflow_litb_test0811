<?php

namespace App\Console\Commands;

use App\Domain\PromptComposition\PromptPresetService;
use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Services\GeoFlow\LitbAssets\LitbQuarterlyBriefCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * 上线运行顺序（必须严格遵守，且两条命令要放在同一个维护窗口里连续执行）：
 *
 *   1. `geoflow:content-strategy:seed-system-admin`（Task 10）——本命令依赖
 *      `geoflow-system` 管理员账号存在，否则直接返回 FAILURE。
 *   2. `geoflow:content-strategy:seed-title-crafting-module`（Task 5）
 *   3. `geoflow:content-strategy:seed-preset-blueprint-templates`（本命令）
 *
 * 第 2 步会把 title_crafting 立刻变成一个全局强制模块，从那一刻起，任何尚未绑定
 * 它的预设都会在 `MandatoryPromptModuleGuard::assertComplete()` 处失败——也就是说
 * `reserve()` 会对**既有的**（本次改动之前就在跑的）内容生成流水线整体报错。第 3 步
 * 正是重新满足该约束的动作。因此两步之间的时间差就是既有流水线的故障窗口：顺序
 * 颠倒、或第 3 步部分失败而没人看汇总，都会把生产上原本正常的生成流程打断。
 *
 * 本命令对每个预设单独兜底，失败不会中断整批；结束时会打印成功/失败数量与逐个
 * 失败原因，并在存在任何失败时返回 FAILURE——请务必检查退出码与汇总输出，把失败
 * 的预设补齐后重跑（命令本身幂等，已完成的预设会被跳过）。
 */
final class SeedPresetBlueprintTemplatesCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:seed-preset-blueprint-templates';

    protected $description = 'Bind title_crafting into every non-generic preset and attach blueprint_template for the 9 LITB pillar presets';

    private const EXCLUDED_PRESET_KEYS = [
        'legacy_geo_trust_en',
        'legacy_geo_ranking_en',
        'legacy_geo_trust_zh',
        'legacy_geo_ranking_zh',
    ];

    /** @var list<string> */
    private const PILLARS = [
        'category_authority', 'product_comparison', 'style_occasion', 'new_arrivals',
        'product_education', 'customer_education', 'brand_company', 'official_news', 'faq',
    ];

    /** @var array<string, string> generic, topic-agnostic phrasing for the 4 non-lead sections */
    private const GENERIC_SECTION_QUESTIONS = [
        'Key options and criteria' => 'What options and criteria matter most for this decision?',
        'How to check fit, compatibility, or terms' => 'How can a shopper check fit, compatibility, or terms before buying?',
        'Common mistakes and verification checklist' => 'What common mistakes should shoppers avoid?',
        'Frequently asked questions' => 'What do shoppers commonly ask about this topic?',
    ];

    public function __construct(private readonly LitbQuarterlyBriefCatalog $catalog)
    {
        parent::__construct();
    }

    public function handle(PromptPresetService $presets): int
    {
        $actor = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $actor) {
            $this->error('Run "geoflow:content-strategy:seed-system-admin" first (Task 10) — no geoflow-system admin found.');

            return self::FAILURE;
        }

        $titleCraftingModule = PromptModule::query()->where('module_key', 'title_crafting')->first();
        if (! $titleCraftingModule || ! $titleCraftingModule->current_version_id) {
            $this->error('title_crafting module not found — run "geoflow:content-strategy:seed-title-crafting-module" first (Task 5).');

            return self::FAILURE;
        }
        $titleCraftingVersionId = (int) $titleCraftingModule->current_version_id;

        $pillarSections = $this->pillarSectionsByPresetKey();

        $presetsToBackfill = PromptPreset::query()
            ->whereNotIn('preset_key', self::EXCLUDED_PRESET_KEYS)
            ->whereNotNull('current_version_id')
            ->get();

        // 每个预设单独兜底：`PromptPresetService::publish()` 内部会调用
        // `MandatoryPromptModuleGuard::assertComplete()`，只要该预设缺任何一个全局
        // 强制模块（不只是 title_crafting）就会抛 InvalidArgumentException。没有
        // per-preset try/catch 时，第 N 个预设失败会让整条命令直接中断，留下 1..N-1
        // 已发布新版本、N 之后原封不动的半吊子状态，而且没有任何汇总说明。
        // 这里改成"记账并继续"，一个坏预设不再挡住其余预设。
        $succeeded = 0;
        $failures = [];
        foreach ($presetsToBackfill as $preset) {
            try {
                $this->backfillPreset($presets, $preset, $titleCraftingVersionId, $pillarSections[$preset->preset_key] ?? null, $actor);
                $succeeded++;
            } catch (Throwable $exception) {
                $failures[(string) $preset->preset_key] = $exception->getMessage();
                $this->error("Failed to backfill {$preset->preset_key}: {$exception->getMessage()}");
            }
        }

        $this->info("Backfill summary: {$succeeded} preset(s) succeeded or already up to date, ".count($failures).' failed.');
        if ($failures === []) {
            return self::SUCCESS;
        }

        $this->error('The following presets were left untouched and must be fixed before the quality-gated pipeline can use them:');
        foreach ($failures as $presetKey => $message) {
            $this->error("  - {$presetKey}: {$message}");
        }

        return self::FAILURE;
    }

    /** @return array<string, array{blueprint_template: array<string, mixed>, default_brief_fields: array<string, mixed>}> */
    private function pillarSectionsByPresetKey(): array
    {
        $byPresetKey = [];
        foreach (self::PILLARS as $pillar) {
            $definition = $this->catalog->pillar($pillar);
            $presetKey = (string) $definition['preset'];

            $byPresetKey[$presetKey] = [
                'blueprint_template' => [
                    'target_length' => 1800,
                    'sections' => $this->templateSections($pillar),
                    'required_entities' => [],
                    'show_references_section' => in_array($pillar, [
                        'official_news', 'brand_company', 'product_comparison', 'customer_education', 'new_arrivals',
                    ], true),
                ],
                'default_brief_fields' => [
                    'market_code' => 'GLOBAL',
                    'language_code' => 'en',
                    'audience' => 'Global English-speaking consumers researching products, purchases, or official LightInTheBox information.',
                    'business_goal' => (string) $definition['business_goal'],
                    'content_type' => (string) $definition['content_type'],
                    'cta_strength' => (string) $definition['cta'],
                ],
            ];
        }

        return $byPresetKey;
    }

    /**
     * Builds the same 5-heading skeleton `LitbQuarterlyBriefCatalog::sections()` uses,
     * but with topic-agnostic purpose/question text — unlike that private per-brief
     * method, this template is shared across every future topic under the preset, so
     * it cannot bake in one specific keyword/question. The lead section's question is
     * left as the literal placeholder "{{core_question}}", which
     * `ContentBriefQuickCreateService` (Task 7) substitutes with the brief's actual
     * `core_question` when it instantiates a per-brief `ContentBlueprintVersion` from
     * this template — if that substitution is ever skipped, `PromptCompiler::render()`
     * already throws on any residual `{{`/`}}` in the compiled prompt, so this fails
     * loudly rather than silently shipping a placeholder to the model.
     *
     * @return list<array<string, mixed>>
     */
    private function templateSections(string $pillar): array
    {
        $headings = [
            'Direct answer and decision summary',
            'Key options and criteria',
            'How to check fit, compatibility, or terms',
            'Common mistakes and verification checklist',
            'Frequently asked questions',
        ];

        $sections = [];
        foreach ($headings as $index => $heading) {
            $sections[] = [
                'key' => Str::slug($heading),
                'heading' => $heading,
                'purpose' => $index === 0
                    ? "Directly answer the brief's core question before any other content."
                    : "Give evidence-backed guidance for {$heading}.",
                'questions_to_answer' => [$index === 0 ? '{{core_question}}' : self::GENERIC_SECTION_QUESTIONS[$heading]],
                'minimum_words' => $index === 4 ? 220 : 280,
                'cta_allowed' => $pillar !== 'official_news',
                'required_fact_ids' => [],
            ];
        }

        return $sections;
    }

    /** @param array{blueprint_template: array<string, mixed>, default_brief_fields: array<string, mixed>}|null $pillarData */
    private function backfillPreset(PromptPresetService $presets, PromptPreset $preset, int $titleCraftingVersionId, ?array $pillarData, Admin $actor): void
    {
        $currentVersion = $preset->currentVersion;
        if ($currentVersion === null) {
            $this->warn("Preset {$preset->preset_key} has no current version, skipping.");

            return;
        }

        $alreadyBound = PromptPresetModuleVersion::query()
            ->where('prompt_preset_version_id', $currentVersion->id)
            ->where('prompt_module_version_id', $titleCraftingVersionId)
            ->exists();
        if ($alreadyBound && ($pillarData === null || $currentVersion->blueprint_template !== null)) {
            $this->info("Preset {$preset->preset_key} already up to date, skipping.");

            return;
        }

        $existingBindings = PromptPresetModuleVersion::query()
            ->where('prompt_preset_version_id', $currentVersion->id)
            ->with('promptModuleVersion.module')
            ->orderBy('sort_order')
            ->get();
        $nextSortOrder = ((int) $existingBindings->max('sort_order')) + 1;

        $bindings = $existingBindings->map(function (PromptPresetModuleVersion $binding): array {
            $module = $binding->promptModuleVersion?->module;
            $moduleVersionId = $module !== null
                && $module->is_system
                && $module->is_mandatory
                && $module->is_enabled
                && $module->current_version_id !== null
                    ? (int) $module->current_version_id
                    : (int) $binding->prompt_module_version_id;

            return [
                'prompt_module_version_id' => $moduleVersionId,
                'sort_order' => (int) $binding->sort_order,
                'is_required' => (bool) $binding->is_required,
                'selection_source' => $binding->selection_source,
                'configuration' => $binding->configuration,
            ];
        })->all();
        if (! $alreadyBound) {
            $bindings[] = [
                'prompt_module_version_id' => $titleCraftingVersionId,
                'sort_order' => $nextSortOrder,
                'is_required' => true,
            ];
        }

        $data = [
            'matching_rules' => $currentVersion->matching_rules,
            'generation_policy' => $currentVersion->generation_policy,
            'conflict_policy' => $currentVersion->conflict_policy,
            'change_note' => 'Bind title_crafting mandatory module'.($pillarData !== null ? ' and attach blueprint_template.' : '.'),
        ];
        if ($pillarData !== null) {
            $data['blueprint_template'] = $pillarData['blueprint_template'];
            $data['default_brief_fields'] = $pillarData['default_brief_fields'];
        }

        $newVersion = $presets->createVersion($preset, $data, $bindings, $actor);
        $presets->publish($newVersion, $actor, 'Automated backfill: title_crafting binding'.($pillarData !== null ? ' + blueprint_template.' : '.'));
        $this->info("Backfilled {$preset->preset_key} -> version {$newVersion->version}.");
    }
}
