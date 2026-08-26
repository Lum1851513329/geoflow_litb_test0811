<?php

namespace App\Services\GeoFlow;

use App\Domain\ContentGeneration\ContentGenerationRequestService;
use App\Domain\ContentGeneration\ReferenceEvidenceFetcher;
use App\Domain\ContentStrategy\ContentBriefQuickCreateService;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\PromptPreset;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeScope;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

/**
 * Worker 任务执行器：将队列任务落地为文章记录（占位实现，先打通 worker/队列链路）。
 */
class WorkerExecutionService
{
    /**
     * 单个知识库最多取多少条已审核 chunk 作为证据，与
     * `LitbQuarterlyGenerationCoordinator::MAX_EVIDENCE_CHUNKS_PER_GROUP` 保持一致。
     */
    private const MAX_EVIDENCE_CHUNKS_PER_KNOWLEDGE_BASE = 6;

    /**
     * 复用正文提示词和模型调用服务，确保任务生成与单篇生成规则一致。
     */
    public function __construct(
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService,
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly ArticleRiskScanner $articleRiskScanner,
        private readonly ArticleWorkflowTransitionService $articleWorkflowTransitionService,
        private readonly ArticleContentPromptRenderer $articleContentPromptRenderer,
        private readonly ArticleContentGenerationService $articleContentGenerationService,
        private readonly ContentBriefQuickCreateService $briefQuickCreateService,
        private readonly ContentGenerationRequestService $generationRequests,
        private readonly ReferenceEvidenceFetcher $referenceEvidenceFetcher,
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(int $taskId, ?int $taskRunId = null): array
    {
        /** @var Task|null $task */
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new RuntimeException('任务不存在');
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new RuntimeException('任务未激活');
        }

        $publishResult = $this->publishDueDraftArticle($task);
        if ($publishResult !== null) {
            $this->distributionOrchestrator->enqueueForArticle((int) $publishResult['article_id']);

            return $publishResult;
        }

        $generationBlockReason = $this->getGenerationBlockReason($task);
        if ($generationBlockReason !== null) {
            return [
                'article_id' => null,
                'title' => '',
                'message' => $generationBlockReason,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'action' => 'noop',
                    'reason' => $generationBlockReason,
                ],
            ];
        }

        $titleRow = $this->pickTitle($task);
        $category = $this->pickCategory($task);

        if ((string) ($task->generation_engine ?? 'legacy') === 'quality_gated') {
            return $this->generateQualityGatedDraft($task, $titleRow, $category);
        }

