# Brand-Scoped Knowledge Governance Design

Date: 2026-08-19  
Status: approved in principle; awaiting written-spec review  
Initial tenant: LightInTheBox / lightinthebox.com

## 1. Purpose

Replace the current flat, duplicated knowledge collection with a governed knowledge system that:

- isolates every brand and publishing site;
- preserves source provenance and time sensitivity;
- removes exact duplicates and audits near duplicates;
- separates authoritative facts from research references;
- produces retrieval-friendly semantic chunks;
- connects knowledge to the governed keyword taxonomy without treating keywords as prose;
- supports file/Excel imports now and store/API synchronization later.

The first migration covers LightInTheBox and English content only. The design retains language metadata so additional languages can be enabled later without changing the isolation model.

## 2. Current-State Findings

- The source directory contains 92 Markdown files, 183 TXT files, one JSON file, and two `.DS_Store` files (about 1.9 MB).
- `LightInTheBox_PT_Official_KB` and `LightInTheBox_PT_Official_KB_TXT` contain format mirrors.
- GEOFlow currently contains 156 knowledge bases and 1,254 chunks.
- There are 72 groups of byte-equivalent knowledge-base bodies in the database.
- Some Portuguese sources were re-imported with English labels.
- `knowledge_bases` has no brand, site, language, or directory-node ownership fields.
- The research reports mix official filings with third-party sources and are correctly treated as higher-risk than first-party policy pages.

## 3. Scope

### Included

- Brand/site/directory data model.
- Source-document governance metadata.
- Exact and near-duplicate detection.
- Deterministic normalization and semantic heading-based chunking.
- Local cleaned corpus, manifest, duplicate report, and import package.
- Transactional migration of current LightInTheBox knowledge.
- Keyword-taxonomy association to keyword library ID 42.
- Admin browsing/filtering by brand, site, language, directory, authority, and review status.
- Contracts for later file, Excel, store crawl, and API synchronization.

### Excluded from this iteration

- Running a recurring production store crawler.
- Automatic publication to Shopify or WordPress.
- Machine translation of source material.
- Treating third-party research as verified official fact.
- Deleting the original source directory.

## 4. Ownership and Isolation Model

The required ownership chain is:

`Brand -> Site -> Knowledge Directory -> Knowledge Document -> Knowledge Chunk`

Initial records:

- Brand key: `lightinthebox`
- Brand name: `LightInTheBox`
- Site key: `lightinthebox-main`
- Canonical host: `www.lightinthebox.com`
- Site role: `commerce-primary`

Every document must have exactly one `brand_id`, one `site_id`, and one primary directory node. A database constraint ensures the selected site belongs to the same brand. Retrieval must receive a brand/site scope and apply it before lexical or vector ranking. A document can never be retrieved merely because its text is semantically similar to a different brand.

The existing generic `business_line` remains for backward compatibility but is not an isolation boundary.

## 5. Knowledge Directory Taxonomy

The LightInTheBox root contains these stable directory keys:

1. `brand-company` — brand story, corporate facts, accessibility, imprint.
2. `customer-transaction` — ordering, payment, tracking, contact and reviews guidance.
3. `policy-compliance` — returns, privacy, cookies, terms, intellectual property and statutory statements.
4. `product-category` — product and category evidence, category maps and buying context.
5. `new-hot-products` — new arrivals, weekly/monthly additions and best sellers.
6. `logistics-fulfilment` — delivery methods, timing and fulfilment evidence.
7. `official-news` — dated company announcements and official updates.
8. `investor-relations` — filings, governance and reported financial facts.
9. `research-reference` — third-party or mixed-source analysis that requires verification.

Directory nodes are brand-owned. Additional nested nodes may represent categories, policy families, years, or source collections. Node paths are unique within a brand, not globally.

## 6. Document Metadata

Each knowledge document stores:

- ownership: brand, site, primary directory;
- identity: stable asset key and canonical document key;
- language: BCP-47-compatible language code detected from content rather than filename; the initial active/importable corpus accepts only `en`, while `pt`, `zh`, and undetermined sources remain excluded and auditable;
- provenance: source name, canonical URL, source type, source file, source checksum and ingestion method;
- authority: `first_party`, `regulatory_filing`, `mixed_research`, or `third_party`;
- lifecycle: published/effective date, retrieved date, expiry/review-due date, version and active/superseded state;
- governance: risk level and review status;
- content: normalized Markdown and normalized-content checksum;
- retrieval controls: eligible-for-generation flag and citation-required flag.

Policy and customer-service documents require a source URL and retrieved date before they become generation-eligible. Research documents remain citation-required and unverified until reviewed.

## 7. Source Classification

Source routing is deterministic:

- `01_Company_Info` -> `brand-company`
- `02_Customer_Service` -> `customer-transaction`, except delivery-specific pages -> `logistics-fulfilment`
- `03_Company_Policies` -> `policy-compliance`; product guides nested under “All You Need To Know” -> `product-category`
- `04_Product_Categories` -> `product-category`; “new arrivals” and “best sellers” indexes -> `new-hot-products`
- official reports about corporate history -> `brand-company`
- official filings/governance/financial reports -> `investor-relations`
- mixed-source market, traffic, competition and reputation reports -> `research-reference`
- official dated announcements -> `official-news`

Ambiguous routing is retained in an audit queue rather than silently guessed.

## 8. Normalization and Deduplication

### Exact duplicate

Normalize line endings, Unicode, trailing whitespace, repeated blank lines and known format-only wrappers. Hash the resulting body. Identical normalized hashes form one document.

Selection precedence:

1. Markdown over TXT mirror.
2. First-party URL-bearing source over URL-less copy.
3. More complete provenance metadata.
4. Newer retrieved/effective date.
5. Stable lexical path as final deterministic tie-breaker.

All discarded mirrors remain in a duplicate audit manifest with `duplicate_of` and reason; source files are not deleted.

### Near duplicate

Use normalized paragraph fingerprints plus token-set similarity. Candidate pairs are limited to the same brand, language and directory family. The initial automatic threshold is 0.92. Near duplicates are not merged automatically unless one document is a strict subset of the other and provenance precedence is unambiguous. Otherwise both remain with a review record.

### Version duplicate

Documents sharing canonical URL or canonical document key are versions, not independent documents. The newest reviewed version is active; older versions remain `superseded` for audit.

## 9. Content Cleanup

Cleanup is conservative and reproducible:

- preserve headings, lists, tables, figures and material policy wording;
- convert Chinese-style inline link wrappers to standard Markdown links;
- remove empty headings, isolated backslashes, navigation boilerplate and duplicate title prefixes;
- normalize spacing without rewriting claims;
- extract URL and date metadata from the body into fields while retaining a provenance block;
- mark low-information link catalogs as `directory_evidence`, not narrative fact;
- do not translate or embellish content during cleaning.

Every cleaned document contains YAML front matter and a normalized Markdown body. The original relative path and checksums make the transformation reproducible.

## 10. Chunking and Retrieval

Chunking follows Markdown structure:

- start with heading-defined sections;
- retain the document title and section path in metadata;
- target 350–700 tokens;
- combine short adjacent sections within the same parent;
- split long sections at paragraph/list boundaries with small contextual overlap;
- never mix documents, languages, directory nodes, versions, brands, or sites;
- include source URL, authority, dates, risk and ownership in chunk metadata.

Retrieval order:

1. Enforce brand and site.
2. Exclude inactive/superseded and non-eligible documents.
3. Require English for the initial corpus, then filter directory based on the content brief.
4. Rank lexical/vector candidates.
5. Apply authority and freshness weighting.
6. Return citations and provenance with each chunk.

Policy queries prefer current first-party policy content. Product/category queries can combine category evidence with current synchronized product records. Research content may support discovery but cannot override first-party facts.

