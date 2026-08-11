<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentStrategy\ContentStrategyService;
use App\Domain\PromptComposition\PromptModuleResolver;
use App\Domain\PromptComposition\PromptPresetSelector;
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
use App\Models\KnowledgeChunk;
use App\Models\PromptModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

final class ContentBriefController extends Controller
{
    public function __construct(
        private readonly ContentStrategyService $strategies,
        private readonly PromptModuleResolver $moduleResolver,
        private readonly PromptPresetSelector $presetSelector,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', ContentBrief::class);
        $briefs = ContentBrief::query()
            ->withCount(['strategyVersions', 'blueprintVersions'])
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('created_by_admin_id', $actor->id))
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

    public function create(Request $request): View
    {
        Gate::forUser($this->actor($request))->authorize('create', ContentBrief::class);

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
        Gate::forUser($this->actor($request))->authorize('view', $brief);
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
        Gate::forUser($this->actor($request))->authorize('view', $brief);
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
        Gate::forUser($this->actor($request))->authorize('view', $brief);
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

        $presets = $this->presetSelector->eligibleVersions($brief);
        $selectedPreset = $presets->firstWhere('id', $request->integer('preset_version_id')) ?? $presets->first();
        [$resolvedModules, $moduleResolutionError] = $this->previewModules($brief, $selectedPreset);
        $models = AiModel::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('model_type')->orWhere('model_type', '!=', 'embedding'))
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();
        $validated = $request->validate(['run_q' => ['nullable', 'string', 'max:150']]);
        $runSearch = trim((string) ($validated['run_q'] ?? ''));
        $runs = ContentGenerationRun::query()
            ->where('content_brief_id', $brief->id)
            ->when($runSearch !== '', function ($query) use ($runSearch): void {
                $like = '%'.mb_strtolower($runSearch).'%';
                $query->where(function ($nested) use ($runSearch, $like): void {
                    if (ctype_digit($runSearch)) {
                        $nested->orWhereKey((int) $runSearch);
                    }
                    $nested->orWhereRaw('LOWER(status) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(error_message, \'\')) LIKE ?', [$like])
                        ->orWhereHas('qualityReports', fn ($quality) => $quality->whereRaw('LOWER(CAST(issues AS TEXT)) LIKE ?', [$like]));
                });
            })
            ->latest('id')
            ->paginate(20, ['*'], 'runs_page')
            ->withQueryString();
        $evidenceChunks = KnowledgeChunk::query()
            ->with('knowledgeBase:id,name,review_status')
            ->whereHas('knowledgeBase', fn ($query) => $query->where('review_status', 'reviewed'))
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'knowledge_base_id', 'chunk_title', 'section_path']);

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
            'runs' => $runs,
            'runSearch' => $runSearch,
            'evidenceChunks' => $evidenceChunks,
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

    /** @return array{Collection<int, array<string, mixed>>, string|null} */
    private function previewModules(ContentBrief $brief, ?PromptPresetVersion $preset): array
    {
        if ($preset === null) {
            return [collect(), __('admin.content_strategy.validation.preset_required')];
        }
        try {
            $resolved = $this->moduleResolver->resolve($preset, $this->presetSelector->context($brief));
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

            return [collect(), __('admin.content_strategy.validation.modules_unavailable')];
        }
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
