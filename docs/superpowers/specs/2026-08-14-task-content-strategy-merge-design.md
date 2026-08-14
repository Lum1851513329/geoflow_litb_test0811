# Task / Content-Strategy Pipeline Merge Design

## Goal

GEOFlow has two parallel content-production pipelines: the legacy `Task`-based
pipeline (autonomous scheduling, draft/publish pacing, mature admin UI, but a
single-shot AI call with no quality gate) and the newer ContentBrief-based
pipeline (`ContentBrief` → `ContentStrategyVersion` → `ContentBlueprintVersion`
→ `PromptPresetVersion` → `ContentGenerationRun`, with modular prompts and a
two-layer quality gate, but no autonomous scheduling — every brief is created,
confirmed, and adopted by hand).

This design merges them: `Task` keeps its scheduling/pacing/review machinery
unchanged, but the "generate a new draft" step routes through the
ContentBrief pipeline's quality-gated engine instead of a single free-text
prompt call. Reaching that requires first making the ContentBrief pipeline
itself fast enough to drive from a scheduler tick — today it needs a human
to fill in ~14 fields, wait for a strategy suggestion, confirm it, hand-edit
a blueprint JSON, confirm that, then manually trigger generation. This design
also fixes a real content-quality issue surfaced while designing this: the
Content Brief pipeline's currently-hardcoded LITB blueprint reuses identical,
verbatim section headings ("Key Options and Criteria", etc.) across every
article regardless of topic, which reads as templated to both readers and
crawlers.

## Background

Full pipeline comparison — how each one currently works, confirmed by
reading `WorkerExecutionService`, `ContentGenerationCoordinator`,
`DeterministicQualityGate`, `GenerationAdoptionService`, `TaskController`,
`ContentBriefController`, `PromptPresetController`, and by querying this
environment's live data — is not repeated here in full; the key facts this
design depends on:

- `Task` (`app/Models/Task.php`) triggers `WorkerExecutionService::executeTask()`
  every minute via `geoflow:schedule-tasks`. Each tick either releases one
  due draft to `published` (paced by `publish_interval`, `next_publish_at`)
  or generates one new draft (bounded by `draft_limit`/`article_limit`) from
  a single `Prompt` template + one AI call, with `review_status` auto-set to
  `approved` when `need_review=0` (fully autonomous, zero human review) or
  `pending` when `need_review=1`.
- `ContentBrief` → `ContentStrategyVersion` → `ContentBlueprintVersion` are
  strictly per-topic (each Strategy/Blueprint belongs to exactly one Brief,
  confirmed by reading their `$fillable`/FK columns) — they are **not**
  reusable across different titles. What genuinely is reusable across topics
  is a `PromptPresetVersion` (a bound set of `PromptModuleVersion`s — the
  "how to write" layer) and, after this design, a new attached blueprint
  *template* (the "what structure to write" layer).
  `LitbQuarterlyBriefImporter::import()` already proves the pattern this
  design generalizes: it creates `ContentStrategyVersion`/`ContentBlueprintVersion`
  rows with `lifecycle_status` already `CONFIRMED` at creation time (via
  `forceCreate`), skipping the human confirm-strategy/confirm-blueprint UI
  screens entirely, because the blueprint content itself came from a
  pre-reviewed catalog (`LitbQuarterlyBriefCatalog`) rather than being
  authored fresh per brief.
- `ContentGenerationRequestService::reserve()` requires `ContentBrief.status
  === STRATEGY_READY`, `ContentStrategyVersion.lifecycle_status === CONFIRMED`,
  `ContentBlueprintVersion.lifecycle_status === CONFIRMED`, and a
  `PromptPresetVersion` not in `draft`/`archived` — enforced in
  `ContentGenerationCoordinator::assertConfirmedInputs()`. It also requires
  `MandatoryPromptModuleGuard::assertComplete()` to pass: every `PromptModule`
  with `is_system=true, is_mandatory=true, is_enabled=true` whose
  `applicability` rule (or `null`, which matches everything) matches the
  brief's context must be bound, at its current version, at
  `lifecycle_status=production`, on the chosen Preset. Live data check found
  the 4 generic (non-LITB) presets only bind 6 of the 18 currently-mandatory
  modules — they predate the 12 `litb.*` modules being marked mandatory and
  were never updated, so any brief resolving to one of them fails this check
  today. This design doesn't fix that specific staleness (out of scope, see
  below) but the new "must explicitly pick a Preset" flow (Component 3)
  prevents a user from *silently* landing on a broken preset the way the
  current auto-match can.
