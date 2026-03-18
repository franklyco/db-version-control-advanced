# Phase 3.6 Deep Capture + Context + AI Section Typing Plan

Goal: evolve crawl output from flat page-level content into a deterministic, high-context content model that improves downstream AI mapping, schema inference, and import planning.

## Current Slice Status

- Implemented in this slice:
  - `W0` foundations for deep-capture and scrub policy settings/flags/contracts.
  - `W1` sidecar deep elements artifact generation (`*.elements.v2.json`).
  - `W1A` deterministic attribute scrub engine and scrub report artifact (`*.attribute-scrub-report.v2.json`).
  - `W2` deterministic section segmentation artifact generation (`*.sections.v2.json`).
  - `W3` context bundle artifact generation (`*.context-bundle.v2.json`) behind feature flag.
  - `W4` section-typing artifact generation (`*.section-typing.v2.json`) with deterministic fallback mode.
  - `W5` ingestion package artifact generation (`*.ingestion-package.v2.json`).
  - `W6` explorer/admin UX integration:
    - Phase 3.6 sidecar summaries in Explore content preview.
    - mode/profile badges (`capture`, `section typing`, `scrub`) in node inspector and preview.
    - Explore content-context inspector with artifact selector + sidecar payload viewer.
    - Collect tab advanced override controls prefilled from Configure defaults.
  - `W7` API integration:
    - Explorer sidecar retrieval endpoint: `GET dbvc_cc/v1/explorer/content-context`.
    - Deterministic scrub policy suggestion preview endpoint: `GET dbvc_cc/v1/explorer/scrub-policy-preview`.
    - Scrub suggestion approval endpoints:
      - `GET dbvc_cc/v1/explorer/scrub-policy-approval-status`
      - `POST dbvc_cc/v1/explorer/scrub-policy-approve`
    - Contract tests: `tests/phpunit/ContentMigrationExplorerContextTest.php`.
    - Fixture snapshots:
      - `addons/content-migration/tests/fixtures/explorer/content-context.expected.json`
      - `addons/content-migration/tests/fixtures/explorer/scrub-policy-preview.expected.json`
      - `addons/content-migration/tests/fixtures/explorer/content-preview-phase36.expected.json`
      - `addons/content-migration/tests/fixtures/explorer/scrub-policy-approval-status.expected.json`
      - `addons/content-migration/tests/fixtures/explorer/scrub-policy-approve.expected.json`
      - `addons/content-migration/tests/fixtures/explorer/node-audit.expected.json`
  - `W8/W9` hardening:
    - phase3.6 stage-level structured events with `pipeline_id` across:
      - `extract`
      - `attribute_scrub`
      - `segment`
      - `context_bundle`
      - `section_typing`
      - `ingestion_package`
    - exception-safe stage execution with error events and partial-artifact continuation.
    - chunked extraction + segmentation processing with stage timeout caps.
    - `processing` metadata on `elements.v2` and `sections.v2` (partial flag, reason, resume marker, chunk/timeout diagnostics).
    - policy-hash telemetry in scrub stage events.
    - observability payload sanitization pass for stage events (secret/token redaction guard).
    - explorer audit summary/event transport includes per-pipeline rollups.
    - context-bundle privacy metadata includes `pii_hint_tags` and redaction-rule summary.
    - PHPUnit hardening coverage: `tests/phpunit/ContentMigrationPhase36HardeningTest.php`.
  - `W10` partial regression gates:
    - deterministic rerun coverage for element extraction and section segmentation.
    - fallback parity coverage for section typing behavior when AI flags differ.
    - leak-guard coverage for scrub outputs and observability redaction of API/token-like strings.
    - fixture-backed explorer transport coverage for:
      - partial/resume processing marker payloads (`content-context-partial.expected.json`)
      - pipeline-filtered node audit snapshots (`node-audit-filtered.expected.json`)
    - LocalWP runtime crawl smoke verification:
      - `CRAWL_RESULT:ok`
      - phase3.6 sidecar presence confirmed (`ELEMENTS_FILE:1`, `SCRUB_FILE:1`)
    - PHPUnit suites:
      - `tests/phpunit/ContentMigrationPhase36DeterminismTest.php`
      - `tests/phpunit/ContentMigrationPhase36LeakGuardTest.php`
  - Configure subtab scaffold: `General` + `Advanced Collection Controls`.
