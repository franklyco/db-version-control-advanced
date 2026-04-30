# Migration Mapper V2 Vertical Field Context Implementation Guide

## Goal

Implement the Vertical Field Context mapping-accuracy redesign inside the existing Content Migration V2 runtime without creating a parallel mapper, review flow, or package system.

This guide complements `MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_PLAN.md`.

Use the plan doc for phase status and tranche ordering.

Use this guide for implementation rules, file ownership, and delivery boundaries.

## Non-Negotiable Rules

1. Keep Vertical's provider payload as the runtime semantic source of truth when it is available.
2. When the provider catalog is `missing` or `unavailable`, preserve that status truthfully and only use the current WordPress ACF runtime arrays as a purpose-only fallback for the enriched target catalog and slot graph.
3. Use Vertical `acf-json/field-groups/*.json` only for topology reference, coverage checks, benchmarks, and drift diagnostics.
4. Treat `key_path` as opaque. Never parse it to infer hierarchy or clone ownership.
5. Use `value_contract` for shape, write behavior, and reference behavior.
6. Use `location` and normalized `object_context` for object compatibility.
7. Use `clone_context` for clone provenance and publish-policy warnings.
8. Prefer `unresolved` over a weak automatic mapping.
9. Reuse the current V2 artifacts and inspector or package surfaces where practical.
10. Preserve field type, field name, group name, and branch-path metadata in the slot graph and review evidence.
11. Do not infer real frontend component or template names unless Vertical exposes explicit metadata for them.
12. Do not edit or depend on changes inside the Vertical theme repo for this DBVC tranche.
13. Do not start Object Type Context work inside this slice.

## Landed Baseline To Reuse

The redesign should build on these already-landed DBVC pieces:

- `DBVC_CC_Field_Context_Provider_Service` is bootstrapped
- provider normalization already carries:
  - provider and catalog metadata
  - `location -> object_context`
  - `value_contract`
  - `clone_context`
  - warnings and diagnostics
  - `entries_by_key_path`
  - `entries_by_name_path`
  - `entries_by_group_and_acf_key`
- the V2 target field catalog already embeds field-context data at the provider, group, and field levels
- mapping candidates already carry additive field-context evidence
- review payloads and inspector UI already expose compact field-context evidence
- provider-empty catalogs now remain truthfully `missing` while the enriched target catalog and slot graph may backfill purpose text from runtime ACF field/group metadata
- semantic slot-role inference now keeps name-driven roles like `headline` and `cta_label` ahead of generic `wysiwyg`/`textarea` fallback

The next work should extend those contracts, not duplicate them.

## Core Runtime Model

### Source unit

A source unit is the smallest meaningful content item DBVC should map.

Minimum fields:

- `unit_id`
- `section_id`
- `section_family`
- `unit_role`
- `text_summary`
- `heading_context`
- `sibling_index`
- `media_hints`
- `cta_hints`
- source provenance references

### Target slot

A target slot is a write-eligible target projection derived from the current target field catalog.

Minimum fields:

- `target_ref`
- `group_key`
- `group_name`
- `acf_key`
- `acf_name`
- `acf_label`
- `type`
- `container_type`
- `key_path`
- `branch_name_path`
- `branch_label_path`
- `context_chain[]`
- `chain_purpose_text`
- `section_family`
- `slot_role`
- `object_context`
- `value_contract`
- `writable`
- `clone_context`
- `provider_trace`
- `competition_group`

### Candidate

A candidate is an eligible pairing between a source unit and a target slot.

Minimum fields:

- `source_unit_id`
- `target_ref`
- deterministic eligibility results
- score components
- semantic evidence when available
- ambiguity and warning flags
- enough trace for reviewer and QA explanation

## Context Chain Contract

Every slot should expose a `context_chain[]` ordered from highest-level context to lowest-level field context.

Recommended shape:

```json
[
  {
    "level": "group",
    "key_path": "group_...",
    "name_path": "core_group",
    "label": "Core",
    "purpose": "...",
    "type": "group"
  },
  {
    "level": "container",
    "key_path": "...",
    "name_path": "hero_section",
    "label": "Hero Section",
    "purpose": "...",
    "type": "group"
  },
  {
    "level": "field",
    "key_path": "...",
    "name_path": "hero_h1",
    "label": "Hero H1",
    "purpose": "Primary hero headline that states the page's core promise, offer, or outcome.",
    "type": "text"
  }
]
```

Rules:

- preserve provider ancestry as-is where available
- fill missing display metadata from the enriched target catalog only when needed
- never reconstruct hierarchy by splitting `key_path`
- expose a flattened `chain_purpose_text` summary for ranking and review display

## Frontend Surface Metadata Rule