- `GenerationAdoptionService::adopt(ContentGenerationRun $run, array
  $articleData, Admin $actor)` requires `status === READY_FOR_REVIEW` and a
  human `Admin $actor` argument (called only from the authenticated admin
  controller action today); it creates the `Article` as
  `status=draft, review_status=pending` — never auto-published.
- `DeterministicQualityGate::parseMarkdown()` matches each Markdown H2 (etc.)
  against the blueprint's declared `heading` text via
  `matchSectionKey()` — longest-normalized-heading-is-a-whole-word-prefix-of
  -the-actual-heading. This tolerates a model *extending* a heading
  ("Sizing" → "Sizing for Every Foot Shape") but requires the blueprint's
  literal heading text to appear as that prefix — it cannot tolerate a
  wholly different, freely AI-phrased heading.
- Real data example (`ContentGenerationRun` id 12, topic "Bridesmaid
  Dresses..."): first generation attempt scored 10/100 (missing 2 sections,
  2 sections too thin, 2 planned questions uncovered); the one allowed
  automatic revision fixed the structural issues but overshot the target
  length range (3058 words vs. 1080–2520 allowed) and still failed the
  second deterministic check — this run's `Article` was nonetheless
  force-published earlier in this project by deliberately bypassing review,
  which is exactly the kind of unreviewed-but-published outcome Task's
  `need_review=0` mode already produces by design; this design does not
  change that trade-off, it only makes it possible to get Pipeline 2's
  content rigor *and* Task's opt-in "skip review" mode in one pipeline
  instead of forcing a choice between the two.

## Scope

In scope:
- Loosen `DeterministicQualityGate`'s section matching from
  literal-heading-text-prefix to declared-section-count-and-order, so a
  blueprint's sections can carry a `purpose` instead of (or in addition to)
  a fixed `heading`, and the model is free to phrase each H2 naturally.
- Add a reusable `blueprint_template` (JSON) column to `PromptPresetVersion`,
  carrying the same section shape (`key`, `purpose`, `questions_to_answer`,
  `minimum_words`, `cta_allowed`, `required_fact_ids`) `ContentBlueprintVersion.blueprint.sections`
  already uses, minus a fixed `heading` (replaced by AI-phrased headings per
  Component 1). Extend the `/prompt-presets` create/edit UI with a JSON
  editor for this field (mirroring the existing blueprint JSON editor on
  the content-brief blueprint screen).
- Migrate the 9 existing LITB pillar section-templates
  (`LitbQuarterlyBriefCatalog::sections()`/`pillar()`) into this new
  `blueprint_template` field on their 9 corresponding existing Presets
  (`litb.geo_category_authority.en`, `litb.product_comparison.en`, etc.) —
  reusing their already-validated structure and business-goal/CTA/risk-flag
  metadata verbatim, not redesigning it.
- Simplify `ContentBrief` creation: Preset selection becomes a required
  field on the create form (no silent auto-match fallback); once picked,
  a `ContentBrief` + auto-confirmed `ContentStrategyVersion` +
  auto-confirmed `ContentBlueprintVersion` (sourced from the Preset's
  `blueprint_template`) are created together, skipping the separate
  strategy-confirm and blueprint-confirm screens — reusing the
  `LitbQuarterlyBriefImporter`'s `forceCreate`-with-`CONFIRMED`-status
  pattern, generalized to work for any Preset, not just LITB's.
- Add `Task.prompt_preset_id` (replacing the single free-text `Prompt`
  reference for Tasks that opt into the new engine) and a
  `generation_engine` enum column (`legacy` default | `quality_gated`) on
  `Task`, so existing Tasks keep working unchanged and this is opt-in per
  Task.
- When `generation_engine=quality_gated`, `WorkerExecutionService`'s
  "generate a new draft" branch creates a Brief/Strategy/Blueprint (per the
  simplified flow above, using the Task's next title-library entry as
  `topic`), reserves and dispatches a `ContentGenerationRun`, and — once a
  new per-minute recovery-style command observes it reached
  `ready_for_review` — auto-adopts it via a dedicated system `Admin` actor.
  The resulting `Article` (`draft`/`pending`) flows into Task's existing
  `need_review`/`draft_limit`/`publish_interval` logic unchanged.
