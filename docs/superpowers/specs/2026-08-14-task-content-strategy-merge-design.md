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
- A spreadsheet import for bulk content requests, potentially spanning
  multiple `DistributionChannel`s/sites, that groups rows into
  auto-created `Task`s (one per `(site, preset, category)` group) so the
  requests run through Component 4's Task-driven automation rather than a
  separate distribution path (Component 5).
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

**Title crafting**: the same "reproduce the input verbatim" problem exists
one level up — today's flow treats `ContentBrief.topic` (or a Task's picked
`Title.title`) as effectively the published title. Going forward, `topic`/
`Title.title` is reframed as **directional input for the AI** (the subject
and angle to write about), not the final headline. A new mandatory
`PromptModule` (`title_crafting`, marked `is_mandatory=true` the same way
the other 18 existing mandatory modules are) instructs the model to write a
genuine headline as the very
first line of its Markdown output (`# Title`), grounded in real
headline-writing practice rather than placeholder guidance: lead with the
concrete benefit or answer, keep it specific rather than generic (name the
product/category/comparison, not "A Guide to X"), match the register the
Preset's purpose calls for (a news headline for `official_news`, a
how-to/decision framing for `buying_guide`, a direct question-answer framing
for `faq`), stay within normal SEO title length (roughly 50–60 characters,
stated in the module as guidance, not a hard quality-gate check), and avoid
clickbait or unverifiable superlatives given `DeterministicQualityGate`'s
existing prohibited-information and required-entity checks still apply to
it like any other content. This becomes a 19th always-applicable mandatory
module, so it inherits the same pre-existing staleness the Background
section and Global Constraints already flag for the 4 generic presets (they
bind only 6 of today's 18 mandatory modules) — not a new problem introduced
here, but this design makes it one module wider.

**Title extraction on adoption**: both Component 4's auto-adopt command and
Component 5's per-row import currently would otherwise fall back to
`brief.topic`/`blueprint.working_title` for `Article.title` — instead, both
extract the AI-written `# Title` line from the generated Markdown as
`Article.title` (extending the existing `stripLeadingTitleHeading()`-
adjacent parsing that already separates a leading H1 from body content, so
this reuses established parsing rather than adding a new one), stripping
that line from the body before it's stored/rendered as the article's first
section. If a run's Markdown has no leading H1 (malformed generation), that
is itself a `DeterministicQualityGate` failure under the existing heading-
hierarchy check, not a silent fallback to `topic` — a missing AI title never
reaches adoption. Manual adoption (a human reviewing a `ready_for_review`
run through the existing UI) is unaffected: `AdoptContentGenerationRequest`
still shows the extracted title as the default value of its already-required
`title` field, and a human can still edit it before confirming, same as
today.

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

A spreadsheet-driven bulk way to **create Tasks** (not a separate,
Task-bypassing pipeline) — rows are grouped by `(site, preset, category)`,
and each group becomes one auto-created `Task` (`generation_engine=
quality_gated`, per Component 4) with its own new Title Library populated
from that group's rows. Once created, the Task runs through Component 4's
already-designed lifecycle exactly like a manually-created one — scheduled
generation, quality gate, review gating, publish pacing, distribution sync
— nothing in this component talks to `DistributionOrchestrator` or bypasses
`need_review`/`publish_interval` directly.

**Import format** (one row = one requested article):

Required columns:

- `site` — the target `DistributionChannel` (matched by `name` or `domain`).
- `preset` — a `PromptPreset.preset_key`. A GEOFlow `category` can host more
  than one Preset with a genuinely different structure (confirmed against
  live data: "Style & Buying Guides" hosts both `litb.geo_category_authority.en`
  and `litb.geo_product_buying_guide.en`; "About LightInTheBox" hosts both
  `litb.brand_company_explainer.en` and `litb.official_news.en`), so `preset`
  cannot be inferred from `category` alone and stays a separate required
  column — the import UI presents it as a dropdown of presets *filtered to
  the row's chosen category* (human-readable preset names, not raw keys) so
  the operator never has to know or type a `preset_key` string directly.
- `category` — resolved to a `Category` (by name or id).
- `topic` — the selection/subject direction. Does **not** need to be a
  polished headline (see the title-generation note under Component 1 below)
  — a short phrase is enough; it seeds the Title Library entry and the
  `ContentBrief`, and is what the model uses as its writing direction, not
  the literal published title.
- `knowledge_base` — which `KnowledgeBase` to attach. Explicit and required
  for now because GEOFlow's knowledge bases aren't yet organized in a way
  that lets this be inferred reliably (see the "Related, deferred" note
  below) — once that reorganization happens, this column can become
  optional with a site-based default.

Rows sharing the same `(site, preset, category)` are merged into one Task;
that Task's `article_limit`/`draft_limit` is set to the number of rows in
the group. If `knowledge_base` differs across rows within the same group,
import validation flags it as a same-group conflict rather than silently
picking one row's value — unlike `knowledge_base`, the optional columns
below are expected to differ row-by-row (each row becomes its own `Title`),
so no conflict check applies to them.

Optional columns — deliberately limited to the fields that vary **per row
within a group** (`market`, `language`, `cta_strength`, and similar are
already fixed once `preset` is chosen, per Component 3's "nothing the
preset already implies is asked twice" principle, so they aren't import
columns — they come from the Preset uniformly for every row in the group):

- `primary_keyword` — stored on the existing `Title.keyword` column.
- `core_question` — if blank, derived from `topic` the same way
  `ContentStrategyService`'s existing baseline-strategy logic already
  derives a fallback; stored on the new `Title.core_question` column.
- `reference_urls` — one or more URLs (one per line within the cell,
  matching `StoreContentBriefRequest`'s existing `reference_urls`
  line-splitting convention); stored on the new `Title.reference_urls`
  column.

**Downloadable import template**: a "Download template" action on the bulk
import screen generates an `.xlsx` file (not a hand-maintained static file —
generated fresh per download so it reflects current live Presets/Categories/
Channels):

- Row 1: the column keys above (what the importer actually parses).
- Row 2: a Chinese instruction line per column (required/optional, format,
  guidance — e.g. `site`: "【必填】发布到哪个站点，从"可选值参考"表中选，不要手打";
  `topic`: "【必填】选题方向，写清楚主题即可，不用是完整标题（最终标题由AI生成）").
  The importer skips this row when parsing — it's recognized by position
  (always row 2), not by content-sniffing.
- Rows 3–4: two example rows, visibly marked (e.g. in a `topic` prefix like
  "示例——请删除后再填真实数据") so they're never mistaken for real data if
  left in place, but still fail validation loudly (unresolvable `site`
  value) rather than silently importing as real rows if someone forgets to
  delete them.
- A second sheet, "可选值参考" ("reference values"), listing current `site`
  (channel name + domain), `preset` (name + key, grouped by category), and
  `category` values, queried live at download time. The `site`/`preset`/
  `category` columns on the main sheet use Excel data-validation dropdowns
  sourced from this sheet, so those three fields can't be mistyped.

**Reference URL fetching**: for each `reference_urls` entry, a fetch step
(reusing the project's existing SSRF-safe outbound HTTP pattern —
`SafeOutboundHttpClient`/`SafeOutboundRequest`, the same mechanism
`WordPressRestRequestFactory` already uses, not a new unguarded HTTP call)
retrieves the page and extracts readable body text (strip nav/boilerplate —
exact extraction approach decided during planning). Extracted text is
attached to the generation run as evidence, alongside (not replacing) any
`KnowledgeChunk` evidence the row's `knowledge_base` already supplies —
treated as pre-approved for this specific import (the person who curated
the spreadsheet is the review step, not the existing
`KnowledgeBase.review_status = reviewed` gate, which stays scoped to
persistent knowledge bases as today). A URL that fails to fetch (dead link,
blocked, non-HTML response) is not fatal to that row — generation proceeds
without that URL's content as evidence, but the URL is still retained for
the citation list below, so a temporarily-unreachable-but-valid source
doesn't silently disappear from the published article.

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

**`Title` model extension**: add nullable `core_question` (string) and
`reference_urls` (JSON) columns to `titles` (alongside the existing `title`/
`keyword` columns), so each imported row's full detail survives at the
per-title level inside the shared Title Library, not just Task-level
config. Component 4's "generate a new draft" branch reads these (when
present) the same way it reads the picked title, instead of only deriving
`core_question` from `topic`.

