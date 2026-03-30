# Phase 3.7 Mapping Catalog + Collection-to-Import Bridge Plan

Goal: bridge Phase 3.6 crawl artifacts to Phase 4 import planning by introducing a deterministic target-field catalog, richer section-archetype mapping, first-class media handling, and a guided mapping UX flow (`Map Collection for Imports`).

## Current Slice Status

- Implemented:
  - `W0` contract/settings foundation:
    - feature flags added: `dbvc_cc_flag_mapping_catalog_bridge`, `dbvc_cc_flag_media_mapping_bridge`
    - contract constants added for 3.7 artifact file/suffix naming
    - mapping/media policy settings defaults + sanitizers added to `dbvc_cc_settings`
    - PHPUnit coverage added: `tests/phpunit/ContentMigrationPhase37W0SettingsTest.php`
  - `W1` target catalog foundation:
    - service added: `mapping-catalog/dbvc-cc-target-field-catalog-service.php`
    - REST controller added: `mapping-catalog/dbvc-cc-target-field-catalog-rest-controller.php`
    - module wiring added behind `dbvc_cc_flag_mapping_catalog_bridge`
  - `W1A` Vertical field-context bridge foundation:
    - shared provider service added: `shared/dbvc-cc-field-context-provider-service.php`
    - `acf_catalog` now carries additive `field_context` provider metadata plus per-group and per-field resolved field-context payloads when Vertical exposes the same-runtime provider
    - deterministic section-field candidate generation now considers resolved `name_path` and purpose hints from the normalized field-context payload
  - `W4` metadata-first media candidate foundation:
    - service added: `mapping-media/dbvc-cc-media-candidate-service.php`
    - REST controller added: `mapping-media/dbvc-cc-media-rest-controller.php`
    - module wiring added behind `dbvc_cc_flag_media_mapping_bridge`
    - host-safety guard integrated with same-origin allowance + allow/deny list support
    - PHPUnit coverage added: `tests/phpunit/ContentMigrationPhase37CatalogMediaTest.php`
  - `W3` deterministic section/field candidate generation:
    - service added: `mapping-workbench/dbvc-cc-section-field-candidate-service.php`
    - REST transport added:
      - `GET dbvc_cc/v1/mapping/candidates`
      - `POST dbvc_cc/v1/mapping/candidates/build`
  - `W5/W6` mapping decision persistence and transport:
    - text decision service added: `mapping-workbench/dbvc-cc-mapping-decision-service.php`
    - media decision service added: `mapping-media/dbvc-cc-media-decision-service.php`
    - REST transport added:
      - `GET dbvc_cc/v1/mapping/decision`
      - `POST dbvc_cc/v1/mapping/decision`
      - `GET dbvc_cc/v1/mapping/media/decision`
      - `POST dbvc_cc/v1/mapping/media/decision`
    - PHPUnit coverage added: `tests/phpunit/ContentMigrationPhase37MappingDecisionTest.php`
  - `W7` baseline UI bridge implementation (incremental, non-React):
    - Explorer node action adds `Map Collection for Imports` deep-link into Workbench (`domain/path` query prefill).
    - Workbench includes `Map Collection for Imports` controls for:
      - catalog build/refresh
      - section candidate mapping (`suggested`, `override`, `ignore`)
      - media candidate mapping with preview cards (`suggested`, `override`, `ignore`)
      - load/save decision state for text and media mapping artifacts
    - Workbench payload inspector added for catalog/candidates/decisions to support QA before Phase 4.
  - `W8` QA + regression gates:
    - deterministic rerun assertions added for catalog fingerprint, section candidates, and media candidates.
    - fixture-locked contract snapshots added for catalog, section candidates, media candidates, mapping decisions, and media decisions.
    - PHPUnit coverage added: `tests/phpunit/ContentMigrationPhase37RegressionGateTest.php`
    - fixture set added under `addons/content-migration/tests/fixtures/mapping/*.expected.json`.
  - `W9` security/compliance hardening:
    - media candidate payloads now include explicit `policy`, `provenance`, `preview_status`, and `policy_trace` fields.
    - blocked-host traces are surfaced via `stats.blocked_url_examples` and explicit reason codes.
    - preview suppression policy enforced in payload generation (`remote_allowed`, `disabled_by_policy`, `blocked_mime`, `not_image`).
    - mapping/media decision artifacts now persist provenance actor/timestamp policy stamps and preserve stable upsert `generated_at`.
  - `W10` handoff bridge + runbook pass:
    - new handoff service added: `import-plan/dbvc-cc-import-plan-handoff-service.php`.
    - new REST transport added: `GET dbvc_cc/v1/mapping/handoff`.
    - Workbench mapping panel now includes `Preview Dry-Run Handoff` action and handoff summary/debug payload views.
    - runbook added: `docs/PHASE3_7_TO_PHASE4_HANDOFF_RUNBOOK.md`.
    - PHPUnit coverage added: `tests/phpunit/ContentMigrationPhase37HandoffBridgeTest.php`.
  - `W10.1` pre-Phase4 contract freeze:
    - `handoff_schema_version` + `handoff_generated_at` added to transport payload.
    - deterministic ordering enforced for mapping/media rows and warning lists.
    - warning taxonomy frozen to `code`, `message`, `blocking`.
    - trace metadata added (`source_pipeline_id`, artifact references).
    - fixture-locked `ready` and `needs_review` handoff snapshots added.
    - explicit persistence decision: no new custom DB table required; file-first artifact persistence remains canonical for this stage.
  - `W10.2` Workbench domain selector hardening:
    - new Workbench-native endpoint added: `GET dbvc_cc/v1/workbench/domains`.
    - Workbench UI domain selectors now source from Workbench endpoint first, with Explorer endpoint fallback for backward compatibility.
    - reduces coupling between Mapping Workbench UI and Explorer route availability.
  - `W10.3` async rebuild + dry-run wiring:
    - domain rebuild endpoint now supports async queue batches (`POST dbvc_cc/v1/mapping/domain/rebuild`) with progress polling (`GET dbvc_cc/v1/mapping/domain/rebuild/status`).
    - new Phase 4 alignment endpoint added: `GET dbvc_cc/v1/import-plan/dry-run`.
    - Workbench mapping panel now includes `Generate Dry-Run Plan` with summary/debug payload rendering.
    - PHPUnit coverage extended for mapping rebuild batch status and dry-run plan endpoint payloads.
  - `W10.4` AI refresh chunking + queue auto-refresh hardening:
    - domain AI refresh now supports chunk queue paging (`max_jobs`, `offset`) with Workbench default chunk size of 50.
    - Workbench AI refresh polling now retries on timeout and auto-refreshes review queue after AI batches complete.
    - Workbench now surfaces a dedicated small progress note for AI chunk processing.