- Next slice target:
  - Phase 3.7 implementation follow-up:
    - `docs/PHASE3_7_MAPPING_CATALOG_IMPORT_BRIDGE.md`
    - target-field catalog, first-class media mapping, and bridge work before Phase 4.

## Why This Phase Exists

Current crawl artifacts are good for baseline capture but limited for advanced ingestion logic. We need richer structure before Phase 4 so dry-run planning and execution can rely on stronger, reusable signals.

This phase adds four linked capabilities:
1. Deep structured element capture.
2. Deterministic context packaging before AI.
3. Advanced AI section-type narrowing with deterministic fallback.
4. Advanced attribute scrub controls with deterministic policy enforcement.

## Scope and Constraints

In scope:
- Deep crawl artifact schema redesign (beta-safe contract upgrade).
- Deterministic content-model pipeline.
- New AI layer for section-type narrowing.
- Configurable attribute scrub policy for element/section/context artifacts.
- Explorer/admin visibility for new artifacts.
- Settings + collect override flow for new controls.

Out of scope:
- Multisite.
- Gutenberg/block template suggestion features.
- Phase 4 import writes.

Guardrails enforced:
- Prefix isolation: all new symbols `dbvc_cc_*` / `DBVC_CC_*`.
- No runtime dependency on `_source/content-collector`.
- Modular implementation under `addons/content-migration/`.
- Dry-run remains required before import writes.
- Idempotent upsert behavior remains baseline for all future write stages.
- Deterministic non-AI fallback remains mandatory.

## Contract Strategy (Beta Reset Allowed)

Because this addon has only been used in beta, Phase 3.6 intentionally introduces a cleaner artifact contract instead of preserving transitional legacy structures.

Planned contract handling:
- Bump `DBVC_CC_Contracts::ADDON_CONTRACT_VERSION` at Phase 3.6 start.
- Introduce `artifact_schema_version` field in all new Phase 3.6 artifacts.
- Treat Phase 3.6 schemas as canonical for Phase 4 input.
- Refresh fixtures for collector/explorer/ai/workbench contracts where payload shape changes.
- Document any endpoint response changes with explicit version notes in module docs.

## Proposed Module Additions

New module boundary (recommended):
- `addons/content-migration/content-context/`

Planned files:
- `addons/content-migration/content-context/dbvc-cc-content-context-module.php`
- `addons/content-migration/content-context/dbvc-cc-element-extractor-service.php`
- `addons/content-migration/content-context/dbvc-cc-attribute-scrub-policy-service.php`
- `addons/content-migration/content-context/dbvc-cc-attribute-scrubber-service.php`
- `addons/content-migration/content-context/dbvc-cc-section-segmenter-service.php`
- `addons/content-migration/content-context/dbvc-cc-section-typing-service.php`
- `addons/content-migration/content-context/dbvc-cc-context-bundle-service.php`
- `addons/content-migration/content-context/dbvc-cc-ingestion-package-service.php`
- `addons/content-migration/content-context/dbvc-cc-content-context-rest-controller.php`

Planned updates in existing modules:
- `collector/`: wire deep capture + context generation into crawl pipeline.
- `ai-mapping/`: add section-type narrowing stage and status tracking.
- `explorer/`: expose element/section/context views.
- `settings/`: add defaults + sanitization for new controls.
- `observability/`: add stage-level structured events.

## Data Model and Artifacts (Phase 3.6 Canonical)

Per crawled page, planned artifacts:

1. `*.elements.v2.json`
- One record per captured textual element.
- Includes DOM and semantic context.

Planned fields:
- `element_id` (stable deterministic ID per page and sequence).
- `tag` (`h1`, `h2`, `p`, `li`, `blockquote`, etc.).
- `text` (normalized text).
- `text_hash`.
- `sequence_index`.
- `dom_path` (CSS-like or XPath path).
- `parent_tag`.
- `heading_context` (nearest h1-h6 stack).
- `attributes` (safe subset only, e.g., `id`, `class`, `aria-*`, `data-*` whitelist).
- `attribute_scrub` (applied policy profile, ruleset hash, and scrub action counts for this element).
- `link_target` (if anchor-derived textual node).
- `media_refs` (if element references media).

2. `*.sections.v2.json`
- Deterministic grouping of elements into section candidates.

Planned fields:
- `section_id`.
- `section_label_candidate` (heuristic label from heading context).
- `start_sequence_index` and `end_sequence_index`.
- `heading_anchor_element_id`.
- `element_ids[]`.
- `signals` (e.g., heading pattern, list density, CTA pattern).