## 11. Keyword Association

Knowledge connects to the formal keyword library (ID 42) through explicit associations:

- document/node to keyword taxonomy node;
- optional document to keyword cluster;
- association confidence and source (`rule`, `import`, or reviewed match).

The association never copies keyword rows into knowledge content. Content generation uses the brief's keyword cluster to locate matching brand/site-scoped knowledge directories and documents.

## 12. Import and Synchronization Contract

All ingestion methods produce the same staged document contract:

- file or folder import;
- Excel manifest import;
- store crawl;
- authenticated API synchronization.

Each run has a batch ID, brand/site scope, configuration hash, source checksum, counts and error log. Data is validated in staging. Commit is transactional; failures leave the active corpus unchanged. Re-running the same checksum/configuration is idempotent.

Store synchronization uses canonical URL or product/category external ID as identity. A source update creates a new version or updates the staged record; it never silently moves knowledge across brands or sites.

## 13. Migration Procedure

1. Back up the PostgreSQL database.
2. Generate the cleaned local corpus and manifests without DB writes.
3. Validate counts, required metadata, duplicate groups, language, URLs and directory assignment.
4. Create brand/site/directory and governance schema.
5. Stage the cleaned LightInTheBox corpus.
6. Generate chunks and keyword associations.
7. In one transaction, insert the new corpus and mark old LightInTheBox entries as superseded or archive them through an auditable migration mapping.
8. Validate retrieval isolation and counts.
9. Keep the rollback mapping and backup until user acceptance.

Existing task/article foreign keys must remain valid. If a referenced legacy knowledge ID is replaced, references are migrated to the canonical replacement before removal. Unreferenced duplicate rows may then be deleted; referenced legacy rows can be retained as archived aliases when reassignment is unsafe.

## 14. Admin Experience

The knowledge list provides:

- brand and site switchers;
- directory tree navigation;
- language, authority, risk, review and lifecycle filters;
- duplicate/version badges;
- source URL, retrieval/effective dates and freshness warnings;
- per-document chunk and keyword-association inspection;
- import/sync batch history.

Document creation and upload require brand, site, directory, language, authority and source fields. Defaults may be derived from the selected site and import folder but remain visible before confirmation.

## 15. Error Handling and Safety

- Reject cross-brand site/document relationships at validation and database levels.
- Reject an active policy document missing provenance or retrieval date.
- Quarantine unreadable, empty, unsupported or ambiguous files.
- Do not auto-merge uncertain near duplicates.
- Do not expose unreviewed high-risk research to quality-gated generation as verified evidence.
- Preserve source and migration audit rows after rollback or deletion.
- Avoid destructive changes to the original filesystem corpus.

## 16. Testing

Automated tests cover:

- ownership constraints and cross-brand rejection;
- directory path uniqueness by brand;
- exact duplicate precedence and audit mapping;
- near-duplicate candidate behavior;
- language detection overrides incorrect legacy labels;
- normalization and Markdown link cleanup;
- heading-aware chunk boundaries and metadata;
- policy eligibility requirements;
- retrieval brand/site hard filters;
- keyword association isolation;
- idempotent staged import and rollback;
- preservation/migration of task references;
- admin filters and required fields.

## 17. Acceptance Criteria

- One active LightInTheBox brand and one canonical commerce site exist.
- Every active document has brand, site, directory, language, authority and provenance fields.
- No duplicate normalized-content hash exists among active documents in the same brand/language scope.
- Markdown/TXT mirrors yield one active canonical document and auditable duplicate records.
- No retrieval result crosses the selected brand/site.
- Current policy evidence outranks research and superseded versions.
- Every generated chunk has ownership, section path, source hash and citation metadata.
- Keyword associations reference library ID 42 without copying keywords into knowledge prose.
- Original source files are unchanged.
- A database backup and rollback mapping exist before legacy cleanup.