- A one-off (non-Task, non-scheduled) spreadsheet import for bulk content
  requests, potentially spanning multiple `DistributionChannel`s/sites,
  building on Component 3's simplified creation and reusing the existing
  `DistributionOrchestrator::enqueueForArticleToChannel()` sync mechanism
  (Component 5).
- A `show_references_section` flag on the Preset blueprint template and a
  URL-fetch-and-extract step so imported `reference_urls` become real
  writing material (not just decorative links) and, for Presets that opt
  in, a rendered "References" section at the end of the article (also
  Component 5).

Out of scope (explicitly deferred, not silently dropped):
- Fixing the 4 generic (non-LITB) presets' stale module bindings — a
  pre-existing bug unrelated to this merge; flagged to the user separately.
- Any change to `SemanticQualityReviewer`, `ArticleWorkflowTransitionService`,
  or `DistributionOrchestrator`'s existing sync logic itself (Component 5
  only *calls* the existing `enqueueForArticleToChannel()`, it doesn't
  modify it).
- Bulk-confirming the 36,923 already-imported LightInTheBox keywords into
  `KeywordCluster` rows via `KeywordClusterSuggestionService` (currently 0
  confirmed clusters — the suggestion engine and single-cluster
  confirmation UI already exist; only a bulk-confirm action is missing). A
  real, separate gap surfaced while designing this, but it's about cleaning
  up existing keyword data, not about the Task/pipeline merge or multi-site
  content requests. Flagged to the user as a follow-up, not addressed here.
- Onboarding entirely new sites/brands (their own keyword imports,
  knowledge bases, categories, Presets). Nothing in this design is
  LightInTheBox-specific at the schema level (Task/Preset/ContentBrief/
  KnowledgeBase are already generic, reusable entities, and Component 5's
  spreadsheet already supports routing rows to different
  `DistributionChannel`s), so this design doesn't block that future work —
  but building an actual "add a new site" onboarding workflow is separate
  future effort.
- Migrating already-published LITB articles or re-running them through the
  new heading-matching logic — this only affects newly generated content.

## Component 1: AI-phrased section headings

**Blueprint section shape changes** (`ContentBlueprintVersion.blueprint.sections[]`
and the new `PromptPresetVersion.blueprint_template.sections[]`, same
shape): drop the requirement that `heading` be the literal text the model
must reproduce. Keep `key` (a stable slug identifying the section across the
system — used in quality-report issue codes, `required_fact_ids` references,
etc.), replace the *meaning* of `heading` from "exact text to match" to "a
short label describing the section's role, included in the prompt as
guidance for the model to phrase its own natural heading from" (the field
name can stay `heading` for backward compatibility with existing
`ContentBlueprintVersion` rows — the prompt-compilation step already reads
`blueprint['sections']`, only its *instructions to the model* change, not
the JSON shape).

**`DeterministicQualityGate::parseMarkdown()` changes**: replace
`matchSectionKey()`'s text-based matching with position-based matching —
collect every heading at the *shallowest* level used for section breaks
(today: any level 2–6 heading that isn't nested under a non-section
heading; in practice, the model is instructed via the prompt to use H2 for
every top-level section) in document order, and zip them 1:1 against the
blueprint's `sections` array in the order the blueprint defines them.

- New check, replacing the old exact-count-of-missing-sections detection:
  if the number of section-level headings found doesn't equal
  `count($blueprint['sections'])`, emit one `section_count_mismatch` issue
  (`"Article has {found} top-level sections; blueprint requires {expected}."`)
  instead of the current per-section `missing_section` issues — a single
  clear signal instead of N confusing ones when the model drops a section
  entirely or adds an extra one.
- When counts match, section N's *content* (paragraphs between heading N and
  heading N+1) is checked against blueprint section N's `minimum_words`,
  `questions_to_answer`, `cta_allowed`, and `required_fact_ids` exactly as
  today — only the *heading-identification* mechanism changes, not what's
  checked once a section is identified.