- Next slice target:
  - W10.5 pre-Phase4 QA hardening:
    - add LocalWP end-to-end admin QA script coverage for `workbench/domains` and `mapping/handoff`.
    - add negative-path REST tests for permission and invalid input handling on `workbench/domains` and `mapping/handoff`.
  - W10.6 pre-Phase4 approval freeze:
    - lock operator decisions for unresolved handling, smart object-type behavior, media ingest defaults, and duplicate-media policy.
    - complete runbook sign-off checklist before beginning Phase 4 implementation.
  - Optional React/Vue uplift remains deferred until after Phase 4 dry-run contracts are stable.

## Decisions Confirmed

1. `dbvc_cc_target_field_catalog` is auto-built when the user starts `Map Collection for Imports`.
2. Catalog output mirrors DBVC entity/export JSON shape but stores field/structure metadata only (no content values).
3. Catalog includes CPT, taxonomy, and term-level structures plus registered meta/ACF field definitions.
4. Section assignment/archetype detection remains in-crawl and is expanded before import planning.
5. Media is first-class in mapping, not a post-import cleanup task.
6. Drag/drop stays as explicit reviewer override, not the primary mapping engine.
7. Auto-mapping remains deterministic-first; AI is refinement-only for unresolved ambiguity.
8. No new DB tables are required for Phase 3.7; artifacts remain file-based under addon storage.

