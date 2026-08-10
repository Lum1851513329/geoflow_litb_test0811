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

## Root review repair round 1

- Added atomic claim fencing with an opaque `claim_token`, monotonic `lease_version`, and expiring lease. Step completion/failure, reports, run output, terminal transitions, and events now require the same current claim; reclaimed stale workers cannot overwrite output, append a revision, fail the winner, or emit a failure event.
- Added a partial unique index enforcing at most one `targeted_revision` step per run. Two interleaving-worker tests cover both a stale provider response and a stale provider exception after a second worker reclaims and completes the run.
- Semantic attempt ordinals are persisted in step input snapshots and recovered from the ledger. Completed durable semantic responses are parsed without another provider call, and the two-attempt lifetime cap survives crashes and resumptions.
- Snapshot migration rollback keeps `compiled_prompt` nullable. A down/up round trip retains `NULL`, permits one complete snapshot initialization, then rejects later mutation.
- Model snapshots now contain a secret-safe configuration fingerprint, revalidated immediately before every provider call. A configuration mutation between initial generation and semantic review fails before a second provider call. Complete generation requirements are stored in the immutable variables snapshot; retry behavior is unchanged by later brief locale/mention edits.
- A successful targeted revision becomes the run's current `raw_output`; the original model response remains on the initial-generation step.
- `ContentGenerationFailed` is emitted only when the current claim actually transitions the run to `failed`; evidence and manual-review terminal outcomes use completion signaling.
- Claim-acquisition database failures propagate to the queue instead of returning a pending run as apparent success; a one-shot query-failure regression covers this retry boundary.
- Focused coordinator suite: `25 passed, 126 assertions`.
- Task 1-6 compatibility plus legacy worker prompt suite: `110 passed, 324 assertions` (`135 passed, 450 assertions` combined with the focused suite).
- Pint: 5 scoped files clean. `git diff --check`: clean.
- PostgreSQL disposable database: full migration, rollback of `010310` and `010300`, and reapply all succeeded. The disposable `geoflow_task7_round1` database was dropped afterward.
- Independent repair re-review: `Ready: yes`; focused suite independently confirmed at `25 passed, 126 assertions`.
