# Wigolo External Evidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable local-first evidence preparation with conditional external supplementation and traceable citations across quality-gated generation.
**Architecture:** A private Wigolo REST service supplies raw evidence. PHP owns coverage, source policy, immutable preparation snapshots and publish eligibility. Existing runs remain unchanged.
**Tech Stack:** PHP 8.3+, Laravel 12, database queue, PostgreSQL; SQLite tests; pinned Wigolo Node/Docker service.
**Spec:** `docs/superpowers/specs/2026-08-26-external-evidence-wigolo-design.md`

## Global Constraints

- 本地品牌知识足够则直接生成，外网请求为零。正文来源链接的存在不是重新抓取的理由。
- 非核心事实无法验证：删除该断言后继续生成，保留删除原因，不改成无根据的模糊肯定。
- 核心事实无法验证或存在未解决冲突：保存待处理草稿，不自动采纳、发布或同步 WordPress。
- No production publication, live migrations, paid generation or changes to existing Task/KB state as part of automated tests.
- No API credentials in code, reports or test output. Do not modify existing dirty preset seeder/tests or main docker-compose.yml.
- Existing runs are immutable; feature disabled by default in configuration. New quality-gated creation may select conditional mode but warn if service unavailable.
- External work outside DB transactions; reuse queue leases and idempotency; no unconsumed queue.
- Each task: write behavioral tests, observe RED, implement, observe GREEN, self-review and commit only owned files. Unit tests mock network/model boundary only.
- Run tests through `docker exec geoflow-app php artisan test --compact ...`; Tests/TestCase enforces SQLite memory.

## Task 1: REST adapter and source policy

**Files:** Create `config/external_evidence.php`, `app/Domain/ContentGeneration/ExternalEvidence/ExternalEvidenceProvider.php`, `WigoloRestClient.php`, `ExternalSourcePolicy.php`, `ExternalEvidenceException.php` in that directory; `tests/Unit/WigoloRestClientTest.php`, `tests/Unit/ExternalSourcePolicyTest.php`. Bind interface in `app/Providers/AppServiceProvider.php`.

**Interfaces:**
```php
interface ExternalEvidenceProvider {
    public function search(array $queries, array $options = []): array;
    public function fetch(string $url, array $options = []): array;
}
// search: ['status'=>'ok|failed|unavailable','results'=>list<array{url:string,title:string}>, 'warnings'=>list<string>]
// fetch: ['status'=>'fetched|blocked|failed|incomplete|unavailable','url'=>string,'final_url'=>?string,'title'=>string,'markdown'=>?string,'metadata'=>array,'error_code'=>?string]
// ExternalSourcePolicy::accepts(string $url, array $domains): bool
```

- [ ] RED: tests instantiate real adapter with fake HTTP/OutboundTransport and verify correct REST JSON/bearer, feature-off zero calls, missing config failure, no LLM answer mode, invalid/private URL rejection, exact/subdomain matching (not suffix collision), malformed JSON, 200 challenge, empty/truncated content, 401/429/5xx, timeout and redirects rejected without credential leakage.
```php
$this->assertFalse($policy->accepts('https://lightinthebox.com.evil.test/a', ['lightinthebox.com']));
$this->assertFalse($policy->accepts('http://127.0.0.1/a', []));
$this->assertSame('unavailable', $client->fetch('https://example.com/a')['status']);
```
- [ ] Run `docker exec geoflow-app php artisan test --compact tests/Unit/WigoloRestClientTest.php tests/Unit/ExternalSourcePolicyTest.php` and retain expected failure.
- [ ] Implement bounded authenticated POST `/v1/search` and `/v1/fetch`, documented upstream JSON shapes verified against official pinned source. Configuration `external_evidence.enabled=false`, `base_url`, `api_token`, `timeout_seconds=30`, `connect_timeout_seconds=5`, `max_response_bytes=2000000`, `max_results=5`, `max_fetches=3`, `max_pages=6`, `max_search_rounds=2`, `budget_seconds=180`, `max_tokens_out=5000`, `official_domains` keyed by brand ID, `general_domains` list. Internal service endpoint is admin-configured only; never follow redirects or disable global outbound safeguards. Validate public target URLs separately from internal service URL, including literal IPv6 and userinfo. Preserve upstream truncation/cache/failure metadata. Do not conflate search snippets with fetched Markdown; only schema-valid URL/title results become candidates. Never implement a generic arbitrary REST tool caller.
- [ ] GREEN run tests + `tests/Unit/ReferenceEvidenceFetcherTest.php`; format only owned files.
- [ ] Commit and report RED/GREEN, source contract/version used, security guarantees and limits. Browser egress isolation belongs to Task 6, so keep global enable off.

## Task 2: Coverage contracts, model assessment and citation rendering