## Why Phase 3.7 Exists

Phase 3.6 added structured content artifacts (`elements`, `sections`, `context`, `section typing`, `ingestion package`), but import planning still needs a deterministic target model for where content and media can go on this specific site.

Phase 3.7 provides that target model and closes the UX gap between `Explore`/`Workbench` and `Generate Dry-Run Plan`.

## Scope

In scope:
- Target field catalog artifact generation and refresh.
- Section-archetype enrichment for mapping readiness.
- Deterministic section/element -> target field candidate generation.
- Deterministic media candidate inventory, classification, and field targeting.
- AI-assisted candidate refinement for unresolved textual and media slots.
- Workbench UI updates to support mapping, drag/drop overrides, and image previews.
- REST contracts + fixtures for catalog, candidates, media, and decisions.

Out of scope:
- Import writes (Phase 4).
- Multisite.
- Gutenberg/block-template suggestion features.
- Full remote media library backfill beyond selected mapping candidates.

## Core Artifact Contracts

### 1) `dbvc_cc_target_field_catalog.v1.json` (domain-level)
Suggested path:
- `uploads/{storage_path}/{domain}/_schema/dbvc_cc_target_field_catalog.v1.json`

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` (`target-field-catalog.v1`)
- `domain`
- `generated_at`
- `catalog_fingerprint`
- `source_artifacts`
- `cpt_catalog`
- `taxonomy_catalog`
- `term_catalog`
- `meta_catalog`
- `acf_catalog`
- `media_field_catalog`
- `stats`

Notes:
- Store field definitions only.
- Do not store site content values.
- Include keys/types/constraints/options where available.
- `media_field_catalog` contains image/video/file-capable target fields and accepted mime/type hints.

### 2) `*.section-field-candidates.v1.json` (page-level)
Suggested path:
- `{slug}.section-field-candidates.v1.json`

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` (`section-field-candidates.v1`)
- `source_url`
- `generated_at`
- `catalog_fingerprint`
- `sections[]`

Per-section fields:
- `section_id`
- `section_archetype` (`hero`, `intro`, `features`, `faq`, `cta`, `contact`, `pricing`, `content`, etc.)
- `deterministic_candidates[]`
- `ai_candidates[]`
- `unresolved_fields[]`
- `confidence_summary`
- `evidence_element_ids[]`
- `evidence_media_ids[]`

### 3) `*.media-candidates.v1.json` (page-level)
Suggested path:
- `{slug}.media-candidates.v1.json`

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` (`media-candidates.v1`)
- `source_url`
- `generated_at`
- `catalog_fingerprint`
- `media_items[]`
- `stats`

Per-media-item fields:
- `media_id` (deterministic stable ID)
- `media_kind` (`image` | `video` | `audio` | `file` | `embed`)
- `source_url`
- `normalized_url`
- `source_element_id`
- `source_section_id`
- `mime_guess`
- `dimensions` (`width`, `height` when known)
- `alt_text`
- `caption_text`
- `surrounding_text_snippet`
- `role_candidates[]` (`featured_image`, `hero_background`, `inline_illustration`, `gallery_item`, `logo`, `icon`, `video_embed`, `download_asset`)
- `quality_signals` (resolution, extension, file-size hint, duplicate score)
- `ingest_policy` (`remote_only`, `download_selected`, `skip`)
- `local_asset_candidate` (relative artifact path if downloaded)
- `preview_ref` (thumbnail/placeholder path or remote URL)
- `ai_enrichment` (optional)

### 4) `*.mapping-decisions.v1.json` (page-level)
Suggested path:
- `{slug}.mapping-decisions.v1.json`

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` (`mapping-decisions.v1`)
- `source_url`
- `generated_at`
- `catalog_fingerprint`
- `decision_status`
- `approved_mappings[]`
- `approved_media_mappings[]`
- `overrides[]`
- `rejections[]`
- `unresolved_fields[]`
- `unresolved_media[]`

