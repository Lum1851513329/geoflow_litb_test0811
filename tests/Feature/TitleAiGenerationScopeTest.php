<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AnonymousAgent;
use Tests\TestCase;

class TitleAiGenerationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_page_offers_taxonomy_cluster_and_output_language_controls(): void
    {
        $admin = Admin::query()->create([
            'username' => 'title_scope_admin',
            'password' => 'secret-123',
            'email' => 'title-scope@example.test',
            'display_name' => 'Title Scope Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'English guides']);
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'LightInTheBox taxonomy']);
        $keyword = Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'green formal dress for wedding',
            'normalized_keyword' => 'green formal dress for wedding',
            'language_code' => 'en',
            'market_scope' => 'US',
            'keyword_type' => 'category',
            'search_intent' => 'commercial',
            'brand_relation' => 'non_brand',
            'status' => 'active',
        ]);
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Product taxonomy', 'slug' => 'product-taxonomy']);
        $node = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'name' => 'Wedding Dresses',
            'slug' => 'wedding-dresses',
            'path' => 'fashion/wedding-dresses',
            'depth' => 1,
            'sort_order' => 1,
        ]);
        $node->keywords()->attach($keyword->id, ['is_primary' => true, 'source' => 'test']);
        $cluster = KeywordCluster::query()->create([
            'name' => 'Green formal wedding dresses',
            'slug' => 'green-formal-wedding-dresses',
            'taxonomy_node_id' => $node->id,
            'primary_keyword_id' => $keyword->id,
            'language_code' => 'en',
            'market_scope' => 'US',
            'search_intent' => 'commercial',
            'status' => 'active',
        ]);
        $cluster->keywords()->attach($keyword->id, ['role' => 'primary', 'sort_order' => 0]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.title-libraries.ai-generate', $titleLibrary));

        $response->assertOk()
            ->assertSee('name="taxonomy_node_id"', false)
            ->assertSee('name="keyword_cluster_id"', false)
            ->assertSee('name="output_language"', false)
            ->assertSee('value="en"', false);
        $this->actingAs($admin, 'admin')->getJson(route('admin.title-libraries.ai-generate.scopes', $titleLibrary).'?keyword_library_id='.$keywordLibrary->id.'&selected_node_id='.$node->id)
            ->assertOk()
            ->assertJsonPath('nodes.0.name', 'Wedding Dresses')
            ->assertJsonPath('clusters.0.name', 'Green formal wedding dresses');
    }

    public function test_generation_requires_a_taxonomy_category_instead_of_sampling_the_whole_library(): void
    {
        $admin = Admin::query()->create([
            'username' => 'title_scope_validation_admin',
            'password' => 'secret-123',
            'email' => 'title-scope-validation@example.test',
            'display_name' => 'Title Scope Validation Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'English guides']);
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'LightInTheBox taxonomy']);
        Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'green formal dress for wedding',
            'normalized_keyword' => 'green formal dress for wedding',
            'language_code' => 'en',
            'market_scope' => 'US',
            'keyword_type' => 'category',
            'search_intent' => 'commercial',
            'brand_relation' => 'non_brand',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Title model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'title-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test/v1',
            'daily_limit' => 10,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.submit', $titleLibrary), [
            'keyword_library_id' => $keywordLibrary->id,
            'ai_model_id' => $model->id,
            'title_count' => 3,
            'title_style' => 'professional',
            'output_language' => 'en',
        ]);

        $response->assertSessionHasErrors('taxonomy_node_id');
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_generation_uses_only_the_selected_category_and_requests_english_output(): void
    {
        AnonymousAgent::fake(['{"titles":[{"title":"Green Formal Wedding Dresses: A Practical Selection Guide","keyword":"green formal dress for wedding"}]}'])->preventStrayPrompts();
        $admin = Admin::query()->create([
            'username' => 'title_scope_submit_admin', 'password' => 'secret-123',
            'email' => 'title-scope-submit@example.test', 'display_name' => 'Title Scope Submit Admin',
            'role' => 'admin', 'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'English guides']);
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'LightInTheBox taxonomy']);
        $scopedKeyword = Keyword::query()->create([
            'library_id' => $keywordLibrary->id, 'keyword' => 'green formal dress for wedding',
            'normalized_keyword' => 'green formal dress for wedding', 'language_code' => 'en',
            'market_scope' => 'US', 'keyword_type' => 'category', 'search_intent' => 'commercial',
            'brand_relation' => 'non_brand', 'status' => 'active',
        ]);
        Keyword::query()->create([
            'library_id' => $keywordLibrary->id, 'keyword' => 'artificial christmas tree',
            'normalized_keyword' => 'artificial christmas tree', 'language_code' => 'en',
            'market_scope' => 'US', 'keyword_type' => 'category', 'search_intent' => 'commercial',
            'brand_relation' => 'non_brand', 'status' => 'active',
        ]);
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Product taxonomy', 'slug' => 'product-taxonomy']);
        $node = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id, 'name' => 'Wedding Dresses', 'slug' => 'wedding-dresses',
            'path' => 'fashion/wedding-dresses', 'depth' => 1, 'sort_order' => 1,
        ]);
        $node->keywords()->attach($scopedKeyword->id, ['is_primary' => true, 'source' => 'test']);
        $secondaryKeyword = Keyword::query()->create([
            'library_id' => $keywordLibrary->id, 'keyword' => 'sage green wedding guest dress',
            'normalized_keyword' => 'sage green wedding guest dress', 'language_code' => 'en',
            'market_scope' => 'US', 'keyword_type' => 'category', 'search_intent' => 'commercial',
            'brand_relation' => 'non_brand', 'status' => 'active',
        ]);
        $node->keywords()->attach($secondaryKeyword->id, ['is_primary' => false, 'source' => 'test']);
        $model = AiModel::query()->create([
            'name' => 'Title model', 'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'title-model',
            'model_type' => 'chat', 'api_url' => 'https://ai.example.test/v1', 'daily_limit' => 10,
            'used_today' => 0, 'total_used' => 0, 'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.submit', $titleLibrary), [
            'keyword_library_id' => $keywordLibrary->id,
            'taxonomy_node_id' => $node->id,
            'output_language' => 'en',
            'ai_model_id' => $model->id,
            'title_count' => 1,
            'title_style' => 'professional',
        ])->assertRedirect(route('admin.title-libraries.detail', $titleLibrary));

        AnonymousAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'green formal dress for wedding')
            && ! str_contains($prompt->prompt, 'artificial christmas tree')
            && str_contains($prompt->prompt, 'English'));
        $this->assertDatabaseHas('titles', [
            'library_id' => $titleLibrary->id,
            'title' => 'Green Formal Wedding Dresses: A Practical Selection Guide',
            'keyword' => 'green formal dress for wedding',
        ]);
    }
}