- All other `DeterministicQualityGate` checks (duplicate paragraphs,
  keyword density, required entities, heading hierarchy, target length,
  prohibited information, external links, market mismatch) are unchanged.

**Prompt-compilation change**: the module/prompt text that currently tells
the model to use each section's exact `heading` as its H2 needs to instead
say something like "write a natural, specific H2 for each section based on
its stated purpose and the article's topic — do not reuse generic section
labels verbatim." This lives in whichever `PromptModuleVersion` currently
renders the blueprint's sections into the compiled prompt (the
`output_format`/`seo_structure` system modules, based on their names) —
identify the exact module during implementation and update its content
(this creates a new `PromptModuleVersion` per the existing versioning
convention, published to `production`, not an edit-in-place of the current
production version).

## Component 2: `blueprint_template` on `PromptPresetVersion`

- New nullable `blueprint_template` JSON column on `prompt_preset_versions`
  (migration, guarded with `Schema::hasColumn()` per this project's existing
  migration convention). Shape: `{"target_length": int, "sections":
  [{"key": string, "heading": string, "questions_to_answer": string[],
  "minimum_words": int, "cta_allowed": bool, "required_fact_ids": string[]}],
  "required_entities": string[]}` — i.e. exactly `ContentBlueprintVersion.blueprint`'s
  shape minus the per-brief `working_title`/`faq_questions`/`seo_title`/
  `meta_description`/`schema_types` fields (those stay per-brief, derived
  from the brief's own `topic`/`core_question` at creation time, not part of
  the reusable template).
- `/prompt-presets` create/edit form gains a `blueprint_template` JSON
  textarea (mirrors the existing pattern at
  `resources/views/admin/content-briefs/blueprint.blade.php`'s JSON editor —
  reuse the same client-side validation approach).
- Data migration: for each of the 9 pillar `content_type`s already present
  in `LitbQuarterlyBriefCatalog::pillar()` (`category_authority`,
  `product_comparison`, `style_occasion`, `new_arrivals`,
  `product_education`, `customer_education`, `brand_company`,
  `official_news`, `faq`), find that pillar's corresponding
  Preset (matched by the `preset` key already recorded in
  `LitbQuarterlyBriefCatalog::pillar()`, e.g. `litb.geo_category_authority.en`)
  and set its current `PromptPresetVersion.blueprint_template` to the
  section shape from `LitbQuarterlyBriefCatalog::sections()`, with headings
  updated per Component 1 (role-description labels, not literal text to
  reproduce) and `target_length` from the importer's hardcoded `1800`. This
  is a one-off data-backfill command (`geoflow:content-strategy:seed-preset-blueprint-templates`
  or similar), not application logic — run once during rollout.

## Component 3: Simplified brief creation

- `content-briefs/create` form: `prompt_preset_id` becomes a required
  select (populated from `PromptPreset::query()->whereHas('currentVersion',
  fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)
  ->whereNotNull('blueprint_template'))`) — only presets that actually have
  a template attached are selectable, so this flow can't target a preset
  Component 2 hasn't been run for yet. Remaining required fields collapse
  to: `topic`, `core_question`, `primary_keyword_id`
  (or `keyword_cluster_id`), `category_id`. Everything else
  (`market_code`, `language_code`, `audience`, `business_goal`,
  `content_type`, `cta_strength`) is read from the chosen preset's
  `matching_rules`/a new small `default_brief_fields` JSON alongside
  `blueprint_template` — implementation detail to work out per-field during
  planning, but the principle is: nothing the preset already implies is
  asked twice.
- New service method (`ContentBriefController::store()`'s new code path,
  or a new `ContentBriefQuickCreateService` — naming decided during
  planning) that does what `LitbQuarterlyBriefImporter::import()` already
  does per-definition, generalized: create the `ContentBrief`
  (`status=STRATEGY_READY` directly, skipping `READY`), then
  `ContentStrategyVersion::forceCreate([...lifecycle_status =>
  CONFIRMED...])` (strategy content derived the same way
  `ContentStrategyService::suggest()` already builds a baseline — reuse that
  method's logic rather than duplicating it — then immediately confirmed
  instead of shown to a human), then
  `ContentBlueprintVersion::forceCreate([...lifecycle_status =>
  CONFIRMED...])` using the preset's `blueprint_template` as the section
  source, with `working_title` set from the brief's `topic` and
  `faq_questions`/`seo_title`/`meta_description` derived the same way
  `starterBlueprint()` already derives its per-brief fields.