**Import flow, end to end**: parse the spreadsheet (skipping the
instruction row) → validate every row (unresolvable `site`/`preset`/
`category`/`knowledge_base`, same-group conflicts) before creating
anything → group validated rows by `(site, preset, category)` → for each
group, create a new `TitleLibrary` + one `Title` row per group member
(`title`=topic, `keyword`=primary_keyword, `core_question`,
`reference_urls`) → create one `Task` via the same creation path
`/tasks/create` uses (`generation_engine=quality_gated`,
`prompt_preset_id`=preset, `distribution_channel_ids`=[site's channel],
`category_mode=fixed`, `fixed_category_id`=category,
`knowledge_base_ids`=[group's knowledge_base], `title_library_id`=the new
library, `article_limit`=`draft_limit`=row count in group, `is_loop=false`).
`need_review` for the created Task(s) is a single setting asked once for
the whole import batch, not per-row. From here, everything is Component 4's
existing automation — no new distribution or adoption logic in this
component.

Related, deferred (not built here, noted so it isn't lost):

- **Knowledge base reorganization**: the user's stated direction is a
  directory structure — site as the top-level folder, containing multiple
  knowledge bases, with the whole site's collection usable as one
  aggregate knowledge base. Once that exists, `knowledge_base` on the
  import sheet can become optional (default to the row's `site`'s
  aggregate). Not designed or built as part of this work.

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