3. `*.context-bundle.v2.json`
- Hybrid AI-ready package with deterministic pre-analysis.

Planned fields:
- `page_context` (url, slug, title, template hints, language hints).
- `outline` (hierarchical heading representation).
- `sections[]` enriched with deterministic signals.
- `repetition_hints` and boilerplate markers.
- `entity_hints` (phones, emails, addresses, pricing cues, FAQ cues).
- `link_graph_hints` (internal/external link patterns).
- `trace_map` (section -> element IDs).
- `attribute_scrub_summary` (aggregated scrub outcomes for included elements).

4. `*.section-typing.v2.json`
- AI + fallback section-type output.

Planned fields:
- `section_id`.
- `section_type_candidate`.
- `confidence`.
- `mode` (`ai` or `fallback`).
- `rationale`.
- `evidence_element_ids[]`.
- `alternate_candidates[]`.

5. `*.ingestion-package.v2.json`
- Final pre-import representation consumed by later planning stages.

Planned fields:
- canonical page metadata.
- normalized section objects with section types.
- extracted entities and mapped field hints.
- traceability links back to element IDs.

6. `*.attribute-scrub-report.v2.json`
- Crawl-level report describing scrub actions and policy application.

Planned fields:
- `policy_version`.
- `policy_hash`.
- `profile` (`deterministic-default`, `custom`, `ai-suggested-approved`).
- `totals` (kept/dropped/hashed/tokenized counts).
- `by_attribute` (e.g., `class`, `id`, `data-*`, `style`).
- `warnings` (over-scrub or policy conflict markers).

## Settings and Override Model

Configure tab default settings (new):
- `capture_mode` (`standard` | `deep`, default `deep`).
- `capture_include_attribute_context` (bool).
- `capture_include_dom_path` (bool).
- `capture_max_elements_per_page` (int cap).
- `capture_max_chars_per_element` (int cap).
- `context_enable_boilerplate_detection` (bool).
- `context_enable_entity_hints` (bool).
- `ai_enable_section_typing` (bool).
- `ai_section_typing_confidence_threshold` (float).
- `scrub_policy_enabled` (bool).
- `scrub_profile_mode` (`deterministic-default` | `custom` | `ai-suggested-approved`).
- `scrub_attr_action_class` (`keep` | `drop` | `hash` | `tokenize`).
- `scrub_attr_action_id` (`keep` | `drop` | `hash` | `tokenize`).
- `scrub_attr_action_data` (`keep` | `drop` | `hash` | `tokenize`).
- `scrub_attr_action_style` (`drop` hard default).
- `scrub_attr_action_aria` (`keep` hard default with optional allowlist narrowing).
- `scrub_custom_allowlist` (newline or CSV list).
- `scrub_custom_denylist` (newline or CSV list).
- `scrub_ai_suggestion_enabled` (bool; never auto-apply).
- `scrub_preview_sample_size` (int cap).

Collect tab per-run overrides:
- Prefill from Configure defaults.
- Apply only to current crawl execution.
- No persistence unless explicitly saved in Configure.

Configure tab subtab layout (planned):
- `General`: existing core and default crawl settings.
- `Advanced Collection Controls`: deep capture limits, context toggles, section-typing thresholds, and attribute scrub policy controls.

## Workstreams and Task Breakdown

### W0: Contracts, Flags, and Foundations
T0.1 Add new feature flags:
- `dbvc_cc_flag_deep_capture`
- `dbvc_cc_flag_context_bundle`
- `dbvc_cc_flag_ai_section_typing`
- `dbvc_cc_flag_attribute_scrub_controls`

T0.2 Add settings defaults and sanitizers for new controls.

T0.3 Bump contract version and define artifact version constants.

T0.4 Add attribute scrub contract constants:
- policy profile constants
- action constants (`keep`, `drop`, `hash`, `tokenize`)
- report schema version constants

T0.5 Create fixture directories for v2 artifacts and API outputs.

### W1: Deep Element Extraction
T1.1 Build `DBVC_CC_Element_Extractor_Service` integrated into crawler processing.

T1.2 Capture tag-level textual elements with deterministic ordering.

T1.3 Add content normalization rules:
- whitespace normalization
- Unicode cleanup
- duplicate suppression per page scope

T1.4 Add structural trace metadata:
- `dom_path`
- `heading_context`
- parent-child hints

T1.5 Persist `*.elements.v2.json` artifacts and event logs.

