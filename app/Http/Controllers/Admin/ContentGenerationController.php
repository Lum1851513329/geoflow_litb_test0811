<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdoptContentGenerationRequest;
use App\Http\Requests\Admin\RequestContentGenerationRequest;
use App\Jobs\GenerateStrategyContent;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class ContentGenerationController extends Controller
{
    public function __construct(private readonly GenerationAdoptionService $adoptions) {}

    public function store(RequestContentGenerationRequest $request, ContentBrief $brief): RedirectResponse
    {
        $data = $request->validated();
        $run = DB::transaction(function () use ($brief, $data, $request): ContentGenerationRun {
            $lockedBrief = ContentBrief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $strategy = $lockedBrief->strategyVersions()
                ->whereKey($data['strategy_version_id'])
                ->where('lifecycle_status', ContentStrategyVersion::STATUS_CONFIRMED)
                ->first();
            if ($strategy === null) {
                throw new DomainException(__('admin.content_strategy.validation.strategy_required'));
            }
            $blueprint = $lockedBrief->blueprintVersions()
                ->whereKey($data['blueprint_version_id'])
                ->where('content_strategy_version_id', $strategy->id)
                ->where('lifecycle_status', ContentBlueprintVersion::STATUS_CONFIRMED)
                ->first();
            if ($blueprint === null) {
                throw new DomainException(__('admin.content_strategy.validation.blueprint_required'));
            }
            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            if ($idempotencyKey === '') {
                $idempotencyKey = 'content-generation-'.Str::uuid();
            }
            $existing = ContentGenerationRun::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ((int) $existing->content_brief_id !== (int) $lockedBrief->id) {
                    throw new DomainException(__('admin.content_strategy.validation.idempotency_conflict'));
                }

                return $existing;
            }

            return ContentGenerationRun::forceCreate([
                'content_brief_id' => $lockedBrief->id,
                'strategy_version_id' => $strategy->id,
                'blueprint_version_id' => $blueprint->id,
                'preset_version_id' => $data['preset_version_id'],
                'ai_model_id' => $data['ai_model_id'],
                'compiled_prompt' => null,
                'status' => ContentGenerationRun::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'created_by_admin_id' => $this->actor($request)->id,
            ]);
        });

        GenerateStrategyContent::dispatch((int) $run->id);

        return redirect()->route('admin.content-generations.show', $run)
            ->with('message', __('admin.content_strategy.messages.generation_queued'));
    }

    public function show(Request $request, ContentGenerationRun $run): View
    {
        $run->load([
            'contentBrief', 'strategyVersion', 'blueprintVersion', 'presetVersion.preset', 'aiModel', 'article',
            'modules.promptModuleVersion.module', 'evidence', 'qualityReports', 'steps',
        ]);
        $actor = $this->actor($request);

        return view('admin.content-generations.show', [
            'pageTitle' => __('admin.content_strategy.run.title', ['id' => $run->id]),
            'activeMenu' => 'content_strategy',
            'run' => $run,
            'canViewCompiledPrompt' => $actor->canManageProtectedWorkflows(),
            'authors' => Author::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function adopt(AdoptContentGenerationRequest $request, ContentGenerationRun $run): RedirectResponse
    {
        try {
            $article = $this->adoptions->adopt($run, $request->validated(), $this->actor($request));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['run' => $exception->getMessage()]);
        }

        return redirect()->route('admin.articles.edit', $article->id)
            ->with('message', __('admin.content_strategy.messages.generation_adopted'));
    }

    private function actor(Request $request): Admin
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }
}
