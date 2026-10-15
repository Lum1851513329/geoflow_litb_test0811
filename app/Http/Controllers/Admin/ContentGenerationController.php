<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentGeneration\ContentGenerationRequestService;
use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Domain\ContentGeneration\WorkBuddyPromptRenderer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdoptContentGenerationRequest;
use App\Http\Requests\Admin\RequestContentGenerationRequest;
use App\Http\Requests\Admin\SubmitWorkBuddyOutputRequest;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

final class ContentGenerationController extends Controller
{
    public function __construct(
        private readonly GenerationAdoptionService $adoptions,
        private readonly ContentGenerationRequestService $generationRequests,
        private readonly WorkBuddyPromptRenderer $workBuddyPromptRenderer,
    ) {}

    public function store(RequestContentGenerationRequest $request, ContentBrief $brief): RedirectResponse
    {
        $data = $request->validated();
        $reservation = $this->generationRequests->reserve($brief, $data, $this->actor($request));
        $run = $reservation['run'];

        return redirect()->route('admin.content-generations.show', $run)
            ->with('message', __('admin.content_strategy.messages.generation_queued'));
    }

    public function show(Request $request, ContentGenerationRun $run): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $run);
        $run->load([
            'contentBrief.category', 'contentBrief.primaryKeyword', 'strategyVersion', 'blueprintVersion', 'presetVersion.preset', 'aiModel', 'article',
            'modules.promptModuleVersion.module', 'evidence', 'qualityReports', 'steps',
            'evidencePreparation',
        ]);
        $isWorkBuddyRun = (string) ($run->contentBrief?->task?->generation_engine ?? '') === 'workbuddy';

        return view('admin.content-generations.show', [
            'pageTitle' => __('admin.content_strategy.run.title', ['id' => $run->id]),
            'activeMenu' => 'content_strategy',
            'run' => $run,
            'canViewCompiledPrompt' => $actor->canManageProtectedWorkflows(),
            'authors' => Author::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'isWorkBuddyRun' => $isWorkBuddyRun,
            'workBuddyPrompt' => $isWorkBuddyRun ? $this->workBuddyPromptRenderer->render($run) : null,
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

    /**
     * WorkBuddy (or whoever operates it) pastes the article body it wrote —
     * from the prompt/evidence/knowledge already compiled onto this run — and
     * this hands the run to the existing ready-for-review/adopt flow above
     * without ever calling the paid AI model. Only valid from
     * STATUS_AWAITING_EXTERNAL_OUTPUT (see ContentGenerationCoordinator's
     * early-exit branch), guarded by the same lock/lease fields the normal
     * pipeline uses so this can't race a stale worker retry.
     */
    public function workbuddySubmit(SubmitWorkBuddyOutputRequest $request, ContentGenerationRun $run): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $run): void {
                $locked = ContentGenerationRun::query()->lockForUpdate()->findOrFail($run->getKey());
                if ($locked->status !== ContentGenerationRun::STATUS_AWAITING_EXTERNAL_OUTPUT) {
                    throw new RuntimeException('This run is not waiting on external output.');
                }
                $locked->forceFill([
                    'raw_output' => (string) $request->validated('raw_output'),
                    'status' => ContentGenerationRun::STATUS_READY_FOR_REVIEW,
                    'completed_at' => now(),
                    'lease_expires_at' => null,
                ])->save();
            });
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['raw_output' => $exception->getMessage()]);
        }

        return redirect()->route('admin.content-generations.show', $run)
            ->with('message', '已提交，请核对后采纳发布。');
    }

    private function actor(Request $request): Admin
    {
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }
}
