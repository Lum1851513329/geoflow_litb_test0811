# Task 5 Report: Duplicate Resolver and Semantic Chunk Preview

## Delivered

- Added deterministic exact duplicate resolution scoped to brand, language, and directory family. Canonical precedence is Markdown, URL-bearing provenance, provenance completeness, newer lifecycle date, then lexical path. Every discarded source is retained as an audit record with `duplicate_of` and a reason.
- Added near-duplicate reporting with paragraph SHA-256 fingerprints and token-set similarity. Candidate pairs never cross brand, language, or directory family. Only strict paragraph subsets with unambiguous non-lexical provenance precedence are marked for merge; all other qualifying pairs require review.
- Added heading-aware preview chunks that preserve section paths, split paragraphs and list items to the requested token range where source structure permits, and copy ownership, lifecycle, source, authority, risk, and review metadata into every chunk.
- Extended PHP knowledge chunk sync metadata with brand/site/directory identity, language, authority, lifecycle, provenance hashes, retrieval/review dates, and eligibility controls.

## Tests and verification

- `python3 -m unittest discover -s tools/knowledge_corpus/tests -v` — 22 passed.
- `docker compose exec -T app php artisan test tests/Feature/KnowledgeChunkGovernanceMetadataTest.php tests/Feature/KnowledgeChunkAsyncSyncTest.php tests/Feature/KnowledgeChunkEmbeddingSyncTest.php` — 38 passed, 201 assertions.
- PHP lint passed for the changed service and feature test.
- `git diff --check` passed.

## Self-review

- Verified exact duplicate precedence and audit mapping, uncertain near-duplicate review behavior, strict-subset merge criteria, scope isolation, heading paths, list boundaries, document isolation, and PHP metadata propagation.
- No unresolved Task 5 concerns. The host shell has no PHP binary, so PHP checks were run in the repository's `app` Docker service.

## Fix round 1 evidence (2026-08-19)

- Exact duplicates are now scoped only by brand and language; near-duplicate candidates remain brand/language/directory-family scoped.
- A strict-subset candidate is merged only when the deterministic provenance winner is the content superset. A stronger-provenance subset is retained with a `review` record so unique content cannot be discarded.
- Exact precedence now explicitly ranks Markdown, first-party URL-bearing sources, provenance completeness, UTC-normalized lifecycle dates, and lexical paths.
- Chunk packing now fills up to the maximum before flushing, rebalances feasible undersized tails, splits oversized prose/list units at safe boundaries, keeps sibling heading paths separate, and counts CJK characters conservatively.
- Near comparison computes paragraph fingerprints and token sets once per document before pairing.
- `python3 -m unittest discover -s tools/knowledge_corpus/tests -v` — 30 passed.
- `docker compose exec -T app php artisan test tests/Feature/KnowledgeChunkGovernanceMetadataTest.php tests/Feature/KnowledgeChunkAsyncSyncTest.php tests/Feature/KnowledgeChunkEmbeddingSyncTest.php` — 38 passed, 201 assertions (49.68s; captured from the container because the interactive command exceeded the local 30-second tool window).

## Fix round 2 evidence (2026-08-19)

- Oversized units are now deterministically partitioned into balanced token groups before packing. A splittable ~800-token paragraph produces two chunks, each within the 350–700 target range.
- Mixed Latin/CJK units are segmented token-by-token while retaining source order and all non-whitespace content; no output chunk exceeds the maximum or contains only a heading.
- `python3 -m unittest discover -s tools/knowledge_corpus/tests -v` — 32 passed.
- PHP was unchanged in this round, so the focused PHP suite was not rerun.
