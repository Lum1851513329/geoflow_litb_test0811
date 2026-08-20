<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeDuplicate;
use App\Models\KnowledgeSite;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeScope;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_research_family_and_mixed_authority_always_require_citations_but_medium_risk_can_generate(): void
    {
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $researchRoot = KnowledgeDirectory::query()->create([
            'brand_id' => $scope['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research Reference',
        ]);
        $nestedResearch = KnowledgeDirectory::query()->create([
            'brand_id' => $scope['brand']->id,
            'parent_id' => $researchRoot->id,
            'key' => 'market-research',
            'path' => 'research-reference/market-research',
            'name' => 'Market Research',
        ]);
        $companyDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $scope['brand']->id,
            'key' => 'brand-company',
            'path' => 'brand-company',
            'name' => 'Brand & Company',
        ]);

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
                'name' => 'Nested Research Without Citation',
                'primary_directory_id' => $nestedResearch->id,
                'risk_level' => 'medium',
                'review_status' => 'reviewed',
                'citation_required' => '0',
            ]))
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('citation_required');

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
                'name' => 'Mixed Authority Without Citation',
                'primary_directory_id' => $companyDirectory->id,
                'authority_level' => 'mixed_research',
                'risk_level' => 'medium',
                'review_status' => 'reviewed',
                'citation_required' => '0',
            ]))
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('citation_required');

        $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
            'name' => 'Medium Research Eligible With Citation',
            'content' => 'Medium-risk research may support generation when citations are mandatory.',
            'primary_directory_id' => $nestedResearch->id,
            'risk_level' => 'medium',
            'review_status' => 'unreviewed',
            'generation_eligible' => '1',
            'citation_required' => '1',
        ]))->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'Medium Research Eligible With Citation',
            'generation_eligible' => 1,
            'citation_required' => 1,
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

    public function test_edit_preserves_import_provenance_and_appends_operator_history(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $admin = Admin::query()->where('username', 'knowledge_governance_admin')->firstOrFail();
        $originalProvenance = [
            'namespace' => 'geoflow.governed-knowledge',
            'document_id' => 'litb-policy-returns',
            'ingestion_method' => 'corpus_import',
            'cleaned_file_hash' => str_repeat('a', 64),
            'warnings' => ['normalized_heading'],
            'import_batch_id' => 42,
            'source_checksum' => str_repeat('b', 64),
            'configuration_hash' => str_repeat('c', 64),
        ];
        $knowledgeBase = $this->createDocument($scope, [
            'name' => 'Imported Returns Policy',
            'content' => 'Original imported policy body.',
            'asset_key' => 'knowledge:imported:returns',
            'asset_provenance' => $originalProvenance,
        ]);

        $this->put(
            route('admin.knowledge-bases.update', ['knowledgeBaseId' => $knowledgeBase->id]),
            $this->governedPayload($scope, [
                'name' => 'Imported Returns Policy Updated',
                'content' => 'Operator-edited policy body.',
                'source_name' => 'Updated display source',
                'source_url' => 'https://www.lightinthebox.test/returns',
                'retrieved_at' => '2026-08-20',
            ]),
        )->assertRedirect(route('admin.knowledge-bases.index'));

        $provenance = (array) $knowledgeBase->fresh()->asset_provenance;
        foreach ($originalProvenance as $key => $value) {
            $this->assertSame($value, $provenance[$key] ?? null, $key);
        }
        $this->assertCount(1, $provenance['operator_edits'] ?? []);
        $this->assertSame('admin_form_update', $provenance['operator_edits'][0]['operation'] ?? null);
        $this->assertSame((int) $admin->id, $provenance['operator_edits'][0]['admin_id'] ?? null);
        $this->assertNotEmpty($provenance['operator_edits'][0]['edited_at'] ?? null);

        $this->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->id]), [
            'name' => 'Imported Returns Policy Updated',
            'description' => 'Detail editor revision',
            'content' => 'Second operator edit from the detail page.',
            'file_type' => 'markdown',
        ])->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->id]));

        $provenance = (array) $knowledgeBase->fresh()->asset_provenance;
        foreach ($originalProvenance as $key => $value) {
            $this->assertSame($value, $provenance[$key] ?? null, $key);
        }
        $this->assertCount(2, $provenance['operator_edits'] ?? []);
        $this->assertSame('admin_detail_update', $provenance['operator_edits'][1]['operation'] ?? null);
        $this->assertSame((int) $admin->id, $provenance['operator_edits'][1]['admin_id'] ?? null);
        $this->assertNotEmpty($provenance['operator_edits'][1]['edited_at'] ?? null);
    }

    public function test_single_file_source_hash_uses_raw_bytes_independent_of_storage_path_and_original_name(): void
    {
        Storage::fake('local');
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $rawBytes = "# Stable source\n\nIdentical raw bytes.";

        foreach (['alpha.md', 'renamed-source.md'] as $index => $fileName) {
            $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
                'name' => 'Stable source '.($index + 1),
                'content' => '',
                'knowledge_file' => UploadedFile::fake()->createWithContent($fileName, $rawBytes),
            ]))->assertRedirect(route('admin.knowledge-bases.index'));
        }

        $documents = KnowledgeBase::query()->where('name', 'like', 'Stable source %')->orderBy('id')->get();
        $this->assertCount(2, $documents);
        $this->assertSame(hash('sha256', $rawBytes), $documents[0]->source_file_hash);
        $this->assertSame(hash('sha256', $rawBytes), $documents[1]->source_file_hash);
        $this->assertNotSame($documents[0]->source_file_path, $documents[1]->source_file_path);
    }

    public function test_multi_file_source_hash_is_a_deterministic_raw_byte_aggregate(): void
    {
        Storage::fake('local');
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $alphaBytes = "# Alpha\n\nFirst source.";
        $betaBytes = "# Beta\n\nSecond source.";

        $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
            'name' => 'Aggregate source 1',
            'content' => '',
            'knowledge_files' => [
                UploadedFile::fake()->createWithContent('alpha.md', $alphaBytes),
                UploadedFile::fake()->createWithContent('beta.md', $betaBytes),
            ],
        ]))->assertRedirect(route('admin.knowledge-bases.index'));
        $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
            'name' => 'Aggregate source 2',
            'content' => '',
            'knowledge_files' => [
                UploadedFile::fake()->createWithContent('renamed-beta.md', $betaBytes),
                UploadedFile::fake()->createWithContent('renamed-alpha.md', $alphaBytes),
            ],
        ]))->assertRedirect(route('admin.knowledge-bases.index'));

        $rawHashes = [hash('sha256', $alphaBytes), hash('sha256', $betaBytes)];
        sort($rawHashes, SORT_STRING);
        $expected = hash('sha256', "geoflow-source-files-v1\0".implode("\0", $rawHashes));
        $documents = KnowledgeBase::query()->where('name', 'like', 'Aggregate source %')->orderBy('id')->get();

        $this->assertCount(2, $documents);
        $this->assertSame($expected, $documents[0]->source_file_hash);
        $this->assertSame($expected, $documents[1]->source_file_hash);
    }

    public function test_admin_create_uses_the_imported_corpus_identity_contract_and_rejects_a_collision(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $importedBody = "# Caf\u{00E9}\n\nLine  with internal spacing.\n";
        $importedHash = hash('sha256', $importedBody);
        $this->createDocument($scope, [
            'name' => 'Imported Corpus Document',
            'content' => $importedBody,
            'normalized_content_hash' => $importedHash,
            'asset_key' => 'knowledge:imported:normalization-contract',
            'asset_provenance' => [
                'namespace' => 'geoflow.governed-knowledge',
                'ingestion_method' => 'read_only_folder',
            ],
        ]);

        $this->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
                'name' => 'Colliding Content',
                'content' => "# Cafe\u{0301}\r\n\r\nLine  with internal spacing.\r\n\r\n",
            ]))
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('content');

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'Imported Corpus Document',
            'content' => $importedBody,
            'normalized_content_hash' => $importedHash,
        ]);
        $this->assertDatabaseMissing('knowledge_bases', ['name' => 'Colliding Content']);
    }

    public function test_admin_content_identity_preserves_internal_spacing_distinctions(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $this->createDocument($scope, [
            'name' => 'Imported Double Space',
            'content' => "Alpha  beta\n",
            'normalized_content_hash' => hash('sha256', "Alpha  beta\n"),
        ]);

        $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
            'name' => 'Admin Single Space',
            'content' => 'Alpha beta',
        ]))->assertRedirect(route('admin.knowledge-bases.index'));

        $created = KnowledgeBase::query()->where('name', 'Admin Single Space')->firstOrFail();
        $this->assertSame("Alpha beta\n", $created->content);
        $this->assertSame(hash('sha256', "Alpha beta\n"), $created->normalized_content_hash);
        $this->assertNotSame(hash('sha256', "Alpha  beta\n"), $created->normalized_content_hash);
    }

    public function test_no_op_edit_of_imported_content_preserves_its_identity_hash(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $normalizedBody = "# Caf\u{00E9}\n\nLine  with internal spacing.\n";
        $normalizedHash = hash('sha256', $normalizedBody);
        $imported = $this->createDocument($scope, [
            'name' => 'Imported No-op Identity',
            'content' => $normalizedBody,
            'normalized_content_hash' => $normalizedHash,
            'asset_key' => 'knowledge:imported:no-op-identity',
            'asset_provenance' => ['ingestion_method' => 'read_only_folder'],
        ]);

        $this->put(
            route('admin.knowledge-bases.update', ['knowledgeBaseId' => $imported->id]),
            $this->governedPayload($scope, [
                'name' => 'Imported No-op Identity',
                'content' => "# Cafe\u{0301}\r\n\r\nLine  with internal spacing.\r\n\r\n",
            ]),
        )->assertRedirect(route('admin.knowledge-bases.index'));

        $imported->refresh();
        $this->assertSame($normalizedBody, $imported->content);
        $this->assertSame($normalizedHash, $imported->normalized_content_hash);
    }

    public function test_edit_recomputes_normalized_hash_and_rejects_an_active_collision(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $canonical = $this->createDocument($scope, [
            'name' => 'Canonical Existing',
            'content' => "Canonical  existing body.\n",
            'normalized_content_hash' => hash('sha256', "Canonical  existing body.\n"),
        ]);
        $editable = $this->createDocument($scope, [
            'name' => 'Editable Existing',
            'content' => "Different editable body.\n",
            'normalized_content_hash' => hash('sha256', "Different editable body.\n"),
        ]);

        $this->from(route('admin.knowledge-bases.edit', ['knowledgeBaseId' => $editable->id]))
            ->put(
                route('admin.knowledge-bases.update', ['knowledgeBaseId' => $editable->id]),
                $this->governedPayload($scope, [
                    'name' => 'Would Collide',
                    'content' => "Canonical  existing body.\r\n\r\n",
                ]),
            )
            ->assertRedirect(route('admin.knowledge-bases.edit', ['knowledgeBaseId' => $editable->id]))
            ->assertSessionHasErrors('content');

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $editable->id,
            'name' => 'Editable Existing',
            'content' => "Different editable body.\n",
            'normalized_content_hash' => hash('sha256', "Different editable body.\n"),
        ]);
        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $canonical->id,
            'normalized_content_hash' => hash('sha256', "Canonical  existing body.\n"),
        ]);

        $this->put(
            route('admin.knowledge-bases.update', ['knowledgeBaseId' => $editable->id]),
            $this->governedPayload($scope, [
                'name' => 'Safely Updated',
                'content' => "Safely   updated\r\n\r\n\r\nbody.\r\n",
            ]),
        )->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $editable->id,
            'name' => 'Safely Updated',
            'content' => "Safely   updated\n\nbody.\n",
            'normalized_content_hash' => hash('sha256', "Safely   updated\n\nbody.\n"),
        ]);
    }

    public function test_detail_editor_recomputes_normalized_hash_and_rejects_an_active_collision(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $this->createDocument($scope, [
            'name' => 'Detail Canonical',
            'content' => "Detail  canonical body.\n",
            'normalized_content_hash' => hash('sha256', "Detail  canonical body.\n"),
        ]);
        $editable = $this->createDocument($scope, [
            'name' => 'Detail Editable',
            'content' => "Detail editable body.\n",
            'normalized_content_hash' => hash('sha256', "Detail editable body.\n"),
        ]);

        $detailRoute = route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $editable->id]);
        $this->from($detailRoute)
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $editable->id]), [
                'name' => 'Detail Collision',
                'description' => '',
                'content' => "Detail  canonical body.\r\n\r\n",
                'file_type' => 'markdown',
            ])
            ->assertRedirect($detailRoute)
            ->assertSessionHasErrors('content');

        $this->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $editable->id]), [
            'name' => 'Detail Safely Updated',
            'description' => '',
            'content' => "Detail   safe\r\n\r\n\r\nbody.\r\n",
            'file_type' => 'markdown',
        ])->assertRedirect($detailRoute);

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $editable->id,
            'name' => 'Detail Safely Updated',
            'content' => "Detail   safe\n\nbody.\n",
            'normalized_content_hash' => hash('sha256', "Detail   safe\n\nbody.\n"),
        ]);
    }

    public function test_both_edit_routes_reload_under_a_transaction_before_appending_operator_history(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');

        foreach (['form', 'detail'] as $routeKind) {
            $knowledgeBase = $this->createDocument($scope, [
                'name' => 'Concurrent provenance '.$routeKind,
                'content' => "Original {$routeKind} body.\n",
                'normalized_content_hash' => hash('sha256', "Original {$routeKind} body.\n"),
                'asset_provenance' => ['ingestion_method' => 'read_only_folder'],
            ]);
            $interleaved = false;
            $baselineTransactionLevel = DB::transactionLevel();
            KnowledgeBase::retrieved(function (KnowledgeBase $retrieved) use ($knowledgeBase, &$interleaved, $baselineTransactionLevel): void {
                if ($interleaved || (int) $retrieved->id !== (int) $knowledgeBase->id) {
                    return;
                }
                $interleaved = true;
                $provenance = (array) $retrieved->asset_provenance;
                $provenance['operator_edits'] = [[
                    'operation' => 'interleaved_operator_update',
                    'admin_id' => 999,
                    'edited_at' => '2026-08-20T00:00:00+00:00',
                ]];
                DB::table('knowledge_bases')->where('id', $retrieved->id)->update([
                    'asset_provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
                ]);

                if (DB::transactionLevel() > $baselineTransactionLevel) {
                    $retrieved->asset_provenance = $provenance;
                }
            });

            if ($routeKind === 'form') {
                $this->put(
                    route('admin.knowledge-bases.update', ['knowledgeBaseId' => $knowledgeBase->id]),
                    $this->governedPayload($scope, [
                        'name' => 'Concurrent provenance form',
                        'content' => 'Form route update body.',
                    ]),
                )->assertRedirect(route('admin.knowledge-bases.index'));
            } else {
                $this->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->id]), [
                    'name' => 'Concurrent provenance detail',
                    'description' => '',
                    'content' => 'Detail route update body.',
                    'file_type' => 'markdown',
                ])->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->id]));
            }

            $operations = collect((array) ($knowledgeBase->fresh()->asset_provenance['operator_edits'] ?? []))
                ->pluck('operation')
                ->all();
            $this->assertSame([
                'interleaved_operator_update',
                $routeKind === 'form' ? 'admin_form_update' : 'admin_detail_update',
            ], $operations);
        }
    }

    public function test_admin_language_is_canonicalized_with_knowledge_scope_and_round_trips_through_retrieval(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');

        $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
            'name' => 'Canonical Language Evidence',
            'content' => 'Canonical language retrieval evidence.',
            'language_code' => ' EN_us ',
            'generation_eligible' => '1',
        ]))->assertRedirect(route('admin.knowledge-bases.index'));

        $document = KnowledgeBase::query()->where('name', 'Canonical Language Evidence')->firstOrFail();
        $this->assertSame('en-US', $document->language_code);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Canonical language retrieval evidence.',
            'content_hash' => hash('sha256', 'canonical-language-evidence'),
            'chunk_title' => 'Canonical Language Evidence',
            'section_path' => 'Canonical Language Evidence',
            'metadata_json' => '{}',
            'embedding_json' => '[]',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidence(
            new KnowledgeScope((int) $scope['brand']->id, (int) $scope['site']->id, ' EN_us '),
            (int) $document->id,
            'canonical language retrieval',
        );

        $this->assertNotEmpty($evidence);
        $this->assertSame('en-US', $evidence[0]['metadata']['language_code'] ?? null);
    }

    public function test_admin_rejects_language_tags_that_knowledge_scope_rejects(): void
    {
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');

        foreach (['en-u', 'en-u-ca-u-nu', 'en--US'] as $index => $invalidLanguage) {
            $this->from(route('admin.knowledge-bases.create'))
                ->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
                    'name' => 'Malformed Language '.($index + 1),
                    'content' => 'Malformed language content '.($index + 1),
                    'language_code' => $invalidLanguage,
                ]))
                ->assertRedirect(route('admin.knowledge-bases.create'))
                ->assertSessionHasErrors('language_code');
        }

        $this->assertDatabaseCount('knowledge_bases', 0);
    }

    public function test_private_use_only_language_round_trips_through_admin_and_retrieval_scope(): void
    {
        Queue::fake();
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');

        $this->post(route('admin.knowledge-bases.store'), $this->governedPayload($scope, [
            'name' => 'Private Use Language Evidence',
            'content' => 'Private-use language retrieval evidence.',
            'language_code' => ' X_Foo ',
            'generation_eligible' => '1',
        ]))->assertRedirect(route('admin.knowledge-bases.index'));

        $document = KnowledgeBase::query()->where('name', 'Private Use Language Evidence')->firstOrFail();
        $this->assertSame('x-foo', $document->language_code);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Private-use language retrieval evidence.',
            'content_hash' => hash('sha256', 'private-use-language-evidence'),
            'chunk_title' => 'Private Use Language Evidence',
            'section_path' => 'Private Use Language Evidence',
            'metadata_json' => '{}',
            'embedding_json' => '[]',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidence(
            new KnowledgeScope((int) $scope['brand']->id, (int) $scope['site']->id, ' X_Foo '),
            (int) $document->id,
            'private use language retrieval',
        );

        $this->assertNotEmpty($evidence);
        $this->assertSame('x-foo', $evidence[0]['metadata']['language_code'] ?? null);
    }

    public function test_index_paginates_governed_documents_and_preserves_filters_and_tree_navigation(): void
    {
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        foreach (range(1, 21) as $number) {
            $this->createDocument($scope, [
                'name' => sprintf('Paginated Document %02d', $number),
                'content' => 'Unique paginated body '.$number,
                'canonical_document_key' => 'pagination-'.$number,
            ]);
        }

        $response = $this->get(route('admin.knowledge-bases.index', [
            'brand_id' => $scope['brand']->id,
            'language_code' => 'en',
            'page' => 2,
        ]));

        $response->assertOk()
            ->assertSee('Paginated Document 01')
            ->assertDontSee('Paginated Document 21')
            ->assertSee(route('admin.knowledge-bases.index', [
                'brand_id' => $scope['brand']->id,
                'language_code' => 'en',
                'directory_id' => $scope['directory']->id,
            ]));
        $paginator = $response->viewData('knowledgeBases');
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(20, $paginator->perPage());
        $this->assertStringContainsString('brand_id='.$scope['brand']->id, $paginator->url(1));
        $this->assertStringContainsString('language_code=en', $paginator->url(1));
    }

    public function test_index_warns_when_source_freshness_is_stale_or_review_is_overdue(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 20)->setTime(12, 0));
        $scope = $this->createOwnership('lightinthebox', 'LightInTheBox');
        $this->createDocument($scope, [
            'name' => 'Stale Retrieval Evidence',
            'retrieved_at' => '2025-08-19 00:00:00',
            'review_due_at' => '2026-12-01',
        ]);
        $this->createDocument($scope, [
            'name' => 'Overdue Review Evidence',
            'retrieved_at' => '2026-08-19 00:00:00',
            'review_due_at' => '2026-08-19',
        ]);

        $this->get(route('admin.knowledge-bases.index', ['brand_id' => $scope['brand']->id]))
            ->assertOk()
            ->assertSee('Stale Retrieval Evidence')
            ->assertSee(__('admin.knowledge_bases.badge_freshness_stale'))
            ->assertSee('Overdue Review Evidence')
            ->assertSee(__('admin.knowledge_bases.badge_review_overdue'));
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
