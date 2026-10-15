# Brand-agnostic Semrush import

The Semrush importer is a preview/confirm workflow on the bulk-content-import page. It accepts Prompt Gap and Topic Gap CSV exports and resolves categories, production presets, knowledge scope, and external-evidence defaults from the selected knowledge brand.

## Brand profile prerequisites

Before importing a brand, create:

- one `KnowledgeBrand` with a stable key and display name;
- one or more `KnowledgeSite` records owned by that brand, with a canonical host;
- brand-owned `KnowledgeDirectory` records when product or policy rows should be scoped locally;
- production `PromptPreset` versions with blueprint templates;
- optional general evidence domains in `config/geoflow.php` under `external_evidence.general_domains`.

If a brand has more than one knowledge site, select the site explicitly in the preview form. The selected site and directory must belong to the selected brand. Preview confirmation is rejected if the brand profile changes after preview; upload again to create a new immutable profile snapshot.

## Knowledge modes

Each accepted row carries a mode into the generated task:

- `brand_auto`: brand facts, products, policies, and service information use the selected brand/site and directory scope.
- `general`: educational, shopping-advice, and industry topics do not retrieve unrelated brand-local chunks. They use general evidence when the policy permits it.
- `mixed`: local brand evidence is used only for brand claims; general claims are routed to general sources.
- `legacy_manual`: rows from the original bulk template continue to use the explicit legacy knowledge-base behavior.

Semrush rows default to `on_insufficient` external evidence. The confirmation form still allows an explicit `off` override. General domains are normalized, persisted on the task, and included in the task group key so rows with different evidence policies are never silently merged.

## Preview and confirmation workflow

1. Open **批量导入文章任务 → 从 Semrush 导出转换导入**.
2. Upload the raw CSV and select the publishing channel and knowledge brand. Select a source site when the brand has multiple sites; directory selection is optional and brand-scoped.
3. Review the accepted topics, mapped category, knowledge mode, duplicate warnings, and quarantined rows. Quarantined rows are never silently imported; resolve the missing category, preset, or directory mapping and preview again.
4. Choose the evidence policy. Keep the recommended `on_insufficient` for generic topics unless an operator explicitly wants local-only generation.
5. Confirm only the checked rows. The encrypted preview token re-runs the deterministic conversion and validates the profile snapshot before creating normal GEOFlow tasks.

## CSV fields

The generated Semrush spreadsheet includes the normal bulk-import fields plus:

| Field | Meaning |
| --- | --- |
| `knowledge_mode` | `brand_auto`, `general`, `mixed`, or `legacy_manual`. |
| `content_type` | Classifier result such as `educational`, `shopping_advice`, `comparison`, `industry`, or `brand_policy`. |
| `external_evidence_policy` | `off` or `on_insufficient`. |
| `external_general_domains` | Comma- or space-separated allow-list for general sources. |
| `brand_profile_snapshot_hash` | Immutable preview profile marker; retained for audit and stale-preview protection. |

The parser also accepts these fields on manually prepared spreadsheets. Existing templates without them remain backward compatible: brand rows become `brand_auto`, legacy knowledge-base rows remain `legacy_manual`, and the controller-level evidence policy remains the fallback.

## Quarantine guidance

Typical quarantine reasons are:

- `no_category_mapping`: no category name matches the brand's configured categories;
- `no_production_preset_mapping`: no enabled production blueprint matches the classified content type;
- `no_brand_directory`: a brand-scoped row requires a local directory but the brand has no root directory.

Do not solve quarantine by assigning another brand's category or preset. Add the missing brand-owned configuration, then upload and preview again.

## Operational verification

Use the focused regression suite after code or configuration changes:

```bash
docker compose exec -T app php artisan test \
  tests/Unit/Semrush \
  tests/Feature/Admin/SemrushImportControllerTest.php \
  tests/Feature/Admin/BulkContentImportControllerTest.php \
  tests/Feature/BulkContentImportTemplateBuilderTest.php \
  tests/Unit/BulkContentImportParserTest.php \
  tests/Feature/ContentGeneration/ExternalEvidenceRoutingTest.php \
  tests/Unit/EvidenceCitationRendererTest.php
```

For a static check:

```bash
find app/Services/GeoFlow/Semrush app/Http/Controllers/Admin \
  app/Domain/ContentGeneration -name '*.php' -print0 | xargs -0 -n1 php -l
```
