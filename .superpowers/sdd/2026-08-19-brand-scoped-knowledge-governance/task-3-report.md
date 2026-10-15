# Task 3 Report — Keyword Taxonomy Associations

## Delivered

- Added `knowledge_keyword_nodes` and `knowledge_keyword_clusters` pivot tables with unique document-target pairs, reverse lookup indexes, confidence, source, primary flag, timestamps, and cascading foreign keys.
- Added pivot models plus forward and reverse Eloquent relationships between knowledge documents, keyword taxonomy nodes, and keyword clusters.
- Added `KnowledgeKeywordAssociationService::associate()`.
  - Locks the document, requested keyword library, candidate nodes, clusters, and cluster primary keywords in one transaction.
  - Treats a node as library-owned only when all of its linked keywords belong to the requested library.
  - Treats a cluster as library-owned through its primary keyword's library.
  - Validates all targets before syncing either association type, so rejected calls preserve existing associations and create no partial rows.
- Added fixture-driven tests. The LightInTheBox fixture explicitly creates library ID 42, so the tests do not rely on any live database row.

## Verification

```text
docker compose exec -T app php artisan test tests/Feature/KnowledgeKeywordAssociationTest.php tests/Feature/KeywordClusterServiceTest.php --compact

24 passed (95 assertions)
```

Pint and `git diff --check` also completed without errors.

## Fix Round 1 Verification

Added regression coverage for zero-keyword nodes and nodes linked to both the requested and a foreign library. Each starts with valid node and cluster associations and confirms that both pivot sets remain unchanged after rejection. The suite also now documents `sync` complete-set behavior: an empty set clears only that target type, omitted IDs are removed on replacement, and duplicate IDs do not create duplicate pivots.

```text
docker compose exec -T app php artisan test tests/Feature/KnowledgeKeywordAssociationTest.php tests/Feature/KeywordClusterServiceTest.php --compact

Tests:    28 passed (117 assertions)
Duration: 1.20s
```

## Scope Notes

- The service intentionally accepts the library ID as an argument; it does not hard-code ID 42. Production callers pass LightInTheBox's formal library ID (42), while tests create their own deterministic ID-42 fixture.
- No changes were made to the unrelated working-tree edits present before this task.
