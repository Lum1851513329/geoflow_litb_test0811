<?php

namespace Tests\Feature;

use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\Task;
use App\Services\GeoFlow\BrandKnowledgeEvidenceSelector;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BrandKnowledgeEvidenceSelectorTest extends TestCase
{
    use RefreshDatabase;

    private function scope(string $key = 'brand'): KnowledgeScope
    {
        $brand = KnowledgeBrand::create(['key' => $key, 'name' => $key]);
        $site = KnowledgeSite::create(['brand_id' => $brand->id, 'key' => $key, 'canonical_host' => $key.'.test', 'role' => 'commerce-primary']);

        return new KnowledgeScope($brand->id, $site->id, 'en');
    }

    private function chunk(KnowledgeScope $scope, string $content = 'linen dress care', array $attributes = []): KnowledgeChunk
    {
        $directory = KnowledgeDirectory::firstOrCreate(['brand_id' => $scope->brandId, 'key' => 'default'], ['path' => 'default', 'name' => 'Default']);
        $attributes = array_merge(['primary_directory_id' => $directory->id, 'canonical_document_key' => (string) str()->uuid()], $attributes);
        $base = KnowledgeBase::create(array_merge(['name' => 'Document', 'content' => $content, 'brand_id' => $scope->brandId, 'site_id' => $scope->siteId, 'language_code' => 'en', 'review_status' => 'reviewed', 'lifecycle_status' => 'active', 'generation_eligible' => true], $attributes));

        return KnowledgeChunk::create(['knowledge_base_id' => $base->id, 'chunk_index' => 0, 'content' => $content, 'chunk_title' => 'Document']);
    }

    public function test_full_brand_scope_excludes_foreign_sites_metadata_only_and_unrelated_chunks(): void
    {
        $scope = $this->scope();
        for ($i = 0; $i < 6; $i++) {
            $this->chunk($scope, 'unrelated shipping '.$i, ['description' => 'linen dress']);
        }
        $allowed = $this->chunk($scope);
        $this->chunk($this->scope('foreign'));
        $site = KnowledgeSite::create(['brand_id' => $scope->brandId, 'key' => 'second', 'canonical_host' => 'second.test', 'role' => 'commerce-primary']);
        $this->chunk(new KnowledgeScope($scope->brandId, $site->id, 'en'));
        $result = app(BrandKnowledgeEvidenceSelector::class)->select($scope, 'linen dress');
        $this->assertSame([$allowed->id], $result['chunk_ids']);
        $this->assertSame($allowed->knowledge_base_id, $result['evidence'][0]['knowledge_base_id']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_descendants_and_eligibility_are_enforced_without_cluster_binding(): void
    {
        $scope = $this->scope();
        $parent = KnowledgeDirectory::create(['brand_id' => $scope->brandId, 'key' => 'root', 'path' => 'root', 'name' => 'Root']);
        $child = KnowledgeDirectory::create(['brand_id' => $scope->brandId, 'parent_id' => $parent->id, 'key' => 'child', 'path' => 'root/child', 'name' => 'Child']);
        $allowed = $this->chunk($scope, 'linen dress care', ['primary_directory_id' => $child->id]);
        foreach ([['review_status' => 'unreviewed'], ['lifecycle_status' => 'superseded'], ['generation_eligible' => false], ['language_code' => 'fr'], ['effective_date' => now()->addDay()->toDateString()], ['review_due_at' => now()->subDay()->toDateString()]] as $override) {
            $this->chunk($scope, 'linen dress', array_merge(['primary_directory_id' => $child->id], $override));
        }
        $this->chunk($scope);
        $result = app(BrandKnowledgeEvidenceSelector::class)->select(new KnowledgeScope($scope->brandId, $scope->siteId, 'en', $parent->id, 123), 'linen dress');
        $this->assertSame([$allowed->id], $result['chunk_ids']);
    }

    public function test_unique_site_normalization_and_storage_casts(): void
    {
        $scope = $this->scope();
        $data = app(GenerationKnowledgeScopeResolver::class)->normalize(['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => (string) $scope->brandId]);
        $this->assertSame($scope->siteId, $data['knowledge_site_id']);
        $this->assertSame('legacy_manual', app(GenerationKnowledgeScopeResolver::class)->normalize([])['knowledge_mode']);
        foreach ([new Task, new ContentBrief] as $model) {
            $model->fill($data);
            $this->assertSame($scope->brandId, $model->knowledge_brand_id);
        }
        $brief = ContentBrief::create(['topic' => 'Test', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'Readers', 'core_question' => 'What?', 'business_goal' => 'Inform', 'content_type' => 'article']);
        $this->assertSame('legacy_manual', $brief->fresh()->knowledge_mode);
        $run = new ContentGenerationRun;
        $run->forceFill(['knowledge_retrieval_snapshot' => ['query' => 'linen dress']]);
        $this->assertSame(['query' => 'linen dress'], $run->knowledge_retrieval_snapshot);
    }

    public function test_invalid_scope_and_ambiguous_sites_are_rejected(): void
    {
        $scope = $this->scope();
        $foreign = $this->scope('foreign');
        $resolver = app(GenerationKnowledgeScopeResolver::class);
        foreach (['1x', 1.5, true, 0, -1, '99999999999999999999999'] as $id) {
            try {
                $resolver->normalize(['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $id]);
                $this->fail('Malformed scope accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([['knowledge_site_id' => $foreign->siteId], ['knowledge_brand_id' => null]] as $override) {
            try {
                $resolver->normalize(array_merge(['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $scope->brandId], $override));
                $this->fail('Invalid scope accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        KnowledgeSite::create(['brand_id' => $scope->brandId, 'key' => 'second', 'canonical_host' => 'second.test', 'role' => 'commerce-primary']);
        $this->expectException(ValidationException::class);
        $resolver->normalize(['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $scope->brandId]);
    }

    public function test_dedup_exclusions_and_complete_chunk_budgets(): void
    {
        $scope = $this->scope();
        $first = $this->chunk($scope);
        $this->chunk($scope, '  linen   dress care  ');
        $large = $this->chunk($scope, 'linen dress '.str_repeat('x', 24000));
        $selector = app(BrandKnowledgeEvidenceSelector::class);
        $this->assertSame([$first->id], $selector->select($scope, 'linen dress')['chunk_ids']);
        $this->assertNotContains($first->id, $selector->select($scope, 'linen dress', [], [$first->id])['chunk_ids']);
        $this->expectException(ValidationException::class);
        $selector->validateSelection($scope, [$large->id]);
    }

    public function test_explicit_ids_are_strict_and_revalidated(): void
    {
        $scope = $this->scope();
        $chunk = $this->chunk($scope);
        $foreign = $this->chunk($this->scope('foreign'));
        $selector = app(BrandKnowledgeEvidenceSelector::class);
        foreach ([[$chunk->id, (string) $chunk->id], ['1x'], [true], [999999], [$foreign->id], array_fill(0, 51, $chunk->id)] as $ids) {
            try {
                $selector->validateSelection($scope, $ids);
                $this->fail('Invalid selection accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $chunk->knowledgeBase->update(['generation_eligible' => false]);
        $this->expectException(ValidationException::class);
        $selector->validateSelection($scope, [$chunk->id]);
    }

    public function test_auto_selection_stops_at_count_and_total_character_budgets(): void
    {
        $scope = $this->scope();
        for ($i = 0; $i < 15; $i++) {
            $this->chunk($scope, 'linen dress '.$i);
        }
        $selector = app(BrandKnowledgeEvidenceSelector::class);
        $this->assertCount(12, $selector->select($scope, 'linen dress')['chunk_ids']);
        KnowledgeBase::query()->update(['generation_eligible' => false]);
        $a = $this->chunk($scope, 'linen dress '.str_repeat('a', 12000));
        $this->chunk($scope, 'linen dress '.str_repeat('b', 12000));
        $this->assertSame([$a->id], $selector->select($scope, 'linen dress')['chunk_ids']);
    }

    public function test_missing_sites_foreign_directories_and_invalid_language_fail_closed(): void
    {
        $scope = $this->scope();
        $foreign = $this->scope('foreign');
        $directory = KnowledgeDirectory::create(['brand_id' => $foreign->brandId, 'key' => 'root', 'path' => 'root', 'name' => 'Root']);
        $empty = KnowledgeBrand::create(['key' => 'empty', 'name' => 'Empty']);
        $resolver = app(GenerationKnowledgeScopeResolver::class);
        foreach ([['knowledge_brand_id' => $empty->id], ['knowledge_brand_id' => $scope->brandId, 'knowledge_directory_id' => $directory->id]] as $data) {
            try {
                $resolver->resolve($data);
                $this->fail('Invalid scope accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ValidationException::class);
        $resolver->resolve(['knowledge_brand_id' => $scope->brandId], 'invalid language');
    }

    public function test_manual_inclusion_can_select_nonmatching_content_but_exclusion_wins(): void
    {
        $scope = $this->scope();
        $chunk = $this->chunk($scope, 'Shipping instructions');
        $selector = app(BrandKnowledgeEvidenceSelector::class);
        $this->assertSame([], $selector->select($scope, 'linen dress')['chunk_ids']);
        $result = $selector->select($scope, 'linen dress', [(string) $chunk->id]);
        $this->assertSame([$chunk->id], $result['chunk_ids']);
        $this->assertSame('manual', $result['evidence'][0]['selection_method']);
        $this->assertNull($result['evidence'][0]['source_url']);
        $this->assertSame([], $selector->select($scope, 'linen dress', [$chunk->id], [$chunk->id])['chunk_ids']);
    }

    public function test_manual_selection_count_budget_cannot_be_bypassed(): void
    {
        $scope = $this->scope();
        $ids = [];
        for ($i = 0; $i < 13; $i++) {
            $ids[] = $this->chunk($scope, 'linen dress '.$i)->id;
        }
        $this->expectException(ValidationException::class);
        app(BrandKnowledgeEvidenceSelector::class)->validateSelection($scope, $ids);
    }

    public function test_natural_language_queries_retain_relevant_evidence_and_rank_before_candidate_cap(): void
    {
        $scope = $this->scope();
        $allowed = $this->chunk($scope, 'Linen dress washing instructions: use cold water.');
        $query = 'A practical guide for summer shoppers: how can customers choose comfortable outfits and what should they know about linen dress care?';
        $this->assertSame([$allowed->id], app(BrandKnowledgeEvidenceSelector::class)->select($scope, $query)['chunk_ids']);
        $allowed->knowledgeBase->update(['generation_eligible' => false]);
        for ($i = 0; $i < 301; $i++) {
            $this->chunk($scope, 'linen shipping '.$i);
        }
        $later = $this->chunk($scope, 'linen dress care');
        $this->assertContains($later->id, app(BrandKnowledgeEvidenceSelector::class)->select($scope, 'linen dress')['chunk_ids']);
    }
}