- The existing manual flow (create brief without a preset locked in →
  review AI-suggested strategy → confirm → hand-edit blueprint JSON →
  confirm) is **not removed** — it remains available for cases that
  genuinely need bespoke strategy/blueprint content the template can't
  express. The simplified flow is an additional fast path, not a
  replacement UI.

## Component 4: Task-driven autonomous generation

- Migration: add `prompt_preset_id` (nullable FK to `prompt_presets`) and
  `generation_engine` (string, default `'legacy'`, values `legacy` |
  `quality_gated`) to `tasks`. Existing Tasks are unaffected
  (`generation_engine` defaults to `legacy`, `WorkerExecutionService`'s
  current single-shot-generation code path is untouched for them).
- `/tasks/create` and `/tasks/edit`: when `generation_engine=quality_gated`
  is selected, the existing "内容配置" section's `prompt_id` field is
  replaced by a single Preset selector (same list as Component 3's — only
  presets with a `blueprint_template`). `ai_model_id` stays a Task-level
  field exactly as it is today — `ContentGenerationRequestService::reserve()`
  already takes `ai_model_id` as a plain argument (it doesn't require a
  human to choose it interactively at request time), so the Task's existing
  `ai_model_id`/`model_selection_mode` fields pass straight through to the
  auto-created run unchanged.
- `WorkerExecutionService::executeTask()`'s "generate a new draft" branch
  (today: `pickTitle()` → `generateContentWithModelSelection()` → single
  `Article::create()`) gets a second code path for
  `generation_engine=quality_gated` Tasks:
  1. `pickTitle($task)` — unchanged, same title-library mechanism.
  2. Create Brief/Strategy/Blueprint via Component 3's new service, using
     the picked title as `topic`, the Task's `knowledge_base_ids`
     (already-existing pivot) feeding evidence resolution the same way
     Component 3's manual flow would, and `category_id` from the Task's
     existing `category_mode`/`fixed_category_id` resolution
     (`pickCategory()`, unchanged).
  3. `ContentGenerationRequestService::reserve()` with the Task's
     `prompt_preset_id`/`ai_model_id` → dispatches `GenerateStrategyContent`
     onto the database queue exactly as the manual flow does today — this
     step is **asynchronous**, unlike Pipeline 1's current synchronous
     single-call generation, so `executeTask()` returns after dispatching
     rather than waiting for a result.
- New scheduled command (parallel to
  `RecoverContentGenerationDispatchesCommand`, same `everyMinute()`
  cadence): scans `ContentGenerationRun`s belonging to Task-driven briefs
  (identified via a `task_id` column added to `content_briefs` — nullable,
  only set for Task-driven briefs) with `status=ready_for_review`, and
  calls `GenerationAdoptionService::adopt()` for each, using a dedicated
  system `Admin` record (seeded once, e.g. `admin@geoflow.internal`,
  `is_system=true` or similar marker — exact mechanism decided during
  planning) as the `$actor`. A `ready_for_review` run whose Task has since
  been paused/deleted is left alone (not adopted) — checked via the Task's
  current `status` before adopting.
- Runs that land in `needs_evidence`, `model_quality_failed`, or
  `manual_review_required` are **not** auto-adopted — they sit until a
  human looks at the Task's brief list directly (existing `/content-briefs`
  UI already supports viewing any brief's runs and their quality-report
  issues, Task-driven or not — no new UI needed for this, since
  `ContentBrief` doesn't distinguish provenance in its own detail view
  today, though `task_id` being present is a visible signal an admin can
  use to recognize it).
- Once adopted (`Article` created as `draft`/`review_status=pending`),
  everything downstream is 100% today's unchanged Task logic:
  `need_review` gates review, `publishDueDraftArticle()` releases it on
  `publish_interval`, `DistributionOrchestrator` syncs it out.

## Component 5: Bulk multi-site content import

A one-off batch job (spreadsheet upload, not a scheduled/autonomous Task) for
requesting a batch of new articles at once, potentially for several
different sites. Distinct from Component 4: no continuous scheduling, no
draft pooling/pacing — each row becomes exactly one `ContentGenerationRun`,
generated and (if it passes) synced out as soon as it's ready, not paced
over time.