DBVC should capture ACF structural branch identity now.

That means preserving:

- `group_name`
- `branch_name_path`
- `branch_label_path`
- field name and field type

DBVC should not claim a real frontend-rendered component, template part, or section implementation unless Vertical exposes that explicitly.

Allowed future additive fields if Vertical provides them:

- `frontend_surface`
- `render_component`
- `template_part`
- `section_slug`

Until then, structural branch names are the supported context signal.

## Object Applicability Rule

Object applicability must be enforced before semantic scoring.

Examples:

- `services_group` should not enter a `page` candidate pool
- page-only groups should not enter a `service` candidate pool
- taxonomy or options groups should only enter matching scope pools

This rule must be based on provider `location` normalized into `object_context`.

## Section Family And Slot Role Rules

Section-family and role modeling should stay compact and deterministic.

Recommended families:

- `hero`
- `intro`
- `content`
- `features`
- `services`
- `testimonials`
- `faq`
- `cta`
- `media`
- `contact`
- `seo_or_meta`
- `unknown`

Recommended roles:

- `headline`
- `subheadline`
- `body`
- `rich_text`
- `cta_label`
- `cta_url`
- `image`
- `image_alt`
- `video`
- `quote`
- `stat`
- `list`
- `eyebrow`
- `meta`
- `unknown`

These should be internal DBVC helpers, not brittle field-name constants.

## Deterministic Eligibility Rules

Deterministic eligibility runs before semantic or AI scoring.

Hard exclusions:

- object mismatch
- `writable = false`
- blocked clone projections
- impossible value-shape mismatch
- impossible reference-kind mismatch

Strong penalties or review-only outcomes:

- degraded provider status
- `legacy_only`
- `missing`
- ambiguous duplicate-name fallback
- weak or conflicting section-family alignment

## Context Index And Cache Rule

Yes, DBVC should maintain a context index and cache.

Recommended order:

1. request-scope in-memory cache
2. artifact-backed slot graph cache
3. optional derived analytics index
4. custom DB table only if profiling proves it is necessary

Recommended artifact-backed cache output:

- `_inventory/dbvc_cc_target_slot_graph.v1.json`

Recommended lookup indexes:

- by `target_ref`
- by `key_path`
- by `name_path`
- by `group_and_acf_key`
- by `object_type`
- by `section_family`
- by `slot_role`

Recommended cache identity:

- `site_fingerprint`
- `source_hash`
- `schema_version`
- `contract_version`

If a custom table is added later, treat it as a derived acceleration layer only. It must be rebuildable from provider and catalog data and must never become the semantic source of truth.

## Selection Rules

The final recommendation stage must not choose `target_candidates[0]` without a global selection pass.

Required behavior:

- keep top-K eligible candidates for each source unit
- compare confidence margins
- reward page-level coherence across the same branch when justified
- penalize already-claimed structural competition groups for non-repeatable sibling slots
- penalize duplicate collisions and cross-group drift
- choose `unresolved` when the evidence gap is too small

Required unresolved metadata:

- `unresolved_class`
- `reason_codes[]`

Recommended initial unresolved classes:

- `missing_page_level_slot`
- `missing_section_family_slot`
- `missing_eligible_slot`
- `ambiguous_sibling_slots`
- `duplicate_target_collision`
- `low_mapping_evidence`
- `missing_media_slot`

## File Ownership

### Schema and field-context layer

Primary ownership:

- `addons/content-migration/shared/dbvc-cc-field-context-provider-service.php`
- `addons/content-migration/shared/dbvc-cc-field-context-match-scorer.php`
- `addons/content-migration/v2/schema/dbvc-cc-v2-target-field-catalog-service.php`

Recommended additions:

- `addons/content-migration/shared/dbvc-cc-field-context-chain-builder.php`
- `addons/content-migration/v2/schema/dbvc-cc-v2-target-slot-graph-service.php`

Responsibilities:

- normalize provider payloads
- derive context chains
- derive slot graph projections from the current catalog
- preserve field type, field name, group name, and branch-path metadata
- maintain rebuildable artifact-backed slot indexes
- preserve provider audit metadata

### Mapping layer

Primary ownership:

- `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php`

Recommended additions:

- `addons/content-migration/v2/shared/dbvc-cc-v2-section-semantics-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-section-content-item-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-target-eligibility-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-assignment-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-semantic-reranker-service.php`

Responsibilities:

- source unit creation
- deterministic eligibility filtering
- bounded candidate pool creation
- score composition
- page-level selection

### Review and inspector layer

Primary ownership:

- `addons/content-migration/v2/review/dbvc-cc-v2-recommendation-review-service.php`
- `addons/content-migration/v2/admin-app/components/inspectors/InspectorMappingTab.js`
- `addons/content-migration/v2/admin-app/components/inspectors/InspectorAuditTab.js`
- `addons/content-migration/v2/admin-app/components/inspectors/RecommendationDecisionCard.js`
- `addons/content-migration/v2/admin-app/components/inspectors/targetPresentation.js`

Responsibilities:

- surface chain path and ambiguity reasoning
- show provider drift and degraded-context warnings
- keep review evidence concise and inspectable

### Package and QA layer

Primary ownership:

- `addons/content-migration/v2/package/dbvc-cc-v2-url-qa-report-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-qa-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-build-service.php`

Responsibilities:

- enforce field-context-based blockers and warnings
- keep package readiness aligned with mapping quality
- stop invalid shapes from reaching import packaging

## Tranche Order

### Tranche A - Chain And Eligibility

Deliver:

- `context_chain[]`
- slot graph projections
- deterministic eligibility filtering
- candidate pool rebuild

Current state:

- landed: chain-aware slot graph artifact, nested ACF ancestry preservation, object-scope filtering, clone-projection write filtering, section-family-aware slot preference, and bounded candidate pools inside `dbvc-cc-v2-mapping-index-service.php`
- landed: benchmark-driven section semantics now suppress utility/navigation fragments and split structured sections into deterministic heading/body/CTA source units before Field Context candidate scoring
- landed: deterministic final selection metadata now survives into transform, review, and inspector surfaces via shared assignment output
- landed: provider-missing catalogs now keep truthful status while runtime ACF field/group arrays backfill purpose text into the enriched target catalog and slot graph without fabricating provider identity
- landed: `hero_h1`-style `wysiwyg` fields now stay in semantic `headline` role buckets instead of dropping to `rich_text`

Stop here only if:

- invalid groups are no longer flooding candidates
- hero and similar section-family collisions drop materially

### Tranche B - Deterministic Selection

Deliver:

- top-K retention
- page-aware assignment
- unresolved bias
- better duplicate collision handling

Stop here only if:

- finalization no longer relies on first-candidate wins
- wrong auto-maps are reduced before semantic reranking exists

Current state:

- landed: `DBVC_CC_V2_Mapping_Assignment_Service` now replaces greedy field-content finalization with page-aware deterministic assignment
- landed: ambiguous section selections default to `unresolved`, carry reason codes plus alternatives, and show reviewer-visible ambiguity framing in the existing inspector
- still open: media selection is still simpler than field-content selection and source units are still broader section blobs

### Tranche C - Source Modeling And Semantic Reranking

Deliver:

- source unit modeling where section blobs are still too coarse
- bounded semantic reranking across eligible candidates only

Stop here only if:

- semantic improvements are measurable on benchmarks
- the reranker is not reviving ineligible targets

### Tranche D - QA, Readiness, And Release Gate

Deliver:

- field-context QA issues
- package readiness gates
- benchmark reporting
- reviewer ambiguity UX polish

Stop here only if:

- benchmark thresholds are defined
- release-readiness is based on measured precision and override rate

Current state:

- landed: URL QA now emits additive Field Context warnings for degraded provider coverage, provider diagnostics, and reviewed ambiguous recommendations
- landed: target transforms now validate slot-graph `value_contract` data, block definite invalid URL or reference-shape writes, and surface additive `value_contract_validation` metadata into recommendations and package QA
- landed: URL QA now compares recommendation-carried provider metadata against the current slot graph and blocks readiness when provider/schema fingerprints drift
- landed: run readiness and package QA now expose compact benchmark rollups for unresolved, ambiguous, transform-blocked, and provider-drift counts per page
- landed: benchmark-backed release thresholds now evaluate quality score, reviewed ambiguity, manual overrides, and rerun counts inside the existing URL QA, readiness, package QA, and package workspace surfaces
- latest live acceptance result: rerunning Home `/` from `ccv2_flourishweb-co_20260421T223255Z_0cc20e` now keeps provider status `missing` but restores the real local `hero_h1` slot to `map_003`; the remaining seam is ambiguity reduction on low-margin hero/body collisions

## Benchmark Rule

Create and maintain a benchmark set of real Vertical pages before treating this system as production-ready for new client sites.

Track at minimum:

- group-level precision
- field-level precision
- unresolved quality
- reviewer override rate
- package-blocker rate caused by degraded context

Benchmark work should start in parallel with early deterministic tranches, not after them.

## Out Of Scope

- Object Type Context
- changes to Vertical theme code or docs outside DBVC repo work
- raw ACF JSON semantic parsing at runtime
- a new standalone mapping UI outside the current V2 inspector and readiness flows
- direct calls into Vertical publish or rollback flows
