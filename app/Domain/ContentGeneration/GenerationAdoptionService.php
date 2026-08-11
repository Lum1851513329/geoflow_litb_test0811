<?php

namespace App\Domain\ContentGeneration;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunStep;
use App\Services\GeoFlow\ArticleGeoFlowService;
use DomainException;
use Illuminate\Support\Facades\DB;

final class GenerationAdoptionService
{
    public function __construct(
        private readonly ArticleGeoFlowService $articleGeoFlowService,
        private readonly SensitiveContentRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $articleData
     */
    public function adopt(ContentGenerationRun $run, array $articleData, Admin $actor): Article
    {
        return DB::transaction(function () use ($run, $articleData, $actor): Article {
            /** @var ContentGenerationRun $lockedRun */
            $lockedRun = ContentGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if ($lockedRun->status === ContentGenerationRun::STATUS_ADOPTED) {
                if ($lockedRun->article_id === null) {
                    throw GenerationAdoptionIntegrityException::adoptedRunMissingArticle((int) $lockedRun->id);
                }

                return Article::withTrashed()->findOrFail($lockedRun->article_id);
            }

            if ($lockedRun->status !== ContentGenerationRun::STATUS_READY_FOR_REVIEW) {
                throw new DomainException('Only ready-for-review generation runs can be adopted.');
            }

            $sensitiveValues = $this->redactor->valuesForRun($lockedRun);
            $rawOutput = $this->redactor->string((string) $lockedRun->raw_output, $sensitiveValues);
            if (trim($rawOutput) === '') {
                throw new DomainException('A generation run without output cannot be adopted.');
            }

            $safeArticleData = $this->redactor->value($articleData, $sensitiveValues);
            $created = $this->articleGeoFlowService->createArticle(array_merge($safeArticleData, [
                'content' => $safeArticleData['content'] ?? $rawOutput,
                'status' => 'draft',
                'review_status' => 'pending',
                'is_ai_generated' => 1,
                'risk_override_reason' => '',
            ]), (int) $actor->getKey());

            /** @var Article $article */
            $article = Article::query()->findOrFail($created['id']);

            ContentGenerationRunStep::query()->create([
                'content_generation_run_id' => $lockedRun->id,
                'sequence' => ((int) $lockedRun->steps()->max('sequence')) + 1,
                'step_type' => ContentGenerationRunStep::TYPE_ADOPTION,
                'status' => ContentGenerationRunStep::STATUS_COMPLETED,
                'input_snapshot' => [
                    'actor_admin_id' => (int) $actor->id,
                    'raw_output_sha256' => hash('sha256', $rawOutput),
                    'adopted_output_sha256' => hash('sha256', (string) $article->content),
                    'compiled_prompt_sha256' => hash('sha256', (string) $lockedRun->compiled_prompt),
                ],
                'metadata' => [
                    'article_id' => (int) $article->id,
                    'article_content_sha256' => hash('sha256', (string) $article->content),
                ],
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $updated = ContentGenerationRun::query()
                ->whereKey($lockedRun->id)
                ->where('status', ContentGenerationRun::STATUS_READY_FOR_REVIEW)
                ->whereNull('article_id')
                ->update([
                    'article_id' => $article->id,
                    'adopted_output' => $article->content,
                    'status' => ContentGenerationRun::STATUS_ADOPTED,
                    'adopted_by_admin_id' => $actor->id,
                    'adopted_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new DomainException('The generation run changed before it could be adopted.');
            }

            return $article;
        });
    }
}