**Import format** (one row = one requested article):

Required columns:
- `site` — the target `DistributionChannel` (matched by `name` or `domain`,
  resolved during import; a row referencing an unknown channel fails
  validation before any generation is attempted, not after).
- `preset` — a `PromptPreset.preset_key`, must resolve to a Preset whose
  current version has a `blueprint_template` (Component 2) — same
  requirement as Component 3's simplified creation form.
- `topic` — becomes `ContentBrief.topic`.
- `category` — resolved to a `Category` (by name or id); becomes
  `ContentBrief.category_id`.

Optional columns (omit → Preset/derived defaults apply, same as Component 3):
- `core_question` — if blank, derived from `topic` the same way
  `ContentStrategyService`'s existing baseline-strategy logic already
  derives a fallback.
- `primary_keyword`, `market`, `language`, `cta_strength`,
  `required_mentions`, `prohibited_information`, `planned_publish_at`.
- `reference_urls` — one or more URLs (delimited, exact format decided
  during planning — e.g. one per line within the cell, matching
  `StoreContentBriefRequest`'s existing `reference_urls` line-splitting
  convention).

**Reference URL fetching**: for each `reference_urls` entry, a new fetch
step (reusing the project's existing SSRF-safe outbound HTTP pattern —
`SafeOutboundHttpClient`/`SafeOutboundRequest`, the same mechanism
`WordPressRestRequestFactory` already uses, not a new unguarded HTTP call)
retrieves the page and extracts readable body text (strip nav/boilerplate —
exact extraction approach, e.g. a readability-style algorithm vs. a simpler
heuristic, decided during planning). Extracted text is attached to the
generation run as evidence, alongside (not replacing) any `KnowledgeChunk`
evidence the Preset's own knowledge-base linkage already supplies — treated
as pre-approved for this specific import (the person who curated the
spreadsheet is the review step, not the existing `KnowledgeBase.review_status
= reviewed` gate, which stays scoped to persistent knowledge bases as
today). A URL that fails to fetch (dead link, blocked, non-HTML response) is
not treated as a fatal error for the row — generation proceeds without that
URL's content as evidence, but the URL is still retained for the citation
list described below, so a temporarily-unreachable-but-valid source doesn't
silently disappear from the published article.