T1.6 Run attribute scrub policy on captured attributes before persistence and emit per-element scrub metadata.

### W1A: Attribute Scrub Policy Engine and Controls
T1A.1 Build `DBVC_CC_Attribute_Scrub_Policy_Service` with deterministic default profiles and validation.

T1A.2 Build `DBVC_CC_Attribute_Scrubber_Service` with supported actions:
- `keep`
- `drop`
- `hash`
- `tokenize`

T1A.3 Add per-artifact scrub profiles:
- `elements_v2` (richer context)
- `context_bundle_v2` (reduced)
- `ingestion_package_v2` (minimal migration-safe)

T1A.4 Add optional AI scrub-rule suggestion endpoint and approval workflow:
- AI may suggest rules.
- Rules are never auto-applied.
- Only approved suggestions become active policy versions.

T1A.5 Persist `*.attribute-scrub-report.v2.json` with action totals and warnings.

### W2: Section Segmentation and Structural Inference
T2.1 Build deterministic section segmentation from heading boundaries and container heuristics.

T2.2 Generate `*.sections.v2.json` with section boundaries and trace maps.

T2.3 Add rule-based section signals:
- hero/intro pattern
- CTA pattern
- FAQ pattern
- contact pattern
- list/grid pattern

T2.4 Enforce deterministic behavior across reruns on identical input.

### W3: Context Bundle Generation
T3.1 Build `DBVC_CC_Context_Bundle_Service` for AI-ready inputs.

T3.2 Aggregate page/section/entity/link/boilerplate signals.

T3.3 Add configurable truncation strategy to stay within AI token budgets while preserving traceability.

T3.4 Apply context-bundle scrub profile and include scrub summary metadata.

T3.5 Persist `*.context-bundle.v2.json` artifacts.

### W4: Advanced AI Section Typing Layer
T4.1 Extend AI pipeline with a section typing stage that consumes context bundles.

T4.2 Persist `*.section-typing.v2.json` artifacts.

T4.3 Add deterministic fallback classifier:
- heading keyword heuristics
- element composition heuristics
- URL and location cues

T4.4 Add confidence threshold logic and review routing hooks.

T4.5 Emit structured status lifecycle including `fallback` mode details.

### W5: Ingestion Package Builder
T5.1 Build `DBVC_CC_Ingestion_Package_Service` to combine sections, typing, and field/entity hints.

T5.2 Persist `*.ingestion-package.v2.json` artifact.

T5.3 Ensure package schema is import-plan ready and traceable to source elements.

### W6: Explorer and Admin UX Integration
T6.1 Add Explorer support for deep artifacts:
- element list view
- section view
- context bundle inspection
- section typing view
- scrub report inspection

T6.2 Add Configure subtab shell under `Configure`:
- `General` subtab (existing settings and defaults)
- `Advanced Collection Controls` subtab (new)

T6.3 Add controls in `Configure > Advanced Collection Controls` for:
- deep capture limits and toggles
- context packaging toggles
- AI section typing thresholds
- attribute scrub policy, attribute action selectors, and preview sample settings

T6.4 Add collect override controls with default prefill behavior for advanced collection settings.

T6.5 Add indicator badges for mode (`standard`, `deep`, `ai`, `fallback`) and scrub profile (`default`, `custom`, `ai-approved`).

### W7: API and Transport Contract Updates
T7.1 Update collector AJAX payload handling to accept new override fields.

T7.2 Add REST endpoints for context artifact retrieval as needed.

T7.3 Add REST endpoint(s) for scrub policy suggestion preview and approval status.

T7.4 Keep permissions, nonce, and sanitization parity.

T7.5 Version and fixture-lock any changed payloads.

### W8: Performance, Reliability, and Resource Budgeting
T8.1 Add hard caps and timeouts for extraction and segmentation stages.

T8.2 Add chunked processing for high-node pages.

T8.3 Add memory-safe failover paths and partial artifact marking.

T8.4 Add resumable processing markers for long-running crawls.

### W9: Security, Privacy, and Observability
T9.1 Ensure no secrets leak into artifacts/logs.

T9.2 Add optional PII hint tagging/redaction metadata in context bundles.

T9.3 Add stage-specific structured events with correlation IDs:
- `extract`
- `attribute_scrub`
- `segment`
- `context_bundle`
- `section_typing`
- `ingestion_package`

T9.4 Log scrub policy ID/hash and action totals without exposing sensitive raw values.

T9.5 Add path guards and directory hardening checks for all new artifact roots.