**Files:** Create `ExternalEvidence/EvidenceCoverageAssessment.php`, `EvidenceCoverageAssessor.php` (interface), `ModelEvidenceCoverageAssessor.php`, `EvidenceCitationRenderer.php`; tests `tests/Unit/EvidenceCoverageAssessmentTest.php`, `tests/Unit/EvidenceCitationRendererTest.php`, `tests/Feature/ModelEvidenceCoverageAssessorTest.php`; bind assessor in AppServiceProvider.

**Interfaces:**
```php
interface EvidenceCoverageAssessor {
    public function assess(array $context, array $evidence, \App\Models\AiModel $model): EvidenceCoverageAssessment;
}
// EvidenceCoverageAssessment::fromArray(array $data, array $evidence, array $requiredIds=[]): self
// properties: claims (list), missingCore (list), missingOptional (list), supported (list)
// claim {id,question,core:bool,status:supported|missing|conflict,evidence_ids:[],quotes:[{evidence_id,text}],reason}
// Renderer::render(string $markdown, array $evidence): string
// citations use [[evidence:ID]], render validated references only.
```
- [ ] RED exact contracts: supported requires known evidence and verbatim nonempty quote; fabricated source IDs, core downgrades, invalid enums, duplicate claim IDs, malformed JSON rejected; optional missing returned separately. Citation renderer rejects unknown IDs/unsafe URLs, escapes labels, dedupes actual sources, does not cite unused evidence.
```php
$result = EvidenceCoverageAssessment::fromArray(['claims'=>[['id'=>'heat','question'=>'heat limit','core'=>false,'status'=>'missing','evidence_ids'=>[],'quotes'=>[],'reason'=>'not supplied']]], [], []);
$this->assertCount(1, $result->missingOptional);
```
- [ ] Run focused tests RED, then implement prompt + strict parser. Existing `ArticleContentGenerationService` and AiModel route model requests, reusing quota/usage accounting; do not create new credentials or synthesize with Wigolo. Context includes authoritative required IDs, core question and prior claim identities for reassessment. Protect instructions from evidence content. Assessor parser validates core requirements and stable claim identities on second pass; do not let the model erase a missing core claim. Bound model input and output with actual model context budget; include only compact evidence, not entire provenance.
- [ ] Renderer converts stable markers to safe links, appends only used external sources under `## Sources`; no model-produced arbitrary URLs allowed as source references. Local evidence without source URL may support a fact but cannot create a link. Keep rendering distinct from blueprint section validation.
- [ ] GREEN plus existing semantic quality reviewer tests; commit focused changes with red/green report.

## Task 3: Durable preparation and local-first orchestration

**Files:** migration `2026_08_26_000002_create_generation_evidence_preparations.php`; model `app/Models/GenerationEvidencePreparation.php`; services `ExternalEvidence/EvidencePreparationCoordinator.php`, `ExternalEvidence/ExternalEvidencePlanner.php`; tests `tests/Feature/EvidencePreparationCoordinatorTest.php`.

**Interfaces:**
```php
// prepare(ContentGenerationRun $run, array $claim): array
// ['state'=>'ready|draft_only','evidence'=>[],'prohibited_claims'=>[],'missing_core'=>[],'assessment'=>[]]
// snapshotInput(ContentBrief $brief,array $localEvidence,array $data): array
```
- [ ] RED SQLite tests: local support gives zero provider calls; known related URL fetch precedes search; wrong-brand domains never accepted; same URL deduped; optional failures yield ready with prohibition; missing core yields draft_only; malformed assessor output fails closed; sealed replay zero calls; lost lease cannot seal; two preparations for one run impossible.
```php
$this->assertSame('ready', $result['state']);
$this->assertSame([], $result['missing_core']);
$this->assertSame('draft_only', $missingCoreResult['state']);
```
- [ ] Migration unique run_id FK, frozen input_json, phase, result_json, sealed_at, lease identifiers and attempt audit; private original Markdown files with hash written atomically, no public disk. Existing tables unchanged in this task. No live migration.
- [ ] Implement assessment then bounded external calls outside transactions. Reuse original lease token/version from run. Persist attempt results incrementally to avoid repeating successful work on retry. Final short transaction checks lease + input digest + unsealed status before sealing. Deadline 180 seconds and six unique pages/two search rounds across retries; remaining timeout passed down; never silently restart budget.
- [ ] Planner uses only missing claim questions, explicitly relevant reference URLs and scoped source policy; no whole-KB send. Official domain list mandatory for brand facts; general domains explicit for general factual questions. Validate redirects/final URL again. Truncated/blocked/empty page cannot count as supporting a claim. Cache provenance retained; no global Wigolo cache query. Source scores never directly mark supported.
- [ ] GREEN focused tests, migration down/up using testing DB only; commit focused changes.

## Task 4: Wire all quality-gated entrypoints and draft-only guard

**Files:** migration `2026_08_26_000003_add_external_evidence_policy.php`; modify `app/Models/Task.php`, `ContentBrief.php`, `ContentGenerationRun.php`; `ContentGenerationRequestService.php`, `GenerationEvidenceSnapshotter.php`, `ContentGenerationCoordinator.php`, `GenerationAdoptionService.php`, `WorkerExecutionService.php`, `TaskLifecycleService.php`, `ContentBriefQuickCreateService.php`, `BulkContentImportService.php`, `AdoptTaskDrivenGenerationRunsCommand.php`; tests `tests/Feature/ExternalEvidenceGenerationFlowTest.php` and targeted existing flow tests.

