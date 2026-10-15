# LightInTheBox Five-Article Controlled Rewrite Pilot Design

Date: 2026-08-24

Status: approved in chat; written specification pending user review

Scope: five existing published English articles; draft generation only

## 1. Purpose

Validate the rebuilt LightInTheBox knowledge corpus, keyword library 42, modular prompt system and quality-gated generation flow by rewriting five representative existing articles.

The pilot must demonstrate that the new system can improve factual grounding, GEO citability, SEO coverage, readability and brand consistency without changing any live article until a human explicitly approves a replacement in a later operation.

This pilot is not a bulk rewrite of all 69 articles and is not authorization to publish, overwrite local published content, or synchronize changes to WordPress.

## 2. Selected Articles

| Article ID | Current title | Pilot role |
|---:|---|---|
| 6 | Wedding Dresses: A Complete Style, Fit, and Planning Guide | High-value product-category buying guide |
| 33 | Indoor vs Outdoor Training Gear: What Changes? | Comparison and use-case search intent |
| 43 | How to Use Accessories Without Overcomplicating an Outfit | Lifestyle and natural product discovery |
| 59 | How to Track a LightInTheBox Order | Official customer education and procedural facts |
| 62 | What Is LightInTheBox? A Company and Brand Overview | Brand entity, authority and company facts |

Selection balances current traffic, content type, search intent and available governed knowledge. Article IDs, slugs, publication dates and live bodies remain unchanged throughout the pilot.

## 3. Core Decisions

### 3.1 Draft-first, never overwrite

Every rewrite creates a separate immutable pilot run and draft revision. The original article is snapshotted by ID and content hash before generation. Generation and evaluation may update only pilot-owned draft/run records and local archival artifacts.

The pilot provides no automatic adoption or publishing path. A future explicitly authorized approval action may adopt a selected draft, but that is outside this specification.

### 3.2 Runtime matching, no permanent knowledge-keyword binding

Knowledge documents do not need precomputed keyword associations. At run time:

1. The keyword selector derives the article topic and search intent from the original article.
2. It searches keyword library 42 within English and LightInTheBox-compatible brand scope.
3. It selects one primary keyword, three to eight secondary keywords, useful variants/questions and relevant topic-cluster context.
4. The knowledge retriever independently searches the LightInTheBox English knowledge scope using the topic, selected intent and article type.
5. The run records both selections as evidence; it does not persist them as global knowledge-to-keyword pivots.

The existing optional `knowledge_keyword_nodes` and `knowledge_keyword_clusters` tables remain available for future manual curation but are not a prerequisite for this pilot.

### 3.3 Controlled rewrite

The system rewrites the article from the source article, current governed evidence and selected keyword strategy. It must not paraphrase blindly, preserve unsupported legacy claims, or invent new commercial facts.

The rewrite may propose a better title, excerpt and metadata, but it must preserve the existing slug in its recommendation package.

## 4. Architecture

### 4.1 Pilot orchestration

A dedicated pilot orchestration service owns selection validation, immutable source snapshots, runtime keyword selection, governed retrieval, prompt composition, generation, evaluation, one bounded repair attempt and final draft packaging.

It must be idempotent by `(pilot_batch_id, article_id, source_content_hash, configuration_hash)`. Re-running an identical completed item returns the existing result. A source or configuration change creates a new run rather than mutating prior evidence.

### 4.2 Persistence

Pilot records must preserve:

- batch identity and status;
- article identity, source title/body/metadata hashes and snapshot time;
- keyword-library ID and selected keyword/cluster evidence with scores and reasons;
- knowledge scope and retrieved document/chunk evidence with citations;
- prompt blueprint/module versions and rendered prompt hash;
- AI provider/model identity and generation timestamps;
- original draft, repaired draft if applicable and final candidate draft;
- deterministic and model-assisted quality reports;
- approval status, which remains `pending_review` for this pilot;
- structured error and retry history.

Large Markdown/JSON artifacts are also archived under a pilot output directory in the workspace. Database rows store stable paths and hashes so local artifacts can be audited without becoming the sole source of truth.

No API keys, authorization headers, raw provider secrets or hidden reasoning are stored.

### 4.3 Keyword candidate selection

Candidate generation is deterministic and read-only before semantic ranking. It filters by:

- library 42;
- active status;
- English language or approved language-neutral records;
- brand relation compatible with LightInTheBox;
- article-type-compatible search intent;
- taxonomy/category evidence from the source title, headings and body;
- lexical matches, variants and existing topic clusters.

The reduced candidate set is semantically reranked with the configured GEOFlow embedding/AI provider. When semantic services are unavailable, the selector may use a deterministic lexical fallback but must record degraded mode and lower confidence.

Selection output includes one primary keyword, three to eight secondary keywords and zero or more variants/questions. It must detect and report cannibalization among all five pilot drafts before final review.

### 4.4 Governed knowledge retrieval

Retrieval uses the existing mandatory `KnowledgeScope` for LightInTheBox, the main site and English. Candidate filtering occurs before scoring and includes active lifecycle, generation eligibility and relevant directories.

Expected directory emphasis:

- Article 6: product-category plus applicable first-party guidance.
- Article 33: product-category and evidence appropriate to the compared use cases.
- Article 43: product-category and brand-safe lifestyle guidance.
- Article 59: customer-transaction and policy-compliance, preferring current first-party policy.
- Article 62: brand-company plus applicable policy-compliance evidence.

No legacy/unowned, Portuguese, inactive, ineligible or foreign-site evidence may enter a prompt. Document-level governance and provenance remain authoritative over chunk metadata.

