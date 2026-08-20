<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\Task;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeScope;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class KnowledgeRetrievalIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_higher_scoring_foreign_brand_document_never_leaks_into_scoped_results(): void
    {
        $lightInTheBox = $this->createOwnership('lightinthebox');
        $foreign = $this->createOwnership('foreign-brand');
        $localDocument = $this->createDocument($lightInTheBox, [
            'name' => 'LightInTheBox returns policy',
            'content' => 'Returns are accepted under the current policy.',
        ]);
        $foreignDocument = $this->createDocument($foreign, [
            'name' => 'Foreign exact-match document',
            'content' => 'returns policy refund window returns policy refund window exact match',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $lightInTheBox['scope'],
            [(int) $foreignDocument->id, (int) $localDocument->id],
            'returns policy refund window',
            5,
        );

        $this->assertNotEmpty($evidence);
        $this->assertSame(
            [(int) $localDocument->id],
            collect($evidence)->pluck('metadata.knowledge_base_id')->unique()->values()->all(),
        );
        $this->assertStringNotContainsString('Foreign exact-match', json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    public function test_inactive_ineligible_and_wrong_language_documents_are_excluded_before_ranking(): void
    {
        $ownership = $this->createOwnership('lightinthebox');
        $eligible = $this->createDocument($ownership, [
            'name' => 'Eligible English policy',
            'content' => 'Current returns policy evidence.',
        ]);
        $inactive = $this->createDocument($ownership, [
            'name' => 'Superseded exact match',
            'content' => 'returns policy evidence exact match exact match',
            'lifecycle_status' => 'superseded',
        ]);
        $ineligible = $this->createDocument($ownership, [
            'name' => 'Generation-ineligible exact match',
            'content' => 'returns policy evidence exact match exact match',
            'generation_eligible' => false,
        ]);
        $wrongLanguage = $this->createDocument($ownership, [
            'name' => 'Portuguese exact match',
            'content' => 'returns policy evidence exact match exact match',
            'language_code' => 'pt',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $ownership['scope'],
            [$inactive->id, $ineligible->id, $wrongLanguage->id, $eligible->id],
            'returns policy evidence',
            10,
        );

        $this->assertSame(
            [(int) $eligible->id],
            collect($evidence)->pluck('metadata.knowledge_base_id')->unique()->values()->all(),
        );
    }

    public function test_current_first_party_policy_ranks_above_unreviewed_research_and_returns_provenance(): void
    {
        $ownership = $this->createOwnership('lightinthebox');
        $policyDirectory = $ownership['directory'];
        $researchDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $ownership['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research reference',
        ]);
        $research = $this->createDocument($ownership, [
            'name' => 'Unreviewed returns market research',
            'content' => 'returns policy refund window returns policy refund window exact match',
            'primary_directory_id' => $researchDirectory->id,
            'authority_level' => 'mixed_research',
            'review_status' => 'unreviewed',
            'risk_level' => 'medium',
            'citation_required' => true,
        ]);
        $policy = $this->createDocument($ownership, [
            'name' => 'Current first-party returns policy',
            'content' => 'The current return window is documented here.',
            'primary_directory_id' => $policyDirectory->id,
            'authority_level' => 'first_party',
            'review_status' => 'reviewed',
            'risk_level' => 'low',
            'source_url' => 'https://www.lightinthebox.com/returns-policy',
            'source_file_path' => '03_Company_Policies/Returns.md',
            'source_file_hash' => str_repeat('a', 64),
            'retrieved_at' => '2026-08-19 00:00:00',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $ownership['scope'],
            [$research->id, $policy->id],
            'returns policy refund window',
            5,
        );

        $this->assertSame((int) $policy->id, $evidence[0]['metadata']['knowledge_base_id'] ?? null);
        $this->assertSame('K1', $evidence[0]['citation_id'] ?? null);
        $this->assertSame('https://www.lightinthebox.com/returns-policy', $evidence[0]['provenance']['source_url'] ?? null);
        $this->assertSame('03_Company_Policies/Returns.md', $evidence[0]['provenance']['source_file_path'] ?? null);
        $this->assertSame(str_repeat('a', 64), $evidence[0]['provenance']['source_file_hash'] ?? null);
        $this->assertTrue($evidence[0]['provenance']['citation_required'] ?? false);
    }

    public function test_worker_knowledge_retrieval_fails_closed_for_an_unowned_legacy_document(): void
    {
        $legacyDocument = KnowledgeBase::query()->create([
            'name' => 'Legacy unowned worker knowledge',
            'content' => 'Unowned legacy content must not enter a worker prompt.',
            'lifecycle_status' => 'active',
            'generation_eligible' => true,
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $legacyDocument->id,
            'chunk_index' => 0,
            'content' => (string) $legacyDocument->content,
        ]);
        $task = new Task;
        $task->forceFill(['knowledge_base_id' => $legacyDocument->id]);

        $method = new ReflectionMethod(app(WorkerExecutionService::class), 'resolveKnowledgeContext');
        $method->setAccessible(true);

        $this->assertSame('', $method->invoke(
            app(WorkerExecutionService::class),
            $task,
            'Worker prompt title',
            'worker keyword',
        ));
    }

    public function test_optional_directory_scope_excludes_documents_from_other_directories(): void
    {
        $ownership = $this->createOwnership('directory-scoped-brand');
        $otherDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $ownership['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research reference',
        ]);
        $policy = $this->createDocument($ownership, [
            'name' => 'Scoped policy',
            'content' => 'returns evidence policy',
        ]);
        $research = $this->createDocument($ownership, [
            'name' => 'Other directory research',
            'content' => 'returns evidence policy exact exact',
            'primary_directory_id' => $otherDirectory->id,
        ]);
        $scope = new KnowledgeScope(
            (int) $ownership['brand']->id,
            (int) $ownership['site']->id,
            'en',
            (int) $ownership['directory']->id,
        );

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $scope,
            [$research->id, $policy->id],
            'returns evidence policy',
            5,
        );

        $this->assertSame(
            [(int) $policy->id],
            collect($evidence)->pluck('metadata.knowledge_base_id')->unique()->values()->all(),
        );
    }

    public function test_optional_keyword_cluster_scope_excludes_unassociated_documents(): void
    {
        $ownership = $this->createOwnership('cluster-scoped-brand');
        $associated = $this->createDocument($ownership, [
            'name' => 'Cluster-associated policy',
            'content' => 'wedding dress returns evidence',
        ]);
        $unassociated = $this->createDocument($ownership, [
            'name' => 'Unassociated exact match',
            'content' => 'wedding dress returns evidence exact exact',
        ]);
        $cluster = $this->createKeywordCluster();
        $associated->keywordClusters()->attach($cluster->id, [
            'confidence' => 1,
            'source' => 'reviewed',
            'is_primary' => true,
        ]);
        $scope = new KnowledgeScope(
            (int) $ownership['brand']->id,
            (int) $ownership['site']->id,
            'en',
            null,
            (int) $cluster->id,
        );

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $scope,
            [$unassociated->id, $associated->id],
            'wedding dress returns evidence',
            5,
        );

        $this->assertSame(
            [(int) $associated->id],
            collect($evidence)->pluck('metadata.knowledge_base_id')->unique()->values()->all(),
        );
    }

    public function test_chunk_metadata_cannot_override_sql_verified_ownership_and_lifecycle_provenance(): void
    {
        $ownership = $this->createOwnership('metadata-integrity-brand');
        $document = $this->createDocument($ownership, [
            'name' => 'Verified ownership evidence',
            'description' => 'Authoritative document description',
            'content' => 'Verified ownership evidence content.',
            'source_name' => 'Authoritative policy source',
            'source_url' => 'https://metadata-integrity-brand.test/policy',
            'source_type' => 'policy',
            'business_line' => 'Authoritative business line',
            'effective_date' => '2026-06-01',
            'retrieved_at' => '2026-08-19 12:00:00',
            'review_due_at' => '2027-06-01',
            'version_label' => 'official-v2',
            'source_file_path' => 'policy/authoritative.md',
            'source_file_hash' => str_repeat('a', 64),
        ]);
        $document->chunks()->update([
            'metadata_json' => json_encode([
                'knowledge_base_id' => 999999,
                'knowledge_base_name' => 'Forged document',
                'knowledge_base_description' => 'Forged description',
                'brand_id' => 999999,
                'site_id' => 999999,
                'primary_directory_id' => 999999,
                'directory_key' => 'forged-directory',
                'directory_path' => 'forged/directory',
                'canonical_document_key' => 'forged-canonical-key',
                'language_code' => 'pt',
                'lifecycle_status' => 'superseded',
                'generation_eligible' => false,
                'authority_level' => 'third_party',
                'citation_required' => false,
                'source_name' => 'Forged source',
                'source_url' => 'https://attacker.test/forged',
                'source_type' => 'research',
                'business_line' => 'Forged business line',
                'effective_date' => '2099-01-01',
                'retrieved_at' => '2099-01-01T00:00:00+00:00',
                'review_due_at' => '2099-01-01',
                'version_label' => 'forged-v99',
                'risk_level' => 'high',
                'review_status' => 'unreviewed',
                'source_file_path' => 'forged.md',
                'source_file_hash' => str_repeat('f', 64),
            ], JSON_THROW_ON_ERROR),
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidence(
            $ownership['scope'],
            (int) $document->id,
            'verified ownership evidence',
            1,
        );

        $this->assertSame((int) $document->id, $evidence[0]['metadata']['knowledge_base_id'] ?? null);
        $this->assertSame('Verified ownership evidence', $evidence[0]['metadata']['knowledge_base_name'] ?? null);
        $this->assertSame('Authoritative document description', $evidence[0]['metadata']['knowledge_base_description'] ?? null);
        $this->assertSame((int) $ownership['brand']->id, $evidence[0]['metadata']['brand_id'] ?? null);
        $this->assertSame((int) $ownership['site']->id, $evidence[0]['metadata']['site_id'] ?? null);
        $this->assertSame((int) $ownership['directory']->id, $evidence[0]['metadata']['primary_directory_id'] ?? null);
        $this->assertSame('policy-compliance', $evidence[0]['metadata']['directory_key'] ?? null);
        $this->assertSame('policy-compliance', $evidence[0]['metadata']['directory_path'] ?? null);
        $this->assertSame((string) $document->canonical_document_key, $evidence[0]['metadata']['canonical_document_key'] ?? null);
        $this->assertSame('en', $evidence[0]['metadata']['language_code'] ?? null);
        $this->assertSame('active', $evidence[0]['metadata']['lifecycle_status'] ?? null);
        $this->assertTrue($evidence[0]['metadata']['generation_eligible'] ?? false);
        $this->assertSame('first_party', $evidence[0]['metadata']['authority_level'] ?? null);
        $this->assertSame('Authoritative policy source', $evidence[0]['metadata']['source_name'] ?? null);
        $this->assertSame('https://metadata-integrity-brand.test/policy', $evidence[0]['metadata']['source_url'] ?? null);
        $this->assertSame('policy', $evidence[0]['metadata']['source_type'] ?? null);
        $this->assertSame('Authoritative business line', $evidence[0]['metadata']['business_line'] ?? null);
        $this->assertSame('2026-06-01', $evidence[0]['metadata']['effective_date'] ?? null);
        $this->assertStringStartsWith('2026-08-19T12:00:00', (string) ($evidence[0]['metadata']['retrieved_at'] ?? ''));
        $this->assertSame('2027-06-01', $evidence[0]['metadata']['review_due_at'] ?? null);
        $this->assertSame('official-v2', $evidence[0]['metadata']['version_label'] ?? null);
        $this->assertSame('low', $evidence[0]['metadata']['risk_level'] ?? null);
        $this->assertSame('reviewed', $evidence[0]['metadata']['review_status'] ?? null);
        $this->assertTrue($evidence[0]['metadata']['citation_required'] ?? false);
        $this->assertSame('policy/authoritative.md', $evidence[0]['metadata']['source_file_path'] ?? null);
        $this->assertSame(str_repeat('a', 64), $evidence[0]['metadata']['source_file_hash'] ?? null);
        $this->assertSame((int) $document->id, $evidence[0]['provenance']['knowledge_base_id'] ?? null);
        $this->assertSame((string) $document->canonical_document_key, $evidence[0]['provenance']['canonical_document_key'] ?? null);
        $this->assertSame('Authoritative policy source', $evidence[0]['provenance']['source_name'] ?? null);
        $this->assertSame('https://metadata-integrity-brand.test/policy', $evidence[0]['provenance']['source_url'] ?? null);
        $this->assertSame('policy', $evidence[0]['provenance']['source_type'] ?? null);
        $this->assertSame('2026-06-01', $evidence[0]['provenance']['effective_date'] ?? null);
        $this->assertStringStartsWith('2026-08-19T12:00:00', (string) ($evidence[0]['provenance']['retrieved_at'] ?? ''));
        $this->assertSame('first_party', $evidence[0]['provenance']['authority_level'] ?? null);
        $this->assertTrue($evidence[0]['provenance']['citation_required'] ?? false);
        $this->assertSame('policy/authoritative.md', $evidence[0]['provenance']['source_file_path'] ?? null);
        $this->assertSame(str_repeat('a', 64), $evidence[0]['provenance']['source_file_hash'] ?? null);
    }

    public function test_chunk_metadata_cannot_disguise_an_unreviewed_high_risk_document(): void
    {
        $ownership = $this->createOwnership('high-risk-integrity-brand');
        $document = $this->createDocument($ownership, [
            'name' => 'Unreviewed high-risk policy',
            'content' => 'This high-risk evidence must remain excluded.',
            'risk_level' => 'high',
            'review_status' => 'unreviewed',
        ]);
        $document->chunks()->update([
            'metadata_json' => json_encode([
                'risk_level' => 'low',
                'review_status' => 'reviewed',
                'authority_level' => 'first_party',
                'source_name' => 'Forged safe source',
                'effective_date' => '2099-01-01',
            ], JSON_THROW_ON_ERROR),
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidence(
            $ownership['scope'],
            (int) $document->id,
            'high-risk evidence',
            1,
        );

        $this->assertSame([], $evidence);
    }

    public function test_same_title_and_section_from_unrelated_documents_are_not_collapsed(): void
    {
        $ownership = $this->createOwnership('unrelated-same-topic-brand');
        $researchDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $ownership['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research reference',
        ]);
        $policy = $this->createDocument($ownership, [
            'name' => 'Returns guidance',
            'content' => 'Official policy guidance for returns.',
            'canonical_document_key' => 'returns-official-policy',
            'effective_date' => '2025-01-01',
        ]);
        $research = $this->createDocument($ownership, [
            'name' => 'Returns guidance',
            'content' => 'Newer market research guidance for returns.',
            'canonical_document_key' => 'returns-market-research',
            'primary_directory_id' => $researchDirectory->id,
            'authority_level' => 'mixed_research',
            'effective_date' => '2026-08-01',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $ownership['scope'],
            [(int) $research->id, (int) $policy->id],
            'returns guidance',
            5,
        );

        $this->assertSame(
            [(int) $policy->id, (int) $research->id],
            collect($evidence)->pluck('metadata.knowledge_base_id')->all(),
        );
        $this->assertSame([0, 0], collect($evidence)->pluck('conflict_merged_count')->all());
    }

    public function test_canonical_versions_use_policy_authority_before_research_freshness(): void
    {
        $ownership = $this->createOwnership('canonical-version-brand');
        $researchDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $ownership['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research reference',
        ]);
        $policy = $this->createDocument($ownership, [
            'name' => 'Returns guidance',
            'content' => 'Official policy version of returns guidance.',
            'canonical_document_key' => 'returns-guidance',
            'effective_date' => '2025-01-01',
        ]);
        $research = $this->createDocument($ownership, [
            'name' => 'Returns guidance',
            'content' => 'Newer research version of returns guidance.',
            'canonical_document_key' => 'returns-guidance',
            'primary_directory_id' => $researchDirectory->id,
            'authority_level' => 'mixed_research',
            'effective_date' => '2026-08-01',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $ownership['scope'],
            [(int) $research->id, (int) $policy->id],
            'returns guidance',
            5,
        );

        $this->assertCount(1, $evidence);
        $this->assertSame((int) $policy->id, $evidence[0]['metadata']['knowledge_base_id'] ?? null);
        $this->assertSame(1, $evidence[0]['conflict_merged_count'] ?? null);
    }

    public function test_canonical_version_resolution_keeps_all_relevant_chunks_from_the_winning_policy_document(): void
    {
        $ownership = $this->createOwnership('canonical-version-siblings-brand');
        $researchDirectory = KnowledgeDirectory::query()->create([
            'brand_id' => $ownership['brand']->id,
            'key' => 'research-reference',
            'path' => 'research-reference',
            'name' => 'Research reference',
        ]);
        $policy = $this->createDocument($ownership, [
            'name' => 'Returns guidance',
            'content' => 'Official returns policy covers the return window.',
            'canonical_document_key' => 'returns-guidance-siblings',
            'effective_date' => '2025-01-01',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $policy->id,
            'chunk_index' => 1,
            'content' => 'Official returns policy also covers refund processing.',
            'content_hash' => hash('sha256', 'returns-guidance-policy-sibling'),
            'chunk_title' => 'Returns guidance',
            'section_path' => 'Returns guidance',
            'metadata_json' => '{}',
            'embedding_json' => '[]',
        ]);
        $research = $this->createDocument($ownership, [
            'name' => 'Returns guidance',
            'content' => 'Newer market research discusses returns and refunds.',
            'canonical_document_key' => 'returns-guidance-siblings',
            'primary_directory_id' => $researchDirectory->id,
            'authority_level' => 'mixed_research',
            'effective_date' => '2026-08-01',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidenceFromMany(
            $ownership['scope'],
            [(int) $research->id, (int) $policy->id],
            'returns policy refund',
            5,
        );

        $this->assertCount(2, $evidence);
        $this->assertSame([(int) $policy->id, (int) $policy->id], collect($evidence)->pluck('metadata.knowledge_base_id')->all());
        $this->assertSame([1, 0], collect($evidence)->pluck('chunk_index')->all());
        $this->assertSame(['K1', 'K2'], collect($evidence)->pluck('citation_id')->all());
        $this->assertStringNotContainsString('Newer market research', json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    public function test_scope_trims_and_canonicalizes_bcp47_compatible_language_tags(): void
    {
        $scope = new KnowledgeScope(10, 20, '  ZH_hANS_cn  ');

        $this->assertSame('zh-Hans-CN', $scope->languageCode);
    }

    #[DataProvider('languageTagsWithExtensions')]
    public function test_scope_canonicalizes_bcp47_extensions_and_private_use_contextually(string $input, string $expected): void
    {
        $scope = new KnowledgeScope(10, 20, $input);

        $this->assertSame($expected, $scope->languageCode);
    }

    /** @return iterable<string,array{string,string}> */
    public static function languageTagsWithExtensions(): iterable
    {
        yield 'unicode extension' => [' EN-U-CA-GREGORY ', 'en-u-ca-gregory'];
        yield 'script region and extension' => ['zh-hant-cn-U-NU-HANIDEC', 'zh-Hant-CN-u-nu-hanidec'];
        yield 'language private use' => ['en-X-Internal-42', 'en-x-internal-42'];
        yield 'private use only' => ['X-Internal-42', 'x-internal-42'];
        yield 'multiple extensions' => ['de-A-FOO-U-CA-GREGORY', 'de-a-foo-u-ca-gregory'];
    }

    #[DataProvider('invalidLanguageTags')]
    public function test_scope_rejects_invalid_language_tags(string $languageCode): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KnowledgeScope(10, 20, $languageCode);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLanguageTags(): iterable
    {
        yield 'empty' => [''];
        yield 'one-letter language' => ['e'];
        yield 'long language name' => ['english'];
        yield 'empty subtag' => ['en--US'];
        yield 'invalid punctuation' => ['en-@US'];
        yield 'numeric primary language' => ['123-US'];
        yield 'overlong subtag' => ['en-abcdefghi'];
        yield 'incomplete unicode extension' => ['en-u'];
        yield 'incomplete generic extension' => ['en-a'];
        yield 'extension without payload before private use' => ['en-u-x-private'];
        yield 'extension without payload before another extension' => ['en-u-a-foo'];
        yield 'incomplete language private use' => ['en-x'];
        yield 'incomplete private use only' => ['x'];
        yield 'duplicate extension singleton' => ['en-u-ca-u-nu'];
    }

    /**
     * @return array{brand:KnowledgeBrand,site:KnowledgeSite,directory:KnowledgeDirectory,scope:KnowledgeScope}
     */
    private function createOwnership(string $key): array
    {
        $brand = KnowledgeBrand::query()->create([
            'key' => $key,
            'name' => str($key)->headline()->toString(),
        ]);
        $site = KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => $key.'-main',
            'canonical_host' => $key.'.test',
            'role' => 'commerce-primary',
        ]);
        $directory = KnowledgeDirectory::query()->create([
            'brand_id' => $brand->id,
            'key' => 'policy-compliance',
            'path' => 'policy-compliance',
            'name' => 'Policy compliance',
        ]);

        return [
            'brand' => $brand,
            'site' => $site,
            'directory' => $directory,
            'scope' => new KnowledgeScope((int) $brand->id, (int) $site->id, 'en'),
        ];
    }

    /**
     * @param  array{brand:KnowledgeBrand,site:KnowledgeSite,directory:KnowledgeDirectory,scope:KnowledgeScope}  $ownership
     * @param  array<string,mixed>  $overrides
     */
    private function createDocument(array $ownership, array $overrides): KnowledgeBase
    {
        $content = (string) ($overrides['content'] ?? 'Governed knowledge content.');
        $document = KnowledgeBase::query()->create(array_merge([
            'name' => 'Governed document',
            'description' => '',
            'content' => $content,
            'character_count' => mb_strlen($content),
            'file_type' => 'markdown',
            'word_count' => 4,
            'brand_id' => $ownership['brand']->id,
            'site_id' => $ownership['site']->id,
            'primary_directory_id' => $ownership['directory']->id,
            'canonical_document_key' => str()->uuid()->toString(),
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'lifecycle_status' => 'active',
            'generation_eligible' => true,
            'citation_required' => true,
            'source_name' => 'Governed source',
            'source_url' => 'https://example.test/source',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ], $overrides));

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $document->id,
            'chunk_index' => 0,
            'content' => (string) $document->content,
            'content_hash' => hash('sha256', (string) $document->content.$document->id),
            'chunk_title' => (string) $document->name,
            'section_path' => (string) $document->name,
            'metadata_json' => '{}',
            'embedding_json' => '[]',
        ]);

        return $document;
    }

    private function createKeywordCluster(): KeywordCluster
    {
        $library = KeywordLibrary::query()->create([
            'name' => 'Retrieval cluster library',
            'description' => '',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'wedding dress returns',
            'normalized_keyword' => 'wedding dress returns',
            'language_code' => 'en',
            'market_scope' => 'GLOBAL',
            'keyword_type' => 'category',
            'search_intent' => 'informational',
            'brand_relation' => 'non_brand',
            'status' => 'active',
        ]);
        $taxonomy = KeywordTaxonomy::query()->create([
            'name' => 'Retrieval taxonomy',
            'slug' => 'retrieval-taxonomy-'.str()->random(8),
        ]);
        $node = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'name' => 'Returns',
            'slug' => 'returns',
            'path' => 'returns',
            'depth' => 0,
        ]);

        return KeywordCluster::query()->create([
            'name' => 'Wedding dress returns',
            'slug' => 'wedding-dress-returns-'.str()->random(8),
            'taxonomy_node_id' => $node->id,
            'primary_keyword_id' => $keyword->id,
            'language_code' => 'en',
            'market_scope' => 'GLOBAL',
            'search_intent' => 'informational',
        ]);
    }
}
