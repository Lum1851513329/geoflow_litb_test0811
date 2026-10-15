<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentGenerationRun;
use Illuminate\View\View;

/**
 * The "intermediate storage" list for the token-saving WorkBuddy generation
 * path: every ContentGenerationRun that's had its prompt/evidence/knowledge
 * compiled (see ContentGenerationCoordinator's early-exit branch) but never
 * called the paid AI model, because its task is generation_engine=workbuddy.
 * Each row here links to the existing /content-generations/{run} page, which
 * already shows the compiled prompt and — once WorkBuddy's output is
 * submitted — the same adopt/publish form the normal AI pipeline uses.
 */
final class WorkBuddyTaskController extends Controller
{
    public function index(): View
    {
        $runs = ContentGenerationRun::query()
            ->whereIn('status', [
                ContentGenerationRun::STATUS_AWAITING_EXTERNAL_OUTPUT,
                ContentGenerationRun::STATUS_READY_FOR_REVIEW,
                ContentGenerationRun::STATUS_ADOPTED,
            ])
            ->whereHas('contentBrief.task', fn ($query) => $query->where('generation_engine', 'workbuddy'))
            ->with(['contentBrief:id,topic,retrieval_keyword,category_id,task_id', 'contentBrief.category:id,name', 'article:id,title'])
            ->orderByRaw("CASE status WHEN '".ContentGenerationRun::STATUS_AWAITING_EXTERNAL_OUTPUT."' THEN 0 WHEN '".ContentGenerationRun::STATUS_READY_FOR_REVIEW."' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(50);

        return view('admin.workbuddy-tasks.index', [
            'pageTitle' => 'WorkBuddy 任务',
            'activeMenu' => 'content_strategy',
            'runs' => $runs,
        ]);
    }
}