### 4.5 Modular prompt composition

The prompt is assembled from versioned modules rather than one monolithic template:

- universal GEO/citability contract;
- SEO and search-intent contract;
- LightInTheBox brand voice and entity facts;
- article-type structure module;
- runtime keyword strategy;
- retrieved knowledge evidence and citation rules;
- title/meta/slug-preservation instructions;
- internal-link and shopping CTA constraints;
- risk and unsupported-claim rules;
- output schema and quality-gate repair instructions.

The rendered prompt and every module version are snapshotted for reproducibility.

## 5. Article Output Contract

Each candidate package contains:

- proposed title;
- unchanged existing slug;
- proposed excerpt;
- Markdown article body;
- meta title and meta description;
- primary and secondary keyword report;
- FAQ suggestions where the intent supports them;
- internal-link recommendations;
- shopping CTA recommendations linking to LightInTheBox rather than pretending the editorial site is the checkout destination;
- recommended Schema.org types/properties;
- inline or section-level citations for factual claims;
- source/evidence appendix for reviewers;
- original-versus-draft structured diff;
- quality scorecard and repair history.

The main article should answer its core question early, use clear headings, include concise quotable passages and avoid keyword stuffing.

## 6. Factual and Commercial Safety

The generator may use only facts supported by the governed evidence snapshot or stable facts explicitly present in the source article and independently retained as evidence.

It must not invent or imply unsupported:

- prices, discounts or promotions;
- inventory or current product availability;
- bestseller or popularity claims;
- fixed delivery durations;
- refund guarantees or policy exceptions;
- product specifications absent from evidence;
- personal purchase, delivery, return or wearing experiences.

When current product data is unavailable, category articles use durable buying guidance and non-specific LightInTheBox shopping CTAs. Policy/customer-service articles prefer current first-party policy evidence over research or prior article wording.

## 7. Quality Gates

Every candidate is evaluated before it may reach `pending_review`.

Required deterministic checks:

- source snapshot and scope integrity;
- required output fields;
- supported citation targets;
- no foreign-brand/site evidence;
- primary/secondary keyword limits and natural coverage;
- title/meta length and uniqueness warnings;
- internal-link validity and slug preservation;
- prohibited unsupported commercial claims;
- near-duplicate and five-article cannibalization checks.

Scored checks:

- factual grounding and citation completeness;
- GEO citability and answer clarity;
- SEO intent coverage;
- structure and readability;
- LightInTheBox brand consistency;
- usefulness and non-generic depth.

If a candidate fails a repairable gate, the orchestrator performs at most one targeted repair using the exact failed checks. The original candidate is retained. A second failure produces `manual_review_required`; it does not loop indefinitely or publish.

## 8. Review Experience

The admin review page shows:

- original and candidate content side by side;
- word/section-level diff;
- selected keywords and selection reasons;
- evidence documents, chunks, URLs and retrieved dates;
- prompt/model/configuration identity;
- gate scores, warnings and repair history;
- proposed metadata, links, CTA and schema;
- downloadable Markdown and JSON audit package.

The only pilot decisions are `approve_for_future_adoption`, `request_revision` and `reject`. Approval records reviewer and timestamp but does not alter the published article or trigger distribution.

## 9. Failure and Recovery

- Each article runs independently; one failure cannot roll back another article's completed evidence.
- Claims use leases/fencing so concurrent workers cannot finalize the same run.
- Provider errors use bounded retries and retain structured failure records.
- Source article changes after snapshot invalidate adoption eligibility and require a new run.
- Missing keyword/knowledge evidence fails closed or clearly enters degraded/manual review; it never silently fabricates context.
- All pilot data can be removed without touching the published articles, though audit rows should normally be retained.

## 10. Pilot Execution Sequence

1. Validate the five article IDs are still published and hash source snapshots.
2. Create one pilot batch with five independent items.
3. Select runtime keywords and record evidence.
4. Retrieve brand-scoped knowledge and record citations.
5. Compose versioned prompts and generate candidates.
6. Run quality gates and at most one targeted repair.
7. Run cross-pilot cannibalization analysis.
8. Export local Markdown/JSON packages.
9. Present all five as `pending_review` in the admin UI.

No execution step updates `articles`, creates a distribution job, calls WordPress, or changes a remote site.

## 11. Acceptance Criteria

The pilot is complete when:

1. Exactly article IDs 6, 33, 43, 59 and 62 have one successful or explicitly failed pilot item.
2. All five original published rows remain byte-for-byte unchanged in title, slug, content, status and publication timestamps.
3. Every candidate records library-42 keyword evidence and LightInTheBox-scoped knowledge evidence.
4. No permanent knowledge-keyword pivot is required or created by default.
5. Every factual/policy claim is traceable or flagged.
6. Every candidate has an auditable prompt/model/configuration snapshot and original-versus-draft diff.
7. Quality-gate and repair behavior is bounded, deterministic where specified and fully recorded.
8. Five-article keyword cannibalization is reported.
9. All candidates remain unpublished and require a later explicit adoption/publishing authorization.
10. The reviewer can inspect and download each complete candidate package from the admin UI.

## 12. Explicit Non-Goals

- Rewriting the remaining 64 articles.
- Automatically overwriting any published article.
- Publishing or synchronizing to WordPress.
- Permanent automatic knowledge-to-keyword association.
- Live product price, inventory or promotion crawling.
- Changing existing article slugs or redirects.
- Resolving the separate near-duplicate knowledge review UI or PHP chunk-sizing gap unless they block the five pilot articles.