        $author = $this->pickAuthor($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $knowledgeAudit = $task->knowledge_mode === 'brand_auto' ? $this->brandKnowledgeAudit($task, $titleRow, $taskRunId) : null;
        $knowledgeContext = $knowledgeAudit === null
            ? $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword)
            : "Use only claims supported by these scoped sources; missing coverage is not permission to invent facts.\n".implode("\n\n", array_map(static fn ($item) => '['.$item['citation_id'].'] '.$item['content'], $knowledgeAudit['evidence']));
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $prompt?->content, $knowledgeContext);
        $generation = $this->generateContentWithModelSelection($task, $contentPrompt);
        $aiModel = $generation['model'];
        $generatedContent = $generation['content'];
        $imageResult = $this->insertTaskImagesIntoContent($task, $generatedContent);
        $content = $imageResult['content'];
        $selectedImages = $imageResult['images'];
        $excerpt = $this->buildExcerpt($content);
        $workflow = [
            'status' => 'draft',
            'review_status' => (int) ($task->need_review ?? 1) === 1 ? 'pending' : 'approved',
            'published_at' => null,
        ];

        $articleId = DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $content, $excerpt, $workflow, $selectedImages): int {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'created_count', 'draft_limit', 'article_limit', 'publish_interval', 'next_publish_at']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }
            $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
            if ($generationBlockReason !== null) {
                throw new RuntimeException($generationBlockReason);
            }

            $pendingWorkflow = ArticleWorkflow::normalizeState('draft', 'pending');
            $article = Article::query()->create([
                'title' => (string) $titleRow->title,
                'slug' => ArticleWorkflow::generateUniqueSlug((string) $titleRow->title),
                'excerpt' => $excerpt,
                'content' => $content,
                'category_id' => $category?->id,
                'author_id' => $author?->id,
                'task_id' => (int) $task->id,
                'source_title_id' => (int) $titleRow->id,
                'original_keyword' => $keyword,
                'keywords' => $keyword,
                'meta_description' => mb_substr($excerpt, 0, 120),
                'status' => $pendingWorkflow['status'],
                'review_status' => $pendingWorkflow['review_status'],
                'is_ai_generated' => 1,
                'published_at' => $pendingWorkflow['published_at'],
                'view_count' => 0,
            ]);

            $this->articleRiskScanner->record($article, 'worker_generation');

            if ($workflow['review_status'] === 'approved') {
                try {
                    $this->articleWorkflowTransitionService->transition(
                        $article,
                        $workflow,
                        'worker_generation',
                        null,
                        null,
                        false,
                        $pendingWorkflow,
                    );
                } catch (ArticleRiskGateException) {
                    // 风险扫描和待审状态随当前生成事务一并保留。
                }
            }
            if ($selectedImages !== []) {
                foreach ($selectedImages as $position => $image) {
                    ArticleImage::query()->create([
                        'article_id' => (int) $article->id,
                        'image_id' => (int) $image->id,
                        'position' => $position,
                    ]);
                    Image::query()->whereKey((int) $image->id)->update([
                        'used_count' => DB::raw('COALESCE(used_count,0)+1'),
                        'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                    ]);
                }
            }

            // 保持与旧逻辑一致：每次任务执行会消耗标题并累加任务计数。
            Title::query()->whereKey($titleRow->id)->increment('used_count');
            Title::query()->whereKey($titleRow->id)->increment('usage_count');

            $taskUpdate = [
                'created_count' => DB::raw('COALESCE(created_count,0)+1'),
                'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
                'updated_at' => now(),
            ];
            if ($freshTask->next_publish_at === null || ! $freshTask->next_publish_at->greaterThan(now())) {
                $taskUpdate['next_publish_at'] = now()->addSeconds($this->normalizePublishInterval($freshTask));
            }
            Task::query()->whereKey($task->id)->update($taskUpdate);

            return (int) $article->id;
        });

        return [
            'article_id' => $articleId,
            'title' => (string) $titleRow->title,
            'message' => '草稿生成成功',
            'meta' => [
                'task_id' => (int) $task->id,
                'action' => 'generate_draft',
                'title_id' => (int) $titleRow->id,
                'author_id' => $author?->id,
                'category_id' => $category?->id,
                'knowledge_length' => mb_strlen($knowledgeContext, 'UTF-8'),
                'knowledge_retrieval_snapshot' => $knowledgeAudit,
                'image_count' => count($selectedImages),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'used_model_id' => (int) $aiModel->id,
                'used_model_name' => (string) $aiModel->name,
                'model_attempts' => $generation['attempts'],
            ],
        ];
    }

    private function brandKnowledgeAudit(Task $task, Title $title, ?int $taskRunId): array
    {
        if ($taskRunId === null) {
            throw new RuntimeException('Brand automatic generation requires a TaskRun audit. Enqueue this task instead of calling executeTask directly.');
        }

        return DB::transaction(function () use ($task, $title, $taskRunId): array {
            $run = TaskRun::whereKey($taskRunId)->where('task_id', $task->id)->where('status', 'running')->lockForUpdate()->first();
            if ($run === null) {
                throw new RuntimeException('The exact running TaskRun audit is required for brand generation.');
            }
            $meta = (array) $run->meta;
            if (isset($meta['knowledge_retrieval_snapshot'])) {
                if (($meta['knowledge_title_id'] ?? null) !== $title->id) {
                    throw new RuntimeException('TaskRun evidence belongs to a different title; enqueue a new run.');
                }

                return $meta['knowledge_retrieval_snapshot'];
            }
            $scope = app(GenerationKnowledgeScopeResolver::class)->resolve($task->getAttributes());
            $ids = KnowledgeBase::where('brand_id', $scope->brandId)->where('site_id', $scope->siteId)->orderBy('id')->lockForUpdate()->pluck('id');
            KnowledgeChunk::whereIn('knowledge_base_id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            $selection = app(BrandKnowledgeEvidenceSelector::class)->select($scope, app(BrandKnowledgeQueryBuilder::class)->forTitle($title));
            if ($selection['chunk_ids'] === []) {
                throw new RuntimeException('该品牌内证据不足: no eligible evidence; generation was not called.');
            }
            $run->update(['meta' => array_merge($meta, ['knowledge_retrieval_snapshot' => $selection, 'knowledge_title_id' => $title->id])]);

            return $selection;
        });
    }

    /**
     * 质量门禁任务分支：创建 ContentBrief 并预约一次异步 ContentGenerationRun，
     * 不再像旧逻辑那样同步调用模型并直接创建 Article。
     *
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    private function generateQualityGatedDraft(Task $task, Title $titleRow, ?Category $category): array
    {
        $presetId = (int) ($task->prompt_preset_id ?? 0);
        if ($presetId <= 0) {
            throw new RuntimeException('任务未配置质量门禁内容模板');
        }
        $preset = PromptPreset::query()->find($presetId);
        if (! $preset) {
            throw new RuntimeException('任务配置的内容模板不存在');
        }

        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $systemAdmin) {
            throw new RuntimeException('系统管理员账户不存在，请先运行 geoflow:content-strategy:seed-system-admin');
        }

        $presetVersion = $preset->currentVersion;
        if ($presetVersion === null) {
            throw new RuntimeException('任务配置的内容模板没有可用版本');
        }

        $brandAuto = $task->knowledge_mode === 'brand_auto';
        $referenceUrls = $brandAuto ? [] : (array) ($titleRow->reference_urls ?? []);
        $fetchedReferences = $referenceUrls !== [] ? $this->referenceEvidenceFetcher->fetch($referenceUrls) : [];
        $knowledgeChunkIds = $brandAuto ? [] : $this->resolveReviewedKnowledgeChunkIds($task);

        // Brief/Strategy/Blueprint 与 run 预约必须在同一个事务内提交：
        // `ContentBriefQuickCreateService::create()` 自带事务并会立刻提交三张表，
        // 而 `reserve()` 可能因模型停用、预设不合格、证据被拒等原因抛错。若不共用
        // 事务，失败时这三行会被永久遗留成孤儿数据，而 Title.used_count 又只在
        // reserve() 成功后才自增，于是下一个调度周期会挑中同一个标题、再造一批
        // 孤儿数据，在配置错误的任务上每分钟重复一次。
        [$brief, $reservation] = DB::transaction(function () use ($task, $titleRow, $category, $referenceUrls, $fetchedReferences, $knowledgeChunkIds, $preset, $presetVersion, $systemAdmin): array {
            $brief = $this->briefQuickCreateService->create(array_merge($task->only(['knowledge_mode', 'knowledge_brand_id', 'knowledge_site_id', 'knowledge_directory_id']), [
                'topic' => (string) $titleRow->title,
                'core_question' => $titleRow->core_question,
                'retrieval_keyword' => $titleRow->keyword,
                'category_id' => $category?->id,
                'reference_urls' => $referenceUrls,
                'task_id' => (int) $task->id,
            ]), $preset, $systemAdmin);

            $strategyVersion = $brief->strategyVersions()->latest('version')->firstOrFail();
            $blueprintVersion = $brief->blueprintVersions()->latest('version')->firstOrFail();

            $reservation = $this->generationRequests->reserve($brief, [
                'strategy_version_id' => $strategyVersion->id,
                'blueprint_version_id' => $blueprintVersion->id,
                'preset_version_id' => $presetVersion->id,
                'ai_model_id' => (int) $task->ai_model_id,
                'evidence' => $this->mapFetchedReferencesToEvidence($fetchedReferences),
                // 任务的知识库证据必须和手工流程一样喂给 run，否则质量门禁任务
                // 会在"只准使用已审核证据"的模块约束下生成无据可依的正文。
                'knowledge_chunk_ids' => $knowledgeChunkIds,
                'idempotency_key' => $this->buildReservationIdempotencyKey($task, $titleRow),
            ], $systemAdmin);

            // 计数自增随预约一起提交：整体回滚时标题不会被平白消耗，提交后
            // used_count 已经变化，重试的调度会挑到下一个标题而不是重复本次预约。
            Title::query()->whereKey($titleRow->id)->increment('used_count');
            Title::query()->whereKey($titleRow->id)->increment('usage_count');

            return [$brief, $reservation];
        }, 3);

        return [
            'article_id' => null,
            'title' => (string) $titleRow->title,
            'message' => '质量门禁生成任务已提交',
            'meta' => [
                'task_id' => (int) $task->id,
                'action' => 'generate_quality_gated_draft',
                'title_id' => (int) $titleRow->id,
                'content_brief_id' => (int) $brief->id,
                'content_generation_run_id' => (int) $reservation['run']->id,
            ],
        ];
    }

    /**
     * 解析任务绑定知识库下的已审核 KnowledgeChunk id，作为 run 的证据来源。
     *
     * 与 `LitbQuarterlyGenerationCoordinator::reviewedEvidenceFor()` 保持同一套约定：
     * 只取 `knowledge_bases.review_status = 'reviewed'` 的知识库、按 id 升序、每个
     * 知识库最多取 self::MAX_EVIDENCE_CHUNKS_PER_KNOWLEDGE_BASE 条，最终去重。
     * 区别在于那里"某个证据组一条都取不到"要整体判定为 needs_evidence，而任务侧
     * 的知识库属于可选增强（任务也可能压根没绑定知识库），因此这里取不到就返回
     * 空数组交给质量门禁判断，而不是在这里阻断整次生成。
     *
     * @return list<int>
     */
    private function resolveReviewedKnowledgeChunkIds(Task $task): array
    {
        $knowledgeBaseIds = $this->resolveTaskKnowledgeBaseIds($task);
        if ($knowledgeBaseIds === []) {
            return [];
        }

        $chunkIds = [];
        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            $ids = KnowledgeChunk::query()
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->whereHas('knowledgeBase', function ($query): void {
                    $query->where('review_status', 'reviewed');
                })
                ->orderBy('id')
                ->limit(self::MAX_EVIDENCE_CHUNKS_PER_KNOWLEDGE_BASE)
                ->pluck('id');
            foreach ($ids as $id) {
                $chunkIds[(int) $id] = true;
            }
        }

        return array_keys($chunkIds);
    }

    /**
     * 预约用的幂等键：同一任务 + 同一标题 + 同一次使用，重复调用会命中
     * `ContentGenerationRequestService::reserve()` 已有的重放逻辑，返回既有 run
     * 而不是再建一条，从而关闭"队列任务重试导致重复 run"的窗口。
     *
     * 必须带上 used_count：`reserve()` 的重放校验会比对 fingerprint（含
     * content_brief_id），而循环任务（is_loop=1）会在标题库轮完一圈后再次挑到
     * 同一个标题并创建一个**新的** Brief。若键里只有 task+title，那次合法的二次
     * 生成会被判成 idempotency_configuration_conflict 而直接失败。used_count 只在
     * 预约成功的同一个事务里自增，因此重试窗口内它保持不变（键稳定、正确去重），
     * 而下一轮循环时它已经变化（键不同、允许重新生成）。
     */
    private function buildReservationIdempotencyKey(Task $task, Title $titleRow): string
    {
        return 'task:'.(int) $task->id
            .':title:'.(int) $titleRow->id
            .':use:'.(int) ($titleRow->used_count ?? 0);
    }

    /**
     * `ReferenceEvidenceFetcher::fetch()` 返回 `{url,status,content}` 形状，
     * 但 `GenerationEvidenceSnapshotter::snapshot()` 的显式 evidence 需要
     * `{fact_id,title?,content,source_url?}` 形状（见该类 docblock及其对
     * `$item['fact_id']`/`$item['content']` 的读取）。两者不兼容，必须在
     * 这里做一次形状转换，不能把抓取结果原样透传给 reserve()。
     *
     * 只保留成功抓取到内容的引用；抓取失败（status=failed，content=null）
     * 的条目会被丢弃，因为 snapshotter 要求 content 非空。
     *
     * @param  list<array{url:string,status:'fetched'|'failed',content:?string}>  $fetchedReferences
     * @return list<array{fact_id:string,title:string,content:string,source_url:string}>
     */
    private function mapFetchedReferencesToEvidence(array $fetchedReferences): array
    {
        $evidence = [];
        foreach ($fetchedReferences as $reference) {
            if (($reference['status'] ?? null) !== 'fetched') {
                continue;
            }
            $content = trim((string) ($reference['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $url = (string) ($reference['url'] ?? '');
            $evidence[] = [
                'fact_id' => 'reference-url:'.sha1($url),
                'title' => $url,
                'content' => $content,
                'source_url' => $url,
            ];
        }

        return $evidence;
    }

    /**
     * 发布一个已审核草稿。生成与发布解耦后，Worker 每次执行优先释放到期草稿。
     *
     * @return array{article_id:int, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(Task $task): ?array
    {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        return DB::transaction(function () use ($task): ?array {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'publish_interval', 'next_publish_at', 'publish_scope']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }

            if ($freshTask->next_publish_at !== null && $freshTask->next_publish_at->greaterThan(now())) {
                return null;
            }

            /** @var Article|null $article */
            $article = Article::query()
                ->where('task_id', (int) $freshTask->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id', 'title', 'review_status']);
            if (! $article) {
                return null;
            }

            $publishScope = (string) ($freshTask->publish_scope ?? 'local_and_distribution');
            $targetStatus = $publishScope === 'distribution_only' ? 'private' : 'published';
            $reviewStatus = (string) ($article->review_status ?: 'approved');
            $workflow = ArticleWorkflow::normalizeState($targetStatus, $reviewStatus);
            $fallbackWorkflow = ArticleWorkflow::normalizeState('draft', 'pending');

            try {
                $this->articleWorkflowTransitionService->transition(
                    $article,
                    $workflow,
                    'worker_publish',
                    null,
                    null,
                    $reviewStatus !== 'auto_approved',
                    $fallbackWorkflow,
                );
            } catch (ArticleRiskGateException) {
                return null;
            }

            $publishInterval = $this->normalizePublishInterval($freshTask);
            Task::query()->whereKey((int) $freshTask->id)->update([
                'published_count' => DB::raw('COALESCE(published_count,0)+1'),
                'next_publish_at' => now()->addSeconds($publishInterval),
                'updated_at' => now(),
            ]);

            return [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'message' => '草稿发布成功',
                'meta' => [
                    'task_id' => (int) $freshTask->id,
                    'action' => 'publish_draft',
                    'publish_interval' => $publishInterval,
                ],
            ];
        });
    }

    /**
     * 判断是否允许继续生成草稿。
     */
    private function getGenerationBlockReason(Task $task, bool $lock = false): ?string
    {
        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return '已达到文章总数上限';
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftCount = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->count();
        // PostgreSQL 不允许在 count(*) 聚合查询上追加 FOR UPDATE。
        // 这里的并发保护由任务行锁和 task_runs 的单任务串行队列保证，草稿计数不需要再单独加锁。

        if ((string) ($task->generation_engine ?? 'legacy') === 'quality_gated') {
            // 质量门禁任务在 run 被采纳前不会创建 Article，需要把进行中的 run 也计入草稿池，
            // 否则会在 run 处于 pending/generating/待审时放任任务超量生成。
            //
            // 但只统计"确实还在流转"的状态：needs_evidence / model_quality_failed /
            // manual_review_required 按设计是等人处理的终态，没有任何自动释放路径，
            // 一旦攒够 draft_limit 条就会把任务永久锁死。人在 /content-generations/{run}
            // 里处理掉（手工采纳等）会让它转入真正的终态，槽位自然释放。
            $draftCount += ContentGenerationRun::query()
                ->whereIn('content_brief_id', ContentBrief::query()->where('task_id', (int) $task->id)->select('id'))
                ->whereNotIn('status', [
                    ContentGenerationRun::STATUS_ADOPTED,
                    ContentGenerationRun::STATUS_FAILED,
                    ContentGenerationRun::STATUS_NEEDS_EVIDENCE,
                    ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED,
                    ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED,
                ])
                ->count();

            // 上面把 needs_evidence / model_quality_failed / manual_review_required
            // 从草稿池计数里排除，是为了不让它们永久占位（见上方注释）。但这三个
            // 终态没有自动释放路径，加上 created_count 只在成功采纳后才自增
            // （见 GenerationAdoptionService::recordTaskGeneration()），两者叠加会让
            // 一个持续被质量门禁拒绝的任务彻底失去上限：草稿池不再计它、
            // article_limit 也永远不会触发，调度器每个 tick 都会真实调用一次模型。
            // 因此这里单独统计"卡在人工处理终态"的 run 数量，一旦达到
            // draft_limit 就阻断新生成——但不影响已经卡住的 run 被人工在
            // /content-generations/{run} 采纳，那是另一条独立的处理路径。
            $parkedForHumanReviewCount = ContentGenerationRun::query()
                ->whereIn('content_brief_id', ContentBrief::query()->where('task_id', (int) $task->id)->select('id'))
                ->whereIn('status', [
                    ContentGenerationRun::STATUS_NEEDS_EVIDENCE,
                    ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED,
                    ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED,
                ])
                ->count();
            if ($parkedForHumanReviewCount >= $draftLimit) {
                return '质量门禁失败过多，等待人工处理';
            }
        }

        if ($draftCount >= $draftLimit) {
            return '草稿池已满，等待审核或按间隔发布';
        }

        return null;
    }

    private function normalizePublishInterval(Task $task): int
    {
        return max(60, (int) ($task->publish_interval ?? 3600));
    }

    /**
     * 解析并校验任务绑定的 AI 模型（必须是 active + chat）。
     */
    private function resolveAiModel(Task $task): AiModel
    {
        $aiModel = $this->resolveConfiguredAiModel($task);
        if (($aiModel->status ?? 'inactive') !== 'active') {
            throw new RuntimeException('任务 AI 模型不可用');
        }

        return $aiModel;
    }

    /**
     * 读取任务绑定的聊天模型；智能切换会保留停用主模型的尝试记录并继续备用模型。
     */
    private function resolveConfiguredAiModel(Task $task): AiModel
    {
        $aiModelId = (int) ($task->ai_model_id ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('任务未配置 AI 模型');
        }

        $aiModel = AiModel::query()
            ->whereKey($aiModelId)
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('任务 AI 模型不可用');
        }

        return $aiModel;
    }

    /**
     * 固定模型只尝试主模型；智能切换按 failover_priority 依次尝试其它 active chat 模型。
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    private function generateContentWithModelSelection(Task $task, string $contentPrompt): array
    {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        foreach ($this->resolveAiModelCandidates($task) as $candidate) {
            $unavailableReason = $this->getAiModelUnavailableReason($candidate);
            if ($unavailableReason !== null) {
                $attempts[] = $this->buildModelAttempt($candidate, 'skipped', $unavailableReason);
                $lastMessage = $unavailableReason;
                if ($mode !== 'smart_failover') {
                    throw new RuntimeException($unavailableReason);
                }

                continue;
            }

            try {
                $content = $this->generateContent($candidate, $contentPrompt);
                $attempts[] = $this->buildModelAttempt($candidate, 'success', null);

                return [
                    'content' => $content,
                    'model' => $candidate,
                    'attempts' => $attempts,
                ];
            } catch (Throwable $exception) {
                $lastMessage = trim($exception->getMessage());
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage);

                if ($mode !== 'smart_failover') {
                    throw $exception;
                }
            }
        }

        if ($mode === 'smart_failover' && $attempts !== []) {
            throw new RuntimeException($this->buildFailoverErrorMessage($attempts, $lastMessage));
        }

        throw new RuntimeException('AI模型不可用或已达每日限制');
    }

    /**
     * @return list<AiModel>
     */
    private function resolveAiModelCandidates(Task $task): array
    {
        $primaryModel = $this->resolveConfiguredAiModel($task);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$this->resolveAiModel($task)];
        }

        $fallbackModels = AiModel::query()
            ->whereKeyNot((int) $primaryModel->id)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_merge([$primaryModel], $fallbackModels));
    }

    private function getAiModelUnavailableReason(AiModel $aiModel): ?string
    {
        if (($aiModel->status ?? 'inactive') !== 'active') {
            return 'AI模型不可用或已达每日限制';
        }

        return null;
    }

    /**
     * @return array{model_id:int,model_name:string,status:string,reason:?string}
     */
    private function buildModelAttempt(AiModel $aiModel, string $status, ?string $reason): array
    {
        return [
            'model_id' => (int) $aiModel->id,
            'model_name' => (string) $aiModel->name,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array{model_id:int,model_name:string,status:string,reason:?string}>  $attempts
     */
    private function buildFailoverErrorMessage(array $attempts, string $lastMessage): string
    {
        $summaries = [];
        foreach ($attempts as $attempt) {
            $reason = trim((string) ($attempt['reason'] ?? ''));
            $summaries[] = (string) $attempt['model_name'].($reason !== '' ? '（'.$reason.'）' : '');
        }

        return '智能模型切换已尝试：'.implode('；', $summaries).'。最终失败：'.$lastMessage;
    }

    private function pickTitle(Task $task): Title
    {
        $libraryId = (int) ($task->title_library_id ?? 0);
        if ($libraryId <= 0) {
            throw new RuntimeException('任务未配置标题库');
        }

        $query = Title::query()->where('library_id', $libraryId);
        if ((int) ($task->is_loop ?? 0) !== 1) {
            $query->where(function ($builder): void {
                $builder->whereNull('used_count')->orWhere('used_count', '<=', 0);
            });
        }

        /** @var Title|null $title */
        $title = $query
            ->orderBy('used_count')
            ->orderBy('id')
            ->first();

        if (! $title) {
            throw new RuntimeException((int) ($task->is_loop ?? 0) === 1 ? '没有可用的标题' : '标题库已用尽');
        }

        return $title;
    }

    private function pickAuthor(Task $task): Author
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            $author = Author::query()->find($authorId);
            if ($author) {
                return $author;
            }
        }

        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return $author;
        }

        return Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function pickCategory(Task $task): ?Category
    {
        if (($task->category_mode ?? 'smart') === 'fixed' && (int) ($task->fixed_category_id ?? 0) > 0) {
            return Category::query()->find((int) $task->fixed_category_id);
        }

        return Category::query()->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * 构造正文提示词：优先精确替换变量；无变量的自定义提示词自动补齐任务上下文。
     */
    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        return $this->articleContentPromptRenderer->renderForWorker($title, $keyword, $promptContent, $knowledgeContext);
    }

    /**
     * 按任务配置检索知识库上下文并回填到 {{Knowledge}}。
     */
    private function resolveKnowledgeContext(Task $task, string $title, string $keyword): string
    {
        $knowledgeBaseIds = $this->resolveTaskKnowledgeBaseIds($task);
        if ($knowledgeBaseIds === []) {
            return '';
        }

        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->select([
                'id',
                'brand_id',
                'site_id',
                'primary_directory_id',
                'language_code',
                'lifecycle_status',
                'generation_eligible',
            ])
            ->selectRaw('SUBSTR(content, 1, ?) AS content_excerpt', [2400])
            ->get()
            ->keyBy('id');
        if ($knowledgeBases->count() !== count($knowledgeBaseIds)) {
            return '';
        }

        $knowledgeScope = $this->knowledgeScope($knowledgeBases, $knowledgeBaseIds);
        if ($knowledgeScope === null) {
            return '';
        }

        $fallbackContents = [];
        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            /** @var KnowledgeBase|null $knowledgeBase */
            $knowledgeBase = $knowledgeBases->get($knowledgeBaseId);
            if (! $knowledgeBase) {
                continue;
            }

            $content = trim((string) ($knowledgeBase->content_excerpt ?? ''));
            if ($content === '') {
                continue;
            }

            $fallbackContents[$knowledgeBaseId] = $content;
        }

        if ($fallbackContents === []) {
            return '';
        }

        $query = trim($title."\n".$keyword);
        $context = $this->knowledgeRetrievalService->retrieveContextFromMany($knowledgeScope, $knowledgeBaseIds, $query, 5, 3200);
        if ($context !== '') {
            return $context;
        }

        $chunkCount = KnowledgeChunk::query()->whereIn('knowledge_base_id', $knowledgeBaseIds)->count();
        if ($chunkCount > 0) {
            return '';
        }

        return $this->fallbackKnowledgeContext($fallbackContents, 2400);
    }

    /**
     * @param  Collection<int,KnowledgeBase>  $knowledgeBases
     * @param  list<int>  $knowledgeBaseIds
     */
    private function knowledgeScope(Collection $knowledgeBases, array $knowledgeBaseIds): ?KnowledgeScope
    {
        /** @var KnowledgeBase|null $firstKnowledgeBase */
        $firstKnowledgeBase = $knowledgeBases->get($knowledgeBaseIds[0] ?? 0);
        if (! $firstKnowledgeBase) {
            return null;
        }

        $brandId = (int) ($firstKnowledgeBase->brand_id ?? 0);
        $siteId = (int) ($firstKnowledgeBase->site_id ?? 0);
        $languageCode = trim((string) ($firstKnowledgeBase->language_code ?? ''));
        $directoryIds = [];

        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            /** @var KnowledgeBase|null $knowledgeBase */
            $knowledgeBase = $knowledgeBases->get($knowledgeBaseId);
            $directoryId = (int) ($knowledgeBase?->primary_directory_id ?? 0);
            if (! $knowledgeBase
                || $brandId <= 0
                || $siteId <= 0
                || $languageCode === ''
                || (int) ($knowledgeBase->brand_id ?? 0) !== $brandId
                || (int) ($knowledgeBase->site_id ?? 0) !== $siteId
                || trim((string) ($knowledgeBase->language_code ?? '')) !== $languageCode
                || $directoryId <= 0
                || trim((string) ($knowledgeBase->lifecycle_status ?? '')) !== 'active'
                || ! (bool) $knowledgeBase->generation_eligible) {
                return null;
            }

            $directoryIds[$directoryId] = true;
        }

        $directoryId = count($directoryIds) === 1 ? (int) array_key_first($directoryIds) : null;

        return new KnowledgeScope($brandId, $siteId, $languageCode, $directoryId);
    }

    /**
     * @return list<int>
     */
    private function resolveTaskKnowledgeBaseIds(Task $task): array
    {
        $taskId = (int) ($task->id ?? 0);
        if ($taskId > 0 && Schema::hasTable('task_knowledge_bases')) {
            $ids = DB::table('task_knowledge_bases')
                ->where('task_id', $taskId)
                ->orderBy('sort_order')
                ->orderBy('knowledge_base_id')
                ->pluck('knowledge_base_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->take(5)
                ->values()
                ->all();

            if ($ids !== []) {
                $bases = KnowledgeBase::whereIn('id', $ids)->get(['id', 'brand_id', 'site_id']);
                $scopes = $bases->filter(fn ($base) => $base->brand_id !== null || $base->site_id !== null)
                    ->map(fn ($base) => $base->brand_id.':'.$base->site_id)->unique();
                if ($scopes->count() > 1 || ($scopes->isNotEmpty() && $bases->contains(fn ($base) => $base->brand_id === null || $base->site_id === null))) {
                    throw ValidationException::withMessages(['knowledge_base_ids' => 'Explicit knowledge libraries must share one brand and source site.']);
                }

                return $ids;
            }
        }

        $legacyKnowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);

        return $legacyKnowledgeBaseId > 0 ? [$legacyKnowledgeBaseId] : [];
    }

    /**
     * @param  array<int,string>  $contents
     */
    private function fallbackKnowledgeContext(array $contents, int $maxChars): string
    {
        $parts = [];
        $charCount = 0;

        foreach ($contents as $knowledgeBaseId => $content) {
            $content = trim($content);
            if ($content === '') {
                continue;
            }

            $header = '【知识库 '.$knowledgeBaseId.'】';
            $remaining = max(0, $maxChars - $charCount - mb_strlen($header, 'UTF-8') - 2);
            if ($remaining <= 0) {
                break;
            }

            $snippet = mb_strlen($content, 'UTF-8') > $remaining
                ? mb_substr($content, 0, $remaining, 'UTF-8')
                : $content;
            $parts[] = $header."\n".$snippet;
            $charCount += mb_strlen($header."\n".$snippet, 'UTF-8');
        }

        return $parts === [] ? '' : implode("\n\n", $parts);
    }

    /**
     * 从 knowledge_chunks 中检索相关片段。
     */
    private function fetchKnowledgeContextFromChunks(int $knowledgeBaseId, string $query, int $limit, int $maxChars): string
    {
        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseId, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                return $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);
            }
        }

        $rows = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'content', 'embedding_json', 'embedding_model_id', 'embedding_dimensions'])
            ->all();
        if ($rows === []) {
            return '';
        }

        $queryTerms = $this->termFrequencies($query);
        $hasRealEmbeddingRows = collect($rows)->contains(
            fn ($row): bool => $this->chunkHasRealEmbedding($row)
        );
        $useRealEmbeddingScore = false;
        $queryVector = [];
        if ($hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)));
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
            $vectorScore = ($useRealEmbeddingScore === $chunkUsesRealEmbedding)
                ? $this->dotProduct($queryVector, $vector)
                : 0.0;
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : ($a['chunk_index'] <=> $b['chunk_index']);
        });

        return $this->composeKnowledgeContext($scored, $limit, $maxChars);
    }

    /**
     * 判断 chunk 是否保存了真实 embedding，而不是 fallback hash 向量。
     */
    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * 按任务图片配置插入 Markdown 配图并返回被选中的图片列表。
     *
     * @return array{content:string,images:list<Image>}
     */
    private function insertTaskImagesIntoContent(Task $task, string $content): array
    {
        $libraryId = (int) ($task->image_library_id ?? 0);
        $imageCount = max(0, (int) ($task->image_count ?? 0));
        if ($libraryId <= 0 || $imageCount <= 0) {
            return ['content' => $content, 'images' => []];
        }

        /** @var list<Image> $images */
        $images = Image::query()
            ->where('library_id', $libraryId)
            ->inRandomOrder()
            ->limit($imageCount)
            ->get(['id', 'file_path', 'original_name'])
            ->all();
        if ($images === []) {
            return ['content' => $content, 'images' => []];
        }

        $markdownBlocks = [];
        foreach ($images as $image) {
            $path = trim((string) ($image->file_path ?? ''));
            if ($path === '') {
                continue;
            }
            $path = ImageUrlNormalizer::toPublicUrl($path);
            $alt = ImageUrlNormalizer::readableAlt((string) ($image->original_name ?? ''));
            $markdownBlocks[] = '!['.($alt !== '' ? $alt : 'image').']('.$path.')';
        }

        if ($markdownBlocks !== []) {
            $content = $this->insertImagesByParagraphInterval($content, $markdownBlocks);
        }

        return ['content' => $content, 'images' => $images];
    }

    /**
     * 按段落间隔插入图片，避免全部堆在文末。
     *
     * @param  list<string>  $markdownBlocks
     */
    private function insertImagesByParagraphInterval(string $content, array $markdownBlocks): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $markdownBlocks === []) {
            return $content;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($paragraphs === []) {
            return $trimmed."\n\n".implode("\n\n", $markdownBlocks);
        }

        $paragraphCount = count($paragraphs);
        $imageCount = count($markdownBlocks);
        $interval = max(1, (int) floor($paragraphCount / ($imageCount + 1)));

        $parts = [];
        $imageIndex = 0;
        foreach ($paragraphs as $index => $paragraph) {
            $parts[] = trim((string) $paragraph);
            $nextParagraphPosition = $index + 1;

            if (
                $imageIndex < $imageCount
                && $nextParagraphPosition % $interval === 0
                && $nextParagraphPosition < $paragraphCount
            ) {
                $parts[] = $markdownBlocks[$imageIndex];
                $imageIndex++;
            }
        }

        while ($imageIndex < $imageCount) {
            $parts[] = $markdownBlocks[$imageIndex];
            $imageIndex++;
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * 调用任务配置模型生成正文。
     */
    private function generateContent(AiModel $aiModel, string $contentPrompt): string
    {
        $response = $this->articleContentGenerationService->generate($aiModel, $contentPrompt);

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);
        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new RuntimeException('AI 返回空流式响应，未生成正文内容，请重试或检查模型流式输出兼容性');
            }

            throw new RuntimeException('AI返回空正文');
        }

        $this->warnIfContentLooksTruncated($content, $aiModel, $response);

        return $content;
    }

    /**
     * 解析模型的最大输出 token 数：优先用模型自身配置，未配置时回退全局默认值。
     */
    private function resolveMaxTokens(AiModel $aiModel): int
    {
        return $this->articleContentGenerationService->maxTokens($aiModel);
    }

    /**
     * 检测生成正文是否疑似被模型截断（输出 token 用尽）。
     *
     * 仅记录告警便于排查，不阻断流程：典型信号是未闭合的代码围栏（``` 数量为奇数），
     * 或正文结尾未落在正常的句末标点上。命中后提示调大该模型的 max_tokens。
     */
    private function warnIfContentLooksTruncated(string $content, AiModel $aiModel, object $response): void
    {
        $trimmed = rtrim($content);
        if ($trimmed === '') {
            return;
        }

        $maxTokens = $this->resolveMaxTokens($aiModel);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $nearTokenLimit = $completionTokens > 0 && $completionTokens >= (int) floor($maxTokens * 0.92);
        $lengthFinishReason = collect($response->steps ?? [])->contains(function (mixed $step): bool {
            $finishReason = is_object($step) ? ($step->finishReason ?? null) : null;

            return $finishReason === FinishReason::Length
                || (is_string($finishReason) && $finishReason === FinishReason::Length->value)
                || (is_object($finishReason) && property_exists($finishReason, 'value') && $finishReason->value === FinishReason::Length->value);
        });

        $fenceCount = substr_count($trimmed, '```');
        $unclosedFence = ($fenceCount % 2) === 1;

        $lastChar = mb_substr($trimmed, -1);
        $allowedEndings = ['。', '！', '？', '.', '!', '?', '”', '"', '）', ')', '》', '`', '】', ']', '…', ':', '：', ';', '；', '-', '—'];
        $hasAbruptTrailingText = $nearTokenLimit
            && ! in_array($lastChar, $allowedEndings, true)
            && preg_match('/[\p{L}\p{N}]$/u', $trimmed) === 1;

        if (! $lengthFinishReason && ! $unclosedFence && ! $hasAbruptTrailingText) {
            return;
        }

        Log::warning('GeoFlow 正文疑似被截断，建议调大该模型的 max_tokens', [
            'ai_model_id' => (int) $aiModel->id,
            'model_id' => (string) ($aiModel->model_id ?? ''),
            'max_tokens' => $maxTokens,
            'completion_tokens' => $completionTokens,
            'content_length' => mb_strlen($trimmed),
            'finish_reason_length' => $lengthFinishReason,
            'unclosed_code_fence' => $unclosedFence,
            'has_abrupt_trailing_text' => $hasAbruptTrailingText,
        ]);
    }

    /**
     * 从正文提取摘要，避免把完整提示词原文当摘要。
     */
    private function buildExcerpt(string $content): string
    {
        $plain = preg_replace('/[`#>*_\-\[\]\(\)]/u', ' ', $content) ?: $content;
        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;
        $plain = trim($plain);
        if ($plain === '') {
            return 'AI 生成内容摘要';
        }

        return mb_substr($plain, 0, 180);
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];
        $frequencies = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') <= 1) {
                continue;
            }
            $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $chunkTerms
     */
    private function lexicalScore(array $queryTerms, array $chunkTerms): float
    {
        if ($queryTerms === [] || $chunkTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($chunkTerms[$term])) {
                $matched += min($count, (int) $chunkTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * 优先使用 pgvector 执行数据库向量检索，命中则返回候选块。
     *
     * @return list<array{chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(int $knowledgeBaseId, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }

        $rows = DB::select(
            '
                SELECT chunk_index, content,
                       (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks
                WHERE knowledge_base_id = ?
                  AND embedding_vector IS NOT NULL
                ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                LIMIT ?
            ',
            [$vectorLiteral, $knowledgeBaseId, $vectorLiteral, max(1, $candidateLimit)]
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $results[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => 1.0 - $distance,
            ];
        }

        return $results;
    }

    /**
     * 仅在 PostgreSQL 且 pgvector 可用时启用向量检索。
     */
    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 从候选块拼装知识上下文，按片段顺序输出。
     *
     * @param  list<array{chunk_index:int,content:string,score:float}>  $scored
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): string
    {
        if ($scored === []) {
            return '';
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => $a['chunk_index'] <=> $b['chunk_index']);

        $parts = [];
        $charCount = 0;
        foreach ($selected as $index => $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }
            $parts[] = '【知识片段'.($index + 1)."】\n".$content;
            $charCount = $nextLength;
        }

        return trim(implode("\n\n", $parts));
    }
}
