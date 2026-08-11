<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentStrategy\ContentStrategyService;
use App\Domain\PromptComposition\PromptModuleResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmContentBlueprintRequest;
use App\Http\Requests\Admin\ConfirmContentStrategyRequest;
use App\Http\Requests\Admin\StoreContentBriefRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

final class ContentBriefController extends Controller
{
    public function __construct(
        private readonly ContentStrategyService $strategies,
        private readonly PromptModuleResolver $moduleResolver,
    ) {}

    public function index(Request $request): View
    {
        $briefs = ContentBrief::query()
            ->withCount(['strategyVersions', 'blueprintVersions'])
            ->when(trim((string) $request->query('q')) !== '', function ($query) use ($request): void {
                $search = '%'.trim((string) $request->query('q')).'%';
                $query->where(fn ($nested) => $nested->where('topic', 'like', $search)->orWhere('core_question', 'like', $search));
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.content-briefs.index', [
            'pageTitle' => __('admin.content_strategy.index.title'),
            'activeMenu' => 'content_strategy',
            'briefs' => $briefs,
        ]);
    }

    public function create(): View
    {
        return view('admin.content-briefs.create', [
            'pageTitle' => __('admin.content_strategy.create.title'),
            'activeMenu' => 'content_strategy',
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'keywordClusters' => KeywordCluster::query()->orderBy('name')->get(['id', 'name']),
            'keywords' => Keyword::query()->orderBy('keyword')->limit(500)->get(['id', 'keyword']),
        ]);
    }

    public function store(StoreContentBriefRequest $request): RedirectResponse
    {
        $brief = ContentBrief::query()->create([
            ...$request->briefData(),
            'status' => ContentBrief::STATUS_READY,
            'created_by_admin_id' => $this->actor($request)->id,
        ]);

        return redirect()->route('admin.content-briefs.strategy', $brief)
            ->with('message', __('admin.content_strategy.messages.brief_created'));
    }

    public function strategy(Request $request, ContentBrief $brief): View
    {
        $suggestion = $this->strategies->suggest($brief);

        return view('admin.content-briefs.strategy', [
            'pageTitle' => __('admin.content_strategy.strategy.title'),
            'activeMenu' => 'content_strategy',
            'brief' => $brief,
            'suggestion' => $suggestion,
            'latestStrategy' => $this->latestStrategy($brief),
        ]);
    }

    public function confirmStrategy(ConfirmContentStrategyRequest $request, ContentBrief $brief): RedirectResponse
    {
        $strategy = $this->strategies->confirmStrategy(
            $brief,
            $request->validated('strategy'),
            $this->actor($request),
            trim((string) $request->validated('correction_reason')),
        );

        return redirect()->route('admin.content-briefs.blueprint', [
            'brief' => $brief,
            'strategy_version_id' => $strategy->id,
        ])->with('message', __('admin.content_strategy.messages.strategy_confirmed'));
    }

    public function blueprint(Request $request, ContentBrief $brief): RedirectResponse|View
    {
        $strategy = $this->selectedStrategy($brief, $request->integer('strategy_version_id'));
        if ($strategy === null) {
            return redirect()->route('admin.content-briefs.strategy', $brief)
                ->withErrors(['strategy' => __('admin.content_strategy.validation.strategy_required')]);
        }
        $latestBlueprint = $brief->blueprintVersions()
            ->where('content_strategy_version_id', $strategy->id)
            ->latest('version')
            ->first();

        return view('admin.content-briefs.blueprint', [
            'pageTitle' => __('admin.content_strategy.blueprint.title'),
            'activeMenu' => 'content_strategy',
            'brief' => $brief,
            'strategy' => $strategy,
            'latestBlueprint' => $latestBlueprint,
            'blueprintJson' => json_encode(
                $latestBlueprint?->blueprint ?? $this->starterBlueprint($brief, $strategy),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        ]);
    }

    public function confirmBlueprint(ConfirmContentBlueprintRequest $request, ContentBrief $brief): RedirectResponse
    {
        $strategy = $this->selectedStrategy($brief, (int) ($request->validated('strategy_version_id') ?? 0));
        if ($strategy === null) {
            return back()->withInput()->withErrors([
                'strategy_version_id' => __('admin.content_strategy.validation.strategy_required'),
            ]);
        }
        $this->strategies->confirmBlueprint(
            $brief,
            $strategy,
            $request->validated('blueprint'),
            $this->actor($request),
        );

        return redirect()->route('admin.content-briefs.show', $brief)
            ->with('message', __('admin.content_strategy.messages.blueprint_confirmed'));
    }

    public function show(Request $request, ContentBrief $brief): RedirectResponse|View
    {
        $strategy = $this->latestStrategy($brief);
        if ($strategy === null) {
            return redirect()->route('admin.content-briefs.strategy', $brief);
        }
        $blueprint = $brief->blueprintVersions()
            ->where('content_strategy_version_id', $strategy->id)
            ->where('lifecycle_status', ContentBlueprintVersion::STATUS_CONFIRMED)
            ->latest('version')
            ->first();
        if ($blueprint === null) {
            return redirect()->route('admin.content-briefs.blueprint', $brief);
        }

        $presets = $this->eligiblePresetVersions($brief);
        $selectedPreset = $presets->firstWhere('id', $request->integer('preset_version_id')) ?? $presets->first();
        [$resolvedModules, $moduleResolutionError] = $this->previewModules($brief, $selectedPreset);
        $models = AiModel::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('model_type')->orWhere('model_type', '!=', 'embedding'))
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();

        return view('admin.content-briefs.show', [
            'pageTitle' => __('admin.content_strategy.generation.title'),
            'activeMenu' => 'content_strategy',
            'brief' => $brief,
            'strategy' => $strategy,
            'blueprint' => $blueprint,
            'presets' => $presets,
            'selectedPreset' => $selectedPreset,
            'resolvedModules' => $resolvedModules,
            'moduleResolutionError' => $moduleResolutionError,
            'models' => $models,
            'selectedModel' => $models->first(),
            'runs' => ContentGenerationRun::query()->where('content_brief_id', $brief->id)->latest('id')->get(),
        ]);
    }

