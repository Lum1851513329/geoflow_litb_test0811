# Task 7 Report: Traceable Generation Coordinator

## Implementation state

- Feature commit: `586ba0c` (`feat: add traceable quality-gated generation`)
- State flow: `pending|queued -> compiling -> generating -> ready_for_review` on success.
- Quality exits: `needs_evidence` when effective compiled evidence is absent or semantic review requires support; `manual_review_required` after malformed semantic review (one parse retry) or a failed post-revision gate; `failed` for sanitized provider/empty-output/preparation failures.
- A failed or stale pre-revision run resumes from its immutable compiled/module/model/evidence snapshots. Any existing targeted-revision step prevents another revision and routes recovery to manual review.

## Transaction and model-call boundary

- The run row is locked while exact module versions are resolved, the prompt is compiled, and module/context-digest/model/generation snapshots are written.
- The forward migration permits only the initial `NULL -> value` snapshot assignment while a run is `pending|queued`; later snapshot mutation remains database-rejected. SQLite update/delete guards survive upgrade and rollback.
- Every provider interaction first persists a `running` step with prompt and input snapshot. The provider is invoked only after the coordinator transaction commits.
- An ambient database transaction is rejected before the provider call, preventing a call against snapshots that exist only in a savepoint.
- Completion/failure events implement `ShouldDispatchAfterCommit`; listener exceptions cannot roll back the saved run.

## Idempotency and queue recovery

- The database unique `idempotency_key` is also the unique queue-job identity.
- Re-delivery of a terminal run is a no-op and performs no additional provider call.
- The job has three attempts. A fresh in-progress claim is re-released for 1205 seconds; the lease becomes reclaimable after 1200 seconds.
- Retriable failed/stale runs reuse the original immutable snapshots and completed initial output when available.
- The revision-step existence check is the hard one-revision cap across retries and crashes.

## Semantic JSON contract

- Response must be one bare JSON object with exactly `passed`, `score`, and `issues`; key order is irrelevant.
- `passed` is boolean, `score` is finite numeric `0..100`, and `issues` is a list of exact objects containing `code`, `section_key`, `message`, and `repair_instruction`.
- Passed reviews require no issues; failed reviews require at least one actionable issue.
- Semantic prompts contain the blueprint, article, and effective post-budget evidence snapshot. The explicit evidence codes are `needs_evidence`, `unsupported_claim`, and `unsupported_fact`.
- Invalid semantic JSON is called once more, then routes to manual review. Only unresolved `repair_instruction` values enter the targeted revision prompt.

## Verification

- RED checks observed for the final-service fake seam, missing coordinator, missing semantic evidence, unordered JSON keys, idempotency-key job identity, ambient transaction calls, failed-run retry, and queue re-release recovery.
- Focused coordinator suite: `17 passed, 85 assertions`.
- Task 1-6 compatibility plus legacy worker prompt suite: `127 passed, 409 assertions`.
- Pint: 10 scoped files clean.
- `git diff --check`: clean.
- PostgreSQL disposable database: full migration, Task 7 rollback, and Task 7 reapply all succeeded; the disposable database was dropped afterward.
- Independent read-only re-review verdict: `Ready: yes`.

## Concerns

- The existing `ArticleContentGenerationService` has no provider-side idempotency token. A hard process death after the provider accepted an initial request but before its step is completed can therefore repeat that initial call after lease expiry. Database state remains idempotent, and targeted revisions remain capped at one, but provider-level exactly-once delivery is not claimable without extending the upstream service contract.
- The unrelated local `docker-compose.yml` modification was preserved and excluded from both commits.
