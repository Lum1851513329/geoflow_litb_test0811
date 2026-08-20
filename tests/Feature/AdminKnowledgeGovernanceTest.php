<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeDuplicate;
use App\Models\KnowledgeSite;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminKnowledgeGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs($this->createAdmin(), 'admin');
    }

    public function test_index_provides_brand_and_site_switchers_and_brand_scoped_directory_navigation(): void
    {
        $lightInTheBox = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $returns = KnowledgeDirectory::query()->create([
            'brand_id' => $lightInTheBox['brand']->id,
            'parent_id' => $lightInTheBox['directory']->id,
            'key' => 'returns',
            'path' => 'policy-compliance/returns',
            'name' => 'Returns',
        ]);
        $foreign = $this->createOwnership('foreign-brand', 'Foreign Brand');
        $foreign['directory']->update(['name' => 'Foreign Policy Only']);

        $this->get(route('admin.knowledge-bases.index', [
            'brand_id' => $lightInTheBox['brand']->id,
        ]))
            ->assertOk()
            ->assertSee('name="brand_id"', false)
            ->assertSee('name="site_id"', false)
            ->assertSee('data-knowledge-directory-tree', false)
            ->assertSee('LightInTheBox')
            ->assertSee('www.lightinthebox.test')
            ->assertSee('Policy &amp; Compliance', false)
            ->assertSee('Returns')
            ->assertSee(route('admin.knowledge-bases.index', [
                'brand_id' => $lightInTheBox['brand']->id,
                'directory_id' => $returns->id,
            ]))
            ->assertDontSee('www.foreign-brand.test')
            ->assertDontSee($foreign['directory']->name);
    }

    public function test_index_applies_all_governance_filters_together(): void
    {
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $otherSite = KnowledgeSite::query()->create([
            'brand_id' => $scope['brand']->id,
            'key' => 'lightinthebox-secondary',
            'canonical_host' => 'secondary.lightinthebox.test',
            'role' => 'commerce-secondary',
        ]);
        $otherDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $scope['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research Reference',
        ]);

        $this->createDocument($scope, ['name' => 'MATCHING GOVERNED DOCUMENT']);
        $this->createDocument($scope, ['name' => 'WRONG SITE', 'site_id' => $otherSite->id]);
        $this->createDocument($scope, ['name' => 'WRONG DIRECTORY', 'primary_directory_id' => $otherDirectory->id]);
        $this->createDocument($scope, ['name' => 'WRONG LANGUAGE', 'language_code' => 'pt-BR']);
        $this->createDocument($scope, ['name' => 'WRONG AUTHORITY', 'authority_level' => 'mixed_research']);
        $this->createDocument($scope, ['name' => 'WRONG RISK', 'risk_level' => 'high']);
        $this->createDocument($scope, ['name' => 'WRONG REVIEW', 'review_status' => 'unreviewed']);
        $this->createDocument($scope, ['name' => 'WRONG LIFECYCLE', 'lifecycle_status' => 'superseded']);

        $response = $this->get(route('admin.knowledge-bases.index', [
            'brand_id' => $scope['brand']->id,
            'site_id' => $scope['site']->id,
            'directory_id' => $scope['directory']->id,
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'risk_level' => 'medium',
            'review_status' => 'reviewed',
            'lifecycle_status' => 'active',
        ]));

        $response->assertOk()->assertSee('MATCHING GOVERNED DOCUMENT');
        foreach (['WRONG SITE', 'WRONG DIRECTORY', 'WRONG LANGUAGE', 'WRONG AUTHORITY', 'WRONG RISK', 'WRONG REVIEW', 'WRONG LIFECYCLE'] as $excluded) {
            $response->assertDontSee($excluded);
        }
    }

    public function test_store_rejects_missing_or_cross_brand_ownership_and_provenance_before_writing(): void
    {
        $local = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $foreign = $this->createOwnership('foreign-brand', 'Foreign Brand');

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), [
                'name' => 'Missing Governance',
                'file_type' => 'markdown',
                'content' => 'This record must not be written.',
            ])
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors([
                'brand_id',
                'site_id',
                'primary_directory_id',
                'language_code',
                'authority_level',
                'source_name',
                'source_type',
            ]);

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), $this->governedPayload($local, [
                'name' => 'Cross-brand Site',
                'site_id' => $foreign['site']->id,
            ]))
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('site_id');

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), $this->governedPayload($local, [
                'name' => 'Cross-brand Directory',
                'primary_directory_id' => $foreign['directory']->id,
            ]))
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('primary_directory_id');

        $this->assertDatabaseMissing('knowledge_bases', ['name' => 'Missing Governance']);
        $this->assertDatabaseMissing('knowledge_bases', ['name' => 'Cross-brand Site']);
        $this->assertDatabaseMissing('knowledge_bases', ['name' => 'Cross-brand Directory']);
    }

    public function test_policy_and_high_risk_research_eligibility_rules_fail_closed(): void
    {
        $policy = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $returnsDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $policy['brand']->id,
            'parent_id' => $policy['directory']->id,
            'key' => 'returns',
            'path' => 'policy-compliance/returns',
            'name' => 'Returns',
        ]);
        $researchDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $policy['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research Reference',
        ]);

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), $this->governedPayload($policy, [
                'name' => 'Policy Without Provenance',
                'primary_directory_id' => $returnsDirectory->id,
                'source_url' => '',
                'retrieved_at' => '',
                'generation_eligible' => '1',
            ]))
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors(['source_url', 'retrieved_at']);

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), $this->governedPayload($policy, [
                'name' => 'Unsafe Research',
                'primary_directory_id' => $researchDirectory->id,
                'authority_level' => 'mixed_research',
                'risk_level' => 'high',
                'review_status' => 'unreviewed',
                'generation_eligible' => '1',
                'citation_required' => '0',
            ]))
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors(['generation_eligible', 'citation_required']);

        $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($policy, [
            'name' => 'Eligible Policy',
            'source_url' => 'https://www.lightinthebox.test/returns',
            'retrieved_at' => '2026-08-19',
            'generation_eligible' => '1',
        ]))->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'Eligible Policy',
            'brand_id' => $policy['brand']->id,
            'site_id' => $policy['site']->id,
            'primary_directory_id' => $policy['directory']->id,
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'generation_eligible' => 1,
        ]);
    }

    public function test_form_exposes_required_owner_provenance_and_governance_controls(): void
    {
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');

        $this->get(route('admin.knowledge-bases.create', ['brand_id' => $scope['brand']->id]))
            ->assertOk()
            ->assertSee('name="brand_id"', false)
            ->assertSee('name="site_id"', false)
            ->assertSee('name="primary_directory_id"', false)
            ->assertSee('name="language_code"', false)
            ->assertSee('name="authority_level"', false)
            ->assertSee('name="retrieved_at"', false)
            ->assertSee('name="review_due_at"', false)
            ->assertSee('name="lifecycle_status"', false)
            ->assertSee('name="generation_eligible"', false)
            ->assertSee('name="citation_required"', false)
            ->assertSee('name="source_name"', false)
            ->assertSee('data-brand-id="'.$scope['brand']->id.'"', false)
            ->assertSee('Policy &amp; Compliance', false);
    }

    public function test_index_and_detail_show_duplicate_version_and_governance_metadata(): void
    {
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $active = $this->createDocument($scope, [
            'name' => 'Returns Policy 2026',
            'canonical_document_key' => 'url:https://www.lightinthebox.test/returns',
            'version_label' => 'v2',
            'source_name' => 'Official Returns Center',
            'source_url' => 'https://www.lightinthebox.test/returns',
            'retrieved_at' => '2026-08-19 00:00:00',
            'review_due_at' => '2026-11-19',
            'generation_eligible' => true,
            'citation_required' => true,
        ]);
        $superseded = $this->createDocument($scope, [
            'name' => 'Returns Policy 2025',
            'canonical_document_key' => 'url:https://www.lightinthebox.test/returns',
            'version_label' => 'v1',
            'lifecycle_status' => 'superseded',
        ]);
        KnowledgeDuplicate::query()->create([
            'brand_id' => $scope['brand']->id,
            'site_id' => $scope['site']->id,
            'duplicate_document_id' => $superseded->id,
            'canonical_document_id' => $active->id,
            'duplicate_kind' => 'exact',
            'duplicate_score' => 1,
            'duplicate_reason' => 'Markdown source supersedes the TXT mirror.',
        ]);

        $this->get(route('admin.knowledge-bases.index', ['brand_id' => $scope['brand']->id]))
            ->assertOk()
            ->assertSee('Returns Policy 2026')
            ->assertSee(__('admin.knowledge_bases.badge_version', ['version' => 'v2']))
            ->assertSee(__('admin.knowledge_bases.badge_duplicate_exact'))
            ->assertSee('LightInTheBox')
            ->assertSee('Policy &amp; Compliance', false);

        $this->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $active->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_detail.governance_title'))
            ->assertSee('LightInTheBox')
            ->assertSee('www.lightinthebox.test')
            ->assertSee('policy-compliance')
            ->assertSee('Official Returns Center')
            ->assertSee('https://www.lightinthebox.test/returns')
            ->assertSee('first_party')
            ->assertSee('2026-08-19')
            ->assertSee('2026-11-19')
            ->assertSee('v2')
            ->assertSee(__('admin.knowledge_bases.badge_duplicate_exact'))
            ->assertSee('Markdown source supersedes the TXT mirror.');
    }

    public function test_detail_content_update_rejects_an_unowned_legacy_record(): void
    {
        Queue::fake();
        $legacy = KnowledgeBase::query()->create([
            'name' => 'Legacy Unowned',
            'content' => 'Legacy body',
            'file_type' => 'markdown',
        ]);

        $this->from(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $legacy->id]))
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $legacy->id]), [
                'name' => 'Attempted Unsafe Update',
                'description' => '',
                'content' => 'Changed body',
                'file_type' => 'markdown',
            ])
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $legacy->id]))
            ->assertSessionHasErrors('brand_id');

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $legacy->id,
            'name' => 'Legacy Unowned',
            'content' => 'Legacy body',
        ]);
    }

    /** @return array{brand: KnowledgeBrand, site: KnowledgeSite, directory: KnowledgeDirectory} */
    private function createOwnership(string $key, string $name): array
    {
        $brand = KnowledgeBrand::query()->create(['key' => $key, 'name' => $name]);
        $site = KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => $key.'-main',
            'canonical_host' => 'www.'.$key.'.test',
            'role' => 'commerce-primary',
        ]);
        $directory = KnowledgeDirectory::query()->create([
            'brand_id' => $brand->id,
            'key' => 'policy-compliance',
            'path' => 'policy-compliance',
            'name' => 'Policy & Compliance',
        ]);

        return compact('brand', 'site', 'directory');
    }

    /**
     * @param  array{brand: KnowledgeBrand, site: KnowledgeSite, directory: KnowledgeDirectory}  $scope
     * @param  array<string, mixed>  $overrides
     */
    private function createDocument(array $scope, array $overrides = []): KnowledgeBase
    {
        return KnowledgeBase::query()->create(array_replace([
            'name' => 'Governed document',
            'description' => 'Governed evidence',
            'content' => 'Governed evidence body.',
            'file_type' => 'markdown',
            'brand_id' => $scope['brand']->id,
            'site_id' => $scope['site']->id,
            'primary_directory_id' => $scope['directory']->id,
            'canonical_document_key' => 'manual:'.fake()->uuid(),
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'source_name' => 'Official source',
            'source_url' => 'https://www.lightinthebox.test/source',
            'source_type' => 'official_web_page',
            'retrieved_at' => '2026-08-19 00:00:00',
            'risk_level' => 'medium',
            'review_status' => 'reviewed',
            'version_label' => 'v1',
            'lifecycle_status' => 'active',
            'generation_eligible' => true,
            'citation_required' => true,
        ], $overrides));
    }

    /**
     * @param  array{brand: KnowledgeBrand, site: KnowledgeSite, directory: KnowledgeDirectory}  $scope
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function governedPayload(array $scope, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Governed knowledge',
            'description' => 'Governed evidence',
            'content' => 'Governed evidence body.',
            'file_type' => 'markdown',
            'brand_id' => $scope['brand']->id,
            'site_id' => $scope['site']->id,
            'primary_directory_id' => $scope['directory']->id,
            'canonical_document_key' => 'manual:'.fake()->uuid(),
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'source_name' => 'Official source',
            'source_url' => 'https://www.lightinthebox.test/source',
            'source_type' => 'official_web_page',
            'retrieved_at' => '2026-08-19',
            'risk_level' => 'medium',
            'review_status' => 'reviewed',
            'version_label' => 'v1',
            'lifecycle_status' => 'active',
            'generation_eligible' => '0',
            'citation_required' => '1',
            'import_action' => 'save',
        ], $overrides);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'knowledge_governance_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-governance-admin@example.com',
            'display_name' => 'Knowledge Governance Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