### W10: QA, Fixtures, and Regression Gates
T10.1 Add fixture baselines for each new v2 artifact type.

T10.2 Add deterministic rerun tests for deep capture and segmentation.

T10.3 Add fallback parity tests for AI-disabled/unavailable states.

T10.4 Add scrub-policy tests:
- deterministic attribute action outputs
- over-scrub detection warnings
- AI suggestion approval gating (no auto-apply)
- secret/token leakage checks in scrub outputs

T10.5 Add admin QA checklist updates for Configure/Collect/Explore deep-capture controls and Configure subtabs.

T10.6 Update source-to-target and handoff docs.

## Unforeseen Items and Recommended Innovations

1. Invalid HTML and parser drift
- Add tolerant parsing mode and error counters per page.
- Preserve extraction stability even on malformed markup.

2. Repeated boilerplate across large sites
- Add template fingerprinting to suppress repeated header/footer blocks.

3. JS-rendered content gaps
- Add explicit `render_mode` metadata (`static_html` now, extensible for headless render later).

4. Extremely large pages
- Add adaptive truncation and progressive extraction windows.

5. Duplicate near-identical sections
- Add section similarity clustering and canonical section hints.

6. Language/locale variance
- Add language hints in context bundle and locale-aware token normalization.

7. Media-context under-capture
- Add media adjacency signals (caption, surrounding heading, nearest paragraph).

8. Link semantics for migration
- Add per-element internal link intent hints (`nav`, `cta`, `reference`, `footer`).

9. Over-scrubbing that removes important mapping context
- Add policy linting to block destructive profiles by default.
- Require preview confirmation for custom or AI-suggested policies.

## Dependencies and Conflict Mitigation

Potential conflicts:
- Existing Explorer assumptions around old artifact shapes.
- AI service expectations tied to prior flattened inputs.
- Crawl runtime performance regression under deep mode defaults.
- Over-scrub policy choices reducing section typing and mapping quality.

Mitigations:
- Feature flag and staged rollout by module.
- Build v2 artifacts alongside existing crawl write path for one stabilization window.
- Add explicit `mode` badges and artifact version labels in UI.
- Add performance guard defaults before enabling deep mode as global default in production.
- Add scrub preview and policy lint checks before policy activation.

## Acceptance Criteria

Functional:
- Deep capture stores deterministic element-level artifacts with tag context.
- Attribute scrub policy is applied per configured profile with traceable reports.
- Context bundle artifacts are generated for crawled pages.
- AI section typing runs with confidence and rationale fields.
- Deterministic fallback section typing works when AI is unavailable.
- Configure defaults prefill Collect overrides for new deep/context settings.
- `Configure` includes a dedicated `Advanced Collection Controls` subtab for these settings.

Quality:
- Rerunning crawl on unchanged page yields stable element IDs and section boundaries.
- Performance caps prevent runaway extraction on large pages.
- No secrets appear in artifacts or logs.
- Explorer can inspect new v2 artifacts without runtime errors.

Governance:
- Contract version bump completed.
- Fixtures updated for changed payloads.
- Guardrail checklist passes.
- Approved-policy-only rule enforced for AI-suggested scrub rules.

## Exit Criteria

- Phase 3.6 artifacts and APIs are stable and fixture-locked.
- Admin + Explorer flows support deep capture and context inspection.
- Admin + Explorer flows expose scrub profiles/reports and subtab settings without runtime errors.
- AI + fallback section typing outputs are consumed by ingestion package builder.
- Phase 4 can begin with ingestion packages as canonical dry-run inputs.

## Suggested Execution Sequence

1. W0 (contracts/settings/flags)
2. W1 (element extraction)
3. W1A (attribute scrub policy engine and controls)
4. W2 (section segmentation)
5. W3 (context bundle)
6. W4 (AI section typing + fallback)
7. W5 (ingestion package)
8. W6-W7 (UI + REST/AJAX integration)
9. W8-W9 (performance/security/observability hardening)
10. W10 (fixtures, QA, docs)

## Next Implementation Round (Recommended)

Implement only W0 + W1 + W1A first:
- Add contract/flag/settings foundations.
- Add deterministic `*.elements.v2.json` generation.
- Add deterministic scrub engine baseline and scrub report artifacts.
- Add `Configure > Advanced Collection Controls` subtab scaffold and settings registration.
- Add fixture seed and smoke tests.

Stop for review before W2+ to validate artifact shape and IDs.