    private function actor(Request $request): Admin
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }

    private function latestStrategy(ContentBrief $brief): ?ContentStrategyVersion
    {
        return $brief->strategyVersions()
            ->where('lifecycle_status', ContentStrategyVersion::STATUS_CONFIRMED)
            ->latest('version')
            ->first();
    }

    private function selectedStrategy(ContentBrief $brief, int $requestedId): ?ContentStrategyVersion
    {
        $query = $brief->strategyVersions()->where('lifecycle_status', ContentStrategyVersion::STATUS_CONFIRMED);
        if ($requestedId > 0) {
            return $query->whereKey($requestedId)->first();
        }

        return $query->latest('version')->first();
    }

    /** @return Collection<int, PromptPresetVersion> */
    private function eligiblePresetVersions(ContentBrief $brief): Collection
    {
        $context = $this->selectionContext($brief);

        return PromptPreset::query()
            ->where('is_enabled', true)
            ->whereNotNull('current_version_id')
            ->with('currentVersion.preset')
            ->orderBy('id')
            ->get()
            ->map(fn (PromptPreset $preset): ?PromptPresetVersion => $preset->currentVersion)
            ->filter(fn (?PromptPresetVersion $version): bool => $version !== null
                && ! in_array($version->lifecycle_status, [PromptPresetVersion::STATUS_DRAFT, PromptPresetVersion::STATUS_ARCHIVED], true))
            ->filter(fn (PromptPresetVersion $version): bool => $this->matchingScore($version, $context) >= 0)
            ->sortByDesc(fn (PromptPresetVersion $version): int => $this->matchingScore($version, $context))
            ->values();
    }

    /** @return array{Collection<int, array<string, mixed>>, string|null} */
    private function previewModules(ContentBrief $brief, ?PromptPresetVersion $preset): array
    {
        if ($preset === null) {
            return [collect(), __('admin.content_strategy.validation.preset_required')];
        }
        try {
            $resolved = $this->moduleResolver->resolve($preset, $this->selectionContext($brief));
            $versions = PromptModuleVersion::query()
                ->with('module')
                ->whereIn('id', array_map(static fn ($module): int => $module->versionId, $resolved))
                ->get()
                ->keyBy('id');

            return [collect($resolved)->map(function ($module) use ($versions): array {
                $version = $versions->get($module->versionId);

                return [
                    'module_key' => $module->moduleKey,
                    'display_name' => $version?->module?->display_name ?? $module->moduleKey,
                    'version' => $version?->version,
                    'version_id' => $module->versionId,
                    'module_type' => $module->moduleType,
                    'required' => $module->required,
                ];
            }), null];
        } catch (Throwable $exception) {
            report($exception);

            return [collect(), $exception->getMessage()];
        }
    }

    /** @return array<string, string> */
    private function selectionContext(ContentBrief $brief): array
    {
        return [
            'content_type' => (string) $brief->content_type,
            'language_code' => (string) $brief->language_code,
            'market_code' => (string) $brief->market_code,
        ];
    }

    /** @param array<string, string> $context */
    private function matchingScore(PromptPresetVersion $version, array $context): int
    {
        $rules = is_array($version->matching_rules) ? $version->matching_rules : [];
        $score = 0;
        foreach ($rules as $field => $allowed) {
            if (! array_key_exists($field, $context)) {
                return -1;
            }
            $allowed = is_array($allowed) ? $allowed : [$allowed];
            if (! in_array('*', $allowed, true) && ! in_array($context[$field], $allowed, true)) {
                return -1;
            }
            $score++;
        }

        return $score;
    }

    /** @return array<string, mixed> */
    private function starterBlueprint(ContentBrief $brief, ContentStrategyVersion $strategy): array
    {
        $questions = array_values(array_filter((array) ($strategy->strategy['questions_to_answer'] ?? [$brief->core_question])));

        return [
            'working_title' => $brief->topic,
            'target_length' => 1200,
            'sections' => [[
                'key' => 'main-answer',
                'heading' => $brief->topic,
                'purpose' => $brief->business_goal,
                'questions_to_answer' => $questions === [] ? [$brief->core_question] : $questions,
                'minimum_words' => 600,
                'cta_allowed' => $brief->cta_strength !== 'none',
                'required_fact_ids' => [],
            ]],
        ];
    }
}