- [ ] RED existing off runs unchanged; on_insufficient brand_auto with zero chunks can reserve/freeze preparation input; plain explicit user evidence still cannot bypass brand scope; references not fetched during reserve; run replays require matching policy fingerprint. Draft-only output remains run raw_output and cannot adopt, approve, publish or WordPress sync. No auto re-generation of a blocked title on scheduler tick.
```php
$this->assertSame('needs_evidence', $run->fresh()->status);
$this->assertNull($run->fresh()->article_id);
```
- [ ] Add task/brief policy default off for migrated records and corresponding casts/validation. Freeze policy in new run parameters. For enabled runs, allow empty local initial snapshot but do not mutate old snapshots; preparation required before normal compile. Final prepared evidence fed through a typed compiler context, not through user-submitted reviewed evidence path. Use existing generation lease and queue.
- [ ] For ready result preserve required-ID checks using explicit claim-to-evidence mapping; not fake IDs. For draft_only generate bounded partial draft from supported information without claiming core completion; set needs_evidence even if normal quality report passes. Noncore prohibited claims included in generation + semantic review, revise at most once, otherwise manual_review_required. No unconditional deletion of established required evidence constraints.
- [ ] All three entrances invoke same coordinator. Task counters only advance for actual adopted Article. Preserve task/title relation of blocked run to avoid infinite duplicate generation. Citation rendering shared by manual/auto adoption after body structural gates; fail closed on invalid IDs.
- [ ] GREEN focused and regression suite around request/dispatch/adopt/brand/import; commit focused changes.

## Task 5: Admin settings and preparation audit presentation

**Files:** task/brief/bulk controllers and form requests located by their current route definitions; Blade views under `resources/views/admin/tasks`, `content-briefs`, `bulk-content-import`, content generation run detail; translations `lang/en/admin.php`, `lang/zh_CN/admin.php` as existing project convention; tests `tests/Feature/ExternalEvidenceAdminTest.php`.

- [ ] RED new quality-gated forms select on_insufficient, editing old records preserves off; unavailable service warning shown; legacy engine rejects enabling; bulk default participates in grouping/idempotency; authorization protects audits; HTML in external excerpts escaped; no token printed.
```php
$response->assertSee('on_insufficient');
$response->assertDontSee('test-secret-token');
```
- [ ] Expose off/on_insufficient, immutable run phase/claim gaps/query URL attempts/omitted claims/usage; new forms warn if service not configured. Advanced controls enforce lower-or-equal deployment caps. Import takes one form-level policy, no mandatory Excel columns. Display needs_evidence as actionable with retry creating new run, not editing sealed snapshots. Do not open browser automatically.
- [ ] GREEN feature tests, route rendering smoke with test auth, commit focused changes.

## Task 6: Deployment, contract fixtures and end-to-end verification

**Files:** `docker-compose.external-evidence.yml`, `docker/external-evidence/` egress controls, `docs/operations/external-evidence-wigolo.md`, `tests/Feature/ExternalEvidenceEndToEndTest.php`, external contract fixture files in `tests/Fixtures/external-evidence/`.

- [ ] RED normalized compose validation for authenticated private Wigolo service, no host port, persistent private volume, memory/concurrency caps, version pin and egress isolation. Test blocked redirect/private browser subresource behavior in isolated fixture network; unknown/changed REST response fails closed. Do not weaken global SafeOutboundHttpClient to make service reachable.
- [ ] Implement optional override file so existing dirty compose remains untouched. Pin reviewed release/image digest from upstream. Core tools only, no cloud model provider. Controlled egress blocks private/metadata destinations for browser, HTTP, DNS/redirect paths; REST connection exception is exact configured internal endpoint only. Document AGPL and resource requirements. If verified egress unavailable, keep enabled=false and explicitly mark deployment incomplete rather than expose unsafe crawler.
- [ ] Run targeted suite, then full PHP suite once and compare preexisting baseline; `git diff --check` and scoped Pint. Run tiny authentic public fetch smoke only after service safely configured, not paid generation or publication. Record actual successes/failures; mock tests are not live crawling proof.
- [ ] Operations doc: setup/secrets/readiness, existing database worker, progress/error troubleshooting, rollback feature flag without deleting snapshots. Final independent review of complete change, fix and verify; commit only owned files. No push/merge without user request.

## Coverage and sequence

Tasks 1/6 implement service boundary and security; 2 implements claims and citations; 3 implements durable preparation; 4 implements lifecycle and publishing; 5 implements admin/bulk surfaces. All spec requirements map to these tasks. Shared AppServiceProvider edits are sequential. Task 4 only consumes Task 3 after its review. Task 5 runs after policy persistence exists. Task 6 is required before enabling live requests.