**References section**: new `show_references_section` boolean on the
Preset's `blueprint_template` (Component 2's JSON shape), set per-Preset by
whoever edits that Preset — not inferred automatically. Recommended
defaults, based on the 9 migrated LITB pillar Presets' existing
`risk_flags`/purpose (final call is an editorial decision, not enforced by
code): **on** for `official_news`, `brand_company`, `product_comparison`,
`customer_education`, `new_arrivals` (all already carry an
evidence-related `risk_flag` or make dated/comparative claims); **off** for
`category_authority`, `product_education`, `style_occasion`, `faq`
(instructional/inspirational content not making sourced factual claims).
When a Preset has this flag on and the brief has at least one successfully-
resolved `reference_urls` entry, the compiled article gets one appended
section (rendered the same way any other blueprint section would be,
positioned after the blueprint's own last section) listing each reference
as a plain link — this section is **not** subject to
`DeterministicQualityGate`'s per-section `minimum_words`/`questions_to_answer`
checks (it's a citation list, not prose content), so the quality gate's
section-count check (Component 1) needs to treat it as an acknowledged extra
section rather than a mismatch.

**Generation and distribution flow per row**: create Brief + auto-confirmed
Strategy + auto-confirmed Blueprint (Component 3's service, with fetched
reference-URL text attached as evidence) → `ContentGenerationRequestService::reserve()`
→ dispatch → quality-gated generation (Component 1's heading matching, same
as everywhere else) → on `ready_for_review`, auto-adopt via the same system
`Admin` actor Component 4 uses → on successful adoption
(`Article.status=draft`), immediately call
`DistributionOrchestrator::enqueueForArticleToChannel($article, $channel)`
for the row's resolved `site` channel — this bypasses Task's
`need_review`/`publish_interval` gating entirely, since this path has no
`Task` in the loop at all; if review-before-sync is wanted for a given
import batch, that's a review step the operator performs before uploading
the spreadsheet (curating which rows/URLs go in), not something this
pipeline enforces after the fact. Rows whose run doesn't reach
`ready_for_review` (needs evidence, quality-failed) are reported back to the
uploader (exact reporting mechanism — a results page listing per-row
outcome, a downloadable report — decided during planning) rather than
silently dropped.

## Testing

- `DeterministicQualityGate`: existing tests for section-heading matching
  need updating for position-based matching (exact list identified during
  implementation); add cases for section-count mismatch (missing section,
  extra section) and confirm all other check categories (keyword density,
  duplicate paragraphs, etc.) are unaffected by the matching-mechanism
  change.
- `PromptPresetVersion.blueprint_template`: migration test confirming the
  column is nullable and existing rows are unaffected; a feature test for
  the `/prompt-presets` edit form persisting the new JSON field.
- Backfill command: test that all 9 pillar presets end up with a
  `blueprint_template` matching `LitbQuarterlyBriefCatalog`'s content
  (structurally, not verbatim headings per Component 1), and that running
  it twice is idempotent.
- Simplified brief creation: feature test creating a brief via the new
  required-preset flow, asserting the resulting Brief/Strategy/Blueprint are
  all immediately `CONFIRMED` and a `ContentGenerationRun` can be reserved
  against them without hitting `assertConfirmedInputs()`'s "must be
  confirmed" exception.
- Task quality-gated path: feature test for a `generation_engine=quality_gated`
  Task's scheduler tick creating a Brief/Strategy/Blueprint/Run instead of
  a single-shot `Article`; a separate test for the new auto-adopt command
  picking up a `ready_for_review` run and producing a `draft`/`pending`
  `Article`, and confirming existing `need_review`/`publish_interval` logic
  still gates it identically to a Pipeline-1-generated article.
- Regression: full existing Task pipeline test suite and full existing
  Content Brief pipeline test suite must still pass unmodified for
  `generation_engine=legacy` Tasks and for manually-created (non-Preset-
  required) briefs — this design must not remove either pipeline's current
  behavior for existing users of either.
- Bulk import (Component 5): feature test for a multi-row import spanning
  more than one `DistributionChannel`, asserting each row's Article ends up
  synced to its own specified channel and not any other row's; a test for
  an unknown `site`/`preset` value failing validation before any row is
  processed; a test for a `reference_urls` fetch failure not blocking that
  row's generation, with the failed URL still appearing in the rendered
  references list; a test confirming a Preset with
  `show_references_section=false` never renders a references section even
  when `reference_urls` were supplied; a test confirming the references
  section is excluded from `DeterministicQualityGate`'s per-section content
  checks but still counted correctly by the section-count check.

## Global Constraints

- `generation_engine=legacy` Tasks and manually-created Briefs (the
  existing flows) must keep working exactly as today — this is an additive,
  opt-in change, not a replacement.
- No change to `SemanticQualityReviewer`, `ArticleWorkflowTransitionService`,
  `DistributionOrchestrator`, or `docker-compose.yml`.
- The 4 generic presets' stale mandatory-module bindings are a known,
  separate, pre-existing bug — not silently fixed as a side effect of this
  work; call it out explicitly if it blocks testing Component 3/4 against a
  non-LITB preset.
- Auto-adoption must use a dedicated system `Admin` actor, never a real
  human admin's identity, so the audit trail (`adopted_by_admin_id`)
  accurately distinguishes autonomous adoptions from human ones.
- Reuse `LitbQuarterlyBriefImporter`'s `forceCreate`-with-confirmed-status
  pattern rather than inventing a new one, and reuse
  `ContentStrategyService::suggest()`'s baseline-strategy logic rather than
  duplicating it.
- Any new outbound HTTP call this design introduces (reference-URL fetching)
  must go through the project's existing SSRF-safe outbound client
  (`SafeOutboundHttpClient`/`SafeOutboundRequest`), never a raw HTTP call —
  this is a general project convention, not new to this design, but worth
  stating explicitly since Component 5 is the first place in this design
  that fetches arbitrary user-supplied URLs.
- `show_references_section` is an explicit per-Preset editorial toggle, not
  an auto-inferred behavior — no code should silently decide whether a
  content type "should" cite sources based on heuristics.