### 5) `*.media-decisions.v1.json` (page-level)
Suggested path:
- `{slug}.media-decisions.v1.json`

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` (`media-decisions.v1`)
- `source_url`
- `generated_at`
- `catalog_fingerprint`
- `decision_status`
- `approved[]`
- `overrides[]`
- `ignored[]`
- `conflicts[]`

## Catalog Build Strategy

Build order:
1. Latest schema snapshot artifact (canonical baseline).
2. Runtime registries (`get_post_types`, `get_taxonomies`, `register_meta` data).
3. ACF field groups/fields when plugin is active.
4. Existing DBVC artifacts/export metadata as supplemental hints.
5. Media-capable target detection from field metadata (`image`, `gallery`, `file`, URL/media object patterns).

Cache and refresh:
- Auto-build on `Map Collection for Imports` entry.
- If catalog fingerprint unchanged, reuse cached artifact.
- Provide `Refresh Catalog` action for manual rebuild.

Fingerprint inputs:
- Snapshot hash.
- Active plugin/theme versions (optional).
- Registered post type/taxonomy/meta signatures.
- ACF field group revision signature (if present).
- Media field signature digest.

## Sectioning + Media Enrichment (Phase 3.7 extension of 3.6)

Enhance section outputs with:
- `section_archetype`
- `archetype_confidence`
- `archetype_mode` (`deterministic` or `ai`)
- `archetype_evidence[]` (signals + element IDs)
- `section_media_ids[]`
- `section_media_roles[]`

Deterministic signals include:
- Heading patterns (`faq`, `contact`, `pricing`, `about`, etc.).
- CTA density / link intent.
- List density and question-answer patterns.
- Position signals (first section hero/intro bias).
- Media patterns (first visual hero bias, gallery cluster detection, logo/header detection, inline decorative ratio).

AI usage:
- Only when deterministic confidence is below threshold.
- Never remove deterministic trace data.
- Never auto-apply media role upgrades without explicit reviewer confirmation.

## Media Capture + Preparation Strategy

Discovery sources:
1. `img`, `picture/source`, `figure`, CSS background-image references when deterministic and parseable.
2. `video`, `source`, `iframe` embeds (YouTube/Vimeo/Wistia/etc. identified as `embed` unless explicit asset URL).
3. `a[href]` to file-like targets (`pdf`, `docx`, media MIME patterns).
4. Existing `media_refs` attached to extracted elements from Phase 3.6.

Acquisition policy:
- Default: metadata-first (`remote_only`) to avoid heavy crawl writes.
- Optional: `download_selected` for reviewer-approved candidates.
- Strict file-size and mime allowlist caps from Configure defaults.

Preview strategy:
- For downloaded images: generate deterministic thumbnail sidecars.
- For remote images: display hotlinked preview with fallback placeholder.
- For non-image media: display typed cards with icon + metadata.

Deduplication strategy:
- `normalized_url` + `content_hash` (if downloaded) + optional visual hash (`image_phash`) for near-duplicate grouping.
- Preserve all source references but group duplicates under one `canonical_media_id`.

## Mapping Pipeline

### Deterministic first pass (required)
Text examples:
- `h1` / top section heading -> title-like target field.
- FAQ section -> repeater/group fields containing `question`/`answer`.
- CTA section -> button text/url fields.
- Contact section -> phone/email/address fields.

Media examples:
- First high-resolution hero image candidate -> hero/featured image fields.
- Section with repeated similarly-sized images -> gallery/repeater image candidates.
- Image near CTA with logo-like dimensions -> logo/brand image candidates.
- Video embed URL -> embed/video URL target fields.

### AI second pass (optional, gated)
- Resolve ambiguous or multiple-candidate textual fields.
- Refine media role ambiguity (`hero` vs `inline`, `service-icon` vs `logo`, `embed` destination selection).
- Propose ranked alternatives with rationale.
- Never auto-commit mappings.

### Human approval layer
- Drag/drop for overrides (text and media).
- Approve/reject/edit/ignore per candidate row.
- Keep unresolved queues explicit for both text and media.

## UI/UX Bridge Plan

Entry point:
- From `Explore` node or `Workbench` queue: `Map Collection for Imports`.

Primary flow:
1. `Catalog` step: auto-build status, fingerprint, refresh control.
2. `Section Mapping` step: section list + archetypes + candidate targets.
3. `Field Mapping` step: drag/drop override board (source element -> target field).
4. `Media Mapping` step: media inventory + preview + target field mapping.
5. `Review` step: unresolved/conflict counters and approvals.
6. `Generate Dry-Run Plan` CTA (Phase 4 handoff).

Media mapping UX requirements:
- Preview panel with thumbnail grid for images and typed cards for non-image media.
- Side-by-side source context (`section`, nearby text, role hints) and target options.
- Inline confidence + rationale badges for auto-mapped media.
- Bulk actions: `approve all high-confidence`, `ignore selected`, `set role`, `set target field`.
- Deterministic fallback placeholder for missing/blocked image previews.

UX constraints:
- Keep mapping context and evidence visible.
- Show confidence/rationale badges.
- Disable import execution actions; only dry-run handoff is enabled.

## Proposed REST Contracts (Phase 3.7)

Namespace: `dbvc_cc/v1`

- `POST /mapping/catalog/build`
  - builds or reuses catalog and returns fingerprint/status.
- `POST /mapping/catalog/refresh`
  - forced rebuild.
- `GET /mapping/catalog`
  - returns catalog for domain.
- `GET /mapping/candidates`
  - returns section-field candidates for selected node/path.
- `GET /mapping/media/candidates`
  - returns media candidates with preview references and role hints.
- `POST /mapping/decision`
  - persists approved mappings/overrides/rejections.
- `GET /mapping/decision`
  - returns existing decision state for node/path.
- `POST /mapping/media/decision`
  - persists media mapping approvals/overrides/ignores.
- `GET /mapping/media/decision`
  - returns existing media decision state.
- `GET /mapping/handoff`
  - returns deterministic Phase 4 dry-run planner input preview (read-only).
  - includes `handoff_schema_version`, deterministic ordering guarantees, and trace metadata.
- `GET /workbench/domains`
  - returns available crawl domains for Workbench queue and mapping selectors.

## Persistence Model

Phase 3.7 default persistence:
- File-first artifacts under addon storage path (`uploads/{storage_path}/{domain}/...`).
- Existing option-backed settings for defaults/policies.
- No new custom WordPress DB tables in this phase.

Pre-Phase4 note:
- Handoff contract freeze (`W10.1`) remains file-first and does not introduce new DB tables.

Escalation condition:
- If artifact listing/query performance degrades at scale, evaluate optional index table in Phase 4.5 without changing Phase 3.7 artifact contracts.

## Workstreams and Tasks

### W0 Contracts + Flags
- Add contract constants for 3.7 artifacts and REST route keys.
- Add feature flags:
  - `dbvc_cc_flag_mapping_catalog_bridge`
  - `dbvc_cc_flag_media_mapping_bridge`
- Add settings for catalog refresh strategy, candidate thresholds, and media policy defaults.

### W1 Target Field Catalog Service
- Build `DBVC_CC_Target_Field_Catalog_Service`.
- Implement artifact write/load/fingerprint logic.
- Add schema snapshot + runtime registry + ACF merger.
- Add `media_field_catalog` extraction from field definitions.

### W2 Section Archetype Extension
- Extend section payloads with archetype fields.
- Add deterministic signal expansion.
- Add optional AI refinement path with fallback.
- Persist section-level `section_media_ids` and `section_media_roles`.

### W3 Text Candidate Generator Service
- Build deterministic section-field candidate rules.
- Attach evidence IDs and confidence values.
- Persist `section-field-candidates.v1`.

### W4 Media Candidate Inventory Service
- Build `DBVC_CC_Media_Candidate_Service`.
- Parse media sources from elements/DOM references/context bundles.
- Normalize URLs and attach section/element trace metadata.
- Apply deterministic role classification.
- Optional selective download pipeline for approved candidates.
- Persist `media-candidates.v1` with preview references.

### W5 Mapping Decision Services
- Extend text decision persistence for media-aware status counters.
- Build `DBVC_CC_Media_Decision_Service` for media approval/override/ignore.
- Ensure idempotent decision upsert behavior.
- Expose decision state for UI restore.

### W6 REST + Transport
- Add catalog/candidates/decision endpoints with permission parity.
- Add media candidates/media decision endpoints.
- Add sanitization, path-guard checks, and preview URL hardening.
- Add response fixtures and schema tests.

### W7 Workbench/Explore UI Bridge
- Add `Map Collection for Imports` entry points.
- Add textual mapping board with drag/drop override interactions.
- Add media mapping panel with image previews, typed cards, and bulk actions.
- Add unresolved/conflict summary with explicit text + media status.

### W8 QA + Regression Gates
- Deterministic rerun tests for catalog fingerprint and candidate stability.
- Catalog completeness tests for CPT/tax/meta/ACF structures.
- Media candidate determinism tests (stable IDs, role classification consistency).
- Preview fallback tests for blocked/missing remote media.
- Contract fixtures for catalog, candidates, media candidates, and decisions.

### W9 Security + Compliance Gates
- Enforce mime/size/domain allowlist validation.
- Block private/local-network media URLs.
- Add provenance fields (`source_url`, `license_hint` if known, decision actor/timestamp).
- Verify no secrets/values leakage in catalog artifacts.

### W10 Documentation + Handoff
- Update source-to-target map and README status.
- Add implementation runbook for catalog rebuild + media mapping flow.
- Define Phase 4 handoff contract from approved decisions to dry-run planner.
- Document media import-plan payload expectations (`download`, `attach`, `reuse`, `ignore`).

## Risks and Mitigations

1. Large taxonomy/term sets increase payload size.
- Mitigation: paged REST transport + full artifact on disk.

2. ACF optional dependency may be absent.
- Mitigation: degrade to registered meta + schema snapshot without fatal.

3. Catalog staleness after field schema edits.
- Mitigation: fingerprint mismatch detection + refresh CTA.

4. Over-reliance on AI mapping.
- Mitigation: deterministic-first pipeline and explicit approval requirement.

5. Remote media availability can change between crawl and mapping.
- Mitigation: preview cache + fallback placeholders + explicit `last_checked_at`.

6. Media volume can increase storage and processing cost.
- Mitigation: metadata-first default policy, selective download, hard limits.

7. Duplicate or near-duplicate visuals can confuse mapping decisions.
- Mitigation: canonical media grouping with deterministic duplicate scores.

## Acceptance Criteria

Functional:
- `Map Collection for Imports` auto-builds or reuses target catalog.
- Catalog includes CPT, taxonomy, term, and meta field structures without values.
- Section-field candidates are generated with deterministic evidence.
- Media candidates are generated with deterministic IDs, role hints, and preview references.
- Reviewer decisions (text + media) persist and reload idempotently.
- Mapping flow can hand off to Phase 4 dry-run planner inputs.

Quality:
- Catalog/candidate/media artifacts are fixture-locked.
- Deterministic reruns keep stable candidate IDs/rankings on unchanged input.
- Preview rendering degrades gracefully for unavailable media.
- No content values or secrets leak into catalog artifacts.

Governance:
- Guardrails remain enforced (dry-run gate, idempotency, security parity).
- Feature flags can disable 3.7 mapping bridge safely.

## Recommended Pre-Phase 4 Closeout Slice

1. W10.3 QA hardening follow-up:
- Add negative-path REST tests for permission and invalid input handling on `workbench/domains` and `mapping/handoff`.
- Add LocalWP smoke script for `Map Collection for Imports` end-to-end (catalog -> candidates -> decisions -> handoff preview).

2. W10.4 approval freeze:
- Confirm operator decisions listed in `PHASE3_7_TO_PHASE4_HANDOFF_RUNBOOK.md` under `Direct Input Required Before Phase 4 Approval`.
- Lock these as canonical Phase 4 defaults before implementation starts.

3. Optional UX uplift (deferred):
- Keep current non-React baseline as canonical until Phase 4 contracts are stable.
- Revisit interactive drag/drop enhancements once dry-run planner handoff is finalized.
