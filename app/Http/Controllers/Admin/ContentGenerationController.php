<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Domain\ContentGeneration\ContentGenerationRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdoptContentGenerationRequest;
use App\Http\Requests\Admin\RequestContentGenerationRequest;
use App\Jobs\GenerateStrategyContent;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class ContentGenerationController extends Controller
{
    public function __construct(
        private readonly GenerationAdoptionService $adoptions,
        private readonly ContentGenerationRequestService $generationRequests,
    ) {}

    public function store(RequestContentGenerationRequest $request, ContentBrief $brief): RedirectResponse
    {
        $data = $request->validated();
        $reservation = $this->generationRequests->reserve($brief, $data, $this->actor($request));
        $run = $reservation['run'];
        if ($reservation['created']) {
            GenerateStrategyContent::dispatch((int) $run->id);
        }

        return redirect()->route('admin.content-generations.show', $run)
            ->with('message', __('admin.content_strategy.messages.generation_queued'));
    }

    public function show(Request $request, ContentGenerationRun $run): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $run);
        $run->load([
            'contentBrief', 'strategyVersion', 'blueprintVersion', 'presetVersion.preset', 'aiModel', 'article',
            'modules.promptModuleVersion.module', 'evidence', 'qualityReports', 'steps',
        ]);
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
