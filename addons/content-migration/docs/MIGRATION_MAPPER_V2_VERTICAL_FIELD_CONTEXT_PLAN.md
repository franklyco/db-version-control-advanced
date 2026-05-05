# Migration Mapper V2 Vertical Field Context Mapping Accuracy Plan

## Purpose

This is the active implementation plan for making Content Migration V2 accurate enough for real client production use on `VerticalFramework` sites.

The goal is not just to expose Vertical field context. The goal is to make DBVC map crawled source content into the correct ACF field group, branch, and field with a strong bias toward `unresolved` over `wrong`.

This plan replaces the earlier provider-only integration memo as the primary planning document for Vertical Field Context work.

## Delivery Goal

DBVC should:

- understand each target ACF field as part of a top-down context chain
- preserve each target's ACF field type, field name, group name, and branch path for matching and reviewer evidence
- understand each crawled content unit as part of a page section and local content graph
- filter invalid targets deterministically before any semantic or AI scoring
- assign mappings across the whole page coherently instead of greedily per item
- refuse low-margin guesses and surface them for review
- reuse the current V2 review, inspector, readiness, and package surfaces instead of building a parallel runtime

## Authority And Source Policy

### Runtime semantic source of truth

Use the DBVC-normalized Vertical field-context provider payload as the runtime semantic source of truth.

That means DBVC should rely on provider data for:

- field purpose
- parent or branch purpose
- group purpose
- object compatibility
- value contract
- clone provenance and write policy
- provider status, source hash, schema version, and contract version

### Topology and coverage reference source

Use Vertical `acf-json/field-groups/*.json` only for:

- topology reference
- ancestry coverage checks
- verifying that `vf_field_context` exists across group, container, and field objects
- benchmark fixture seeding
- drift diagnostics against provider output

Do not use raw ACF JSON as DBVC's runtime semantic source of truth.

### Runtime fallback when provider catalog is empty

If the Vertical provider returns `missing` or `unavailable`, DBVC may use the current WordPress ACF runtime field/group arrays only to backfill purpose context into the enriched target catalog and slot graph.

Rules:

- preserve the provider status truthfully as `missing` or `unavailable`
- do not synthesize provider identity such as `key_path` or `name_path`
- use runtime fallback only for purpose/display context needed to keep slot selection usable
- prefer `vf_field_context.purpose`
- use `gardenai_field_purpose` only as a lower-priority fallback

### Explicit guardrails

- Do not consume Vertical smart-authoring artifacts as runtime context.
- Do not parse `resolved_purpose` to infer value shape.
- Do not parse composite clone keys to infer clone ownership.
- Treat `key_path` as an opaque provider identity.
- Use `resolved_purpose` for meaning, not value shape.
- Use `value_contract` for value shape, write behavior, and reference behavior.
- Use `location` and normalized `object_context` for object compatibility.
- Use `clone_context` for clone provenance and publish-policy warnings.
- Do not call Vertical publish, backup, or rollback flows from DBVC.

## Context Chain Requirement

Every target slot must carry a top-down context chain.

The minimum chain is:

1. ACF field group context
2. parent container or branch context at every nested level
3. leaf field context
4. value contract and write constraints
5. object compatibility
6. clone provenance and warnings

Example reference from Vertical page field groups:

- file: `/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical/acf-json/field-groups/core_group_64f803456e4d0.json`
- group: `Core`
- branch: `hero_section`
- leaf field: `hero_h1` (`field_64fab06829de4`)

DBVC should interpret that target as:

- group purpose: what the `Core` page field group is for
- branch purpose: what `hero_section` is for on the page
- field purpose: what `hero_h1` is for inside that branch
- value shape: whatever `value_contract` says the field accepts
- write policy: whatever `writable` and clone policy allow

No mapper or AI layer should score a target as if it were only an isolated field label.

## Build Around What Already Exists

The current DBVC field-context tranche has already landed these foundations:

- bootstrap loads `DBVC_CC_Field_Context_Provider_Service`
- provider service normalizes:
  - provider and catalog metadata
  - `location -> object_context`
  - `value_contract`
  - `clone_context`
  - diagnostics and warnings
  - `entries_by_key_path`
  - `entries_by_name_path`
  - `entries_by_group_and_acf_key`
- V2 contracts and settings include compact Field Context controls
- V2 target field catalog embeds:
  - top-level `field_context_provider`
  - per-group `field_context`
  - per-field `field_context`
- V2 mapping candidates carry `field_context` and confidence degradation for non-writable or clone-projected targets
- V2 review payloads and inspector surfaces show compact Field Context evidence
- provider-empty catalogs now keep truthful `missing` status while the enriched target catalog and slot graph can backfill purpose text from runtime ACF field/group metadata
- semantic slot-role inference now runs before generic `wysiwyg`/`textarea` fallback so real Vertical fields like `hero_h1` stay in `headline` candidate pools

The next work should build on those pieces, not replace them.

## Current Failure Pattern

The main accuracy problem is that the runtime is still too heuristic-first.

What is still happening now:

- source sections are reduced too aggressively before matching
- candidate generation is still too dependent on field names and pattern keys
- object compatibility checks are too narrow
- candidate pools still contain invalid or weakly related groups and fields
- finalization is still too greedy and local to each item
- page-level coherence is not strong enough
- wrong mappings often win instead of landing as unresolved

That is why DBVC can now show good Field Context evidence in review while still producing bad candidate selections upstream.

## Target Runtime Model

### 1. Source Unit Graph

Convert crawled page content into bounded source units instead of loosely typed section fragments.

A source unit should carry:

- `section_family`
- `unit_role`
- `text_summary`
- `surrounding_heading`
- `position`
- `sibling_units`
- media and CTA cues where present
- provenance back to source artifacts

Examples:

- hero headline
- hero supporting copy
- hero CTA label
- hero image
- testimonial heading
- testimonial quote list

### 2. Target Slot Graph

Build a target slot graph from the enriched V2 target field catalog.

A target slot should carry:

- stable `target_ref`
- `acf_name`
- `acf_label`
- `type`
- `container_type`
- `group_name`
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
- provider trace metadata
- `competition_group`

This should be derived from the current V2 target catalog instead of introducing a second primary catalog system.

### 3. Deterministic Eligibility Layer

Before semantic scoring, DBVC should exclude targets that are not actually eligible.

Minimum hard filters:

- object compatibility must pass
- non-writable targets must not be direct write candidates
- clone-projected blocked targets must not be direct write candidates
- section family must be compatible when strong family evidence exists
- obvious value-shape mismatches must not survive into ranking

### 4. Bounded Candidate Pools

Only eligible targets should move to semantic ranking.

Candidate pools should be:

- bounded
- chain-aware
- group-aware
- page-context-aware

Do not let broad field-name collisions flood the candidate pool.

### 5. Global Assignment

Final mapping should be chosen across the whole page, not greedily per item.

The assignment step should:

- prefer coherent selections within the same target branch when evidence supports it
- avoid duplicate collisions
- penalize already-claimed structural competition groups for non-repeatable sibling slots
- penalize cross-group drift
- mark low-margin conflicts unresolved instead of forcing a choice

### 6. Typed Unresolved Classes

Do not leave unresolved items in one generic bucket.

At minimum, carry:

- `unresolved_class`
- stable `reason_codes[]`

Recommended classes:

- `missing_page_level_slot`
- `missing_section_family_slot`
- `missing_eligible_slot`
- `ambiguous_sibling_slots`
- `duplicate_target_collision`
- `low_mapping_evidence`
- `missing_media_slot`

## Frontend Surface Naming Rule

DBVC should distinguish between two different things:

1. the ACF structural branch or section path inside the field-group hierarchy
2. the actual frontend-rendered component or template that outputs the content

DBVC should capture the first one now.

That means the slot graph and reviewer payloads should preserve:

- `group_name`
- `branch_name_path`
- `branch_label_path`
- field type and field name

DBVC should not claim the second one unless Vertical exposes explicit metadata for it.

That means DBVC must not infer a real frontend component or template name from:

- field names
- purpose text
- branch names alone
- `resolved_purpose`

If Vertical later exposes explicit frontend-surface metadata, DBVC can carry it as additive evidence, for example:

- `frontend_surface`
- `render_component`
- `template_part`
- `section_slug`

Until then, DBVC should treat ACF branch paths as structural context, not as guaranteed frontend component identity.

## Object Applicability Rule

DBVC should deterministically know which field groups and fields apply to which object types.

This must come from provider `location` normalized into `object_context`, not from guessed naming conventions.

Examples:

- `services_group` should only be eligible for `service` CPT objects
- page-oriented groups should only be eligible for `page` or other explicitly allowed post types
- taxonomy and options-page groups should only be eligible for their declared scopes

This is not just reviewer metadata. It is a hard eligibility rule in the mapper.

## Context Index And Cache Strategy

Yes, DBVC should maintain a context index and cache for efficient matching.

It should not start with a complex graph database or a required custom table.

The recommended order is:

### 1. Request-scope cache

Inside the current runtime request:

- cache provider payload lookups
- cache chain-builder results
- cache slot-graph projections

### 2. Artifact-backed slot graph cache

Persist an artifact-backed slot graph and lookup indexes derived from the current target catalog and provider trace.

Recommended artifact family:

- `_inventory/dbvc_cc_target_slot_graph.v1.json`

Recommended index views inside that artifact:

- `slots_by_target_ref`
- `slots_by_key_path`
- `slots_by_name_path`
- `slots_by_group_and_acf_key`
- `slots_by_object_type`
- `slots_by_section_family`
- `slots_by_slot_role`

Key cache identity inputs:

- `site_fingerprint`
- `source_hash`
- `schema_version`
- `contract_version`

This keeps the source of truth file-first and rebuildable from provider and catalog data.

### 3. Optional benchmark and analytics index

If benchmark reporting and reviewer analytics need fast cross-run querying, add a lightweight derived index for metrics.

This still should not become the semantic source of truth.

### 4. Optional DB table only if justified

Introduce a custom table only if all of these become true:

- artifact-backed slot graph reads are measurably too slow
- cross-run querying becomes a real runtime bottleneck
- reviewer or benchmark analytics require indexed SQL access
- rebuild semantics from provider metadata are clearly defined

If a table is added later, it should be a derived acceleration layer, not an authored truth source.

Recommended shape if it becomes necessary:

- one row per `site_fingerprint + source_hash + object_scope + target_ref`
- indexed columns for:
  - `target_ref`
  - `group_key`
  - `acf_key`
  - `acf_name`
  - `field_type`
  - `object_scope`
  - `section_family`
  - `slot_role`
  - `writable`
- JSON columns or serialized payload for:
  - `context_chain`
  - `value_contract`
  - `object_context`
  - `clone_context`
  - `provider_trace`

Recommended rule:

- implement the artifact-backed slot graph cache first
- only add a DB table if profiling proves that the file-first index is not enough

## Delivery Principles

- Wrong auto-mapping is worse than unresolved review work.
- Deterministic eligibility must run before semantic or AI ranking.
- AI should rerank only among valid candidates, not invent target families.
- The page should be scored as a whole, not as disconnected rows.
- `value_contract` is the only supported authority for shape and write behavior.
- Review should stay exception-first.
- New logic should reuse the current V2 catalog, mapping, review, readiness, and package surfaces.

## Progress Tracker

### FC-00 - Provider And Reviewer Baseline

Status: `CLOSED`

Landed baseline:

- provider service integration
- target catalog field-context embedding
- candidate field-context trace
- review payload field-context trace
- inspector reviewer visibility pass

Tracked result:

- reviewers can now see field-context evidence
- the remaining gap is upstream selection accuracy

### FC-01 - Context Chain, Slot Graph, And Context Index

Status: `CLOSED`

Goal:

Build a chain-aware target slot graph from the current V2 target field catalog so every mapping target has explicit ancestry and purpose context.

Key work:

- add a slot-graph builder that derives slots from the existing catalog
- materialize `context_chain[]` from group -> parent containers -> leaf field
- preserve `acf_name`, `acf_label`, `type`, `container_type`, `group_name`, `branch_name_path`, and `branch_label_path`
- derive `chain_purpose_text`, `section_family`, and `slot_role`
- preserve provider trace: `source_hash`, `contract_version`, `schema_version`, `site_fingerprint`, provider status
- keep `key_path` opaque and use explicit ancestry fields for display and matching
- build artifact-backed lookup indexes for object scope, section family, role, and provider identities

Likely files:

- new: `addons/content-migration/v2/schema/dbvc-cc-v2-target-slot-graph-service.php`
- new: `addons/content-migration/shared/dbvc-cc-field-context-chain-builder.php`
- update: `addons/content-migration/v2/schema/dbvc-cc-v2-target-field-catalog-service.php`
- update: `addons/content-migration/shared/dbvc-cc-field-context-provider-service.php`
- optional later: a derived DB table only if runtime profiling proves the artifact cache is insufficient

Acceptance criteria:

- page slots for `core_group_64f803456e4d0.json` can expose a full chain for targets such as `hero_section -> hero_h1`
- page and service slots preserve field type, field name, group name, and branch path in the slot projection
- the chain is available to mapping and review consumers without another provider round-trip
- provider status metadata survives into slot projections and audit views
- slot lookup can efficiently filter by object type before semantic ranking

Landed notes:

- `_schema/dbvc_cc_target_slot_graph.v1.json` now persists chain-aware slot projections and lookup indexes
- nested ACF ancestry now survives into the V2 target catalog, including `ancestor_field_keys`, `ancestor_name_path`, and `ancestor_label_path`
- slot projections now preserve `context_chain[]`, `branch_name_path`, `branch_label_path`, `chain_purpose_text`, `section_family`, `slot_role`, and provider trace metadata

### FC-02 - Source Unit Modeling

Status: `WIP`

Goal:

Upgrade source-side modeling so DBVC maps bounded content units instead of broad section blobs.

Key work:

- split sections into typed source units
- classify source units with deterministic roles before semantic ranking
- preserve heading ancestry, local section family, CTA/media cues, and sibling order
- carry enough source evidence for later reviewer explanation

Likely files:

- new: `addons/content-migration/v2/shared/dbvc-cc-v2-section-semantics-service.php`
- new: `addons/content-migration/v2/mapping/dbvc-cc-v2-section-content-item-service.php`
- update: `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`
- update: `addons/content-migration/v2/ai-context/dbvc-cc-v2-context-creation-service.php`

Acceptance criteria:

- hero text, hero CTA, hero image, and similar content types no longer arrive as one generic section payload
- mapping candidates can distinguish `headline` vs `body` vs `cta` vs `image`

Current landed slice:

- benchmark tuning against `ccv2_flourishweb-co_20260421T223255Z_0cc20e` now suppresses utility and navigation fragments before Field Context matching
- structured sections now split into deterministic `headline`, `body`, `cta_label`, and `cta_url` source units when element evidence is present
- H1-led hero sections now stop inheriting stale `contact` context purely because CTA language is also present in the same section

Remaining gap:

- image-focused source units still rely on the dedicated media pipeline rather than a shared section-unit model
- deeper multi-paragraph or repeated CTA sections still need richer sibling-aware source grouping if benchmark coverage shows drift

### FC-03 - Eligibility Filters And Candidate Pool Rebuild

Status: `WIP`

Goal:

Rebuild candidate generation around deterministic eligibility filters and bounded candidate pools.

Key work:

- hard filter by `object_context`
- treat object applicability as a first-class gate so `services_group` cannot appear for `page` mappings
- exclude non-writable and blocked clone projections from direct write mapping
- filter by section family when strong evidence exists
- filter by value shape or reference expectations when clearly incompatible
- replace broad pattern-first candidate expansion with slot-graph retrieval
- keep only top-K eligible candidates per source unit

Likely files:

- new: `addons/content-migration/v2/mapping/dbvc-cc-v2-target-eligibility-service.php`
- update: `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`
- update: `addons/content-migration/shared/dbvc-cc-field-context-match-scorer.php`

Acceptance criteria:

- clearly incompatible groups and fields stop appearing in candidate pools
- object-type-incompatible groups no longer appear in page-level candidate pools
- duplicate raw `acf_key` collisions no longer dominate when scoped `key_path` or group-scoped indexes exist
- the average candidate pool is smaller, more relevant, and more auditable

Current landed slice:

- mapping index now loads the target slot graph before ACF candidate expansion
- object-scope filtering now excludes targets whose `object_context.post_types` do not match the selected object
- blocked clone projections now stop surfacing as direct-write ACF candidates
- section items now prefer structured section-family slots and stop defaulting to `core:post_title` when matching structured hero slots exist
- candidate pools are now capped and carry a confidence-gap summary for the next deterministic selection tranche

Remaining gap:

- source units are still broad section blobs
- eligibility does not yet enforce deeper value-shape compatibility beyond direct writability
- package and readiness gating still need stronger transform-side enforcement than the current additive warning layer

### FC-04 - Deterministic Selection And Unresolved Bias

Status: `CLOSED`

Goal:

Stop greedy first-candidate finalization and move to page-aware deterministic selection with an explicit unresolved bias.

Key work:

- keep top-K candidate sets instead of assuming candidate `0` is correct
- apply confidence-gap and ambiguity thresholds
- reward page-level branch coherence when evidence supports it
- penalize unrelated cross-group spreads
- mark low-margin collisions unresolved instead of guessing

Likely files:

- new: `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-assignment-service.php`
- update: `addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php`
- update: `addons/content-migration/v2/review/dbvc-cc-v2-recommendation-review-service.php`

Acceptance criteria:

- final recommendations are no longer chosen by naive `target_candidates[0]`
- ambiguous hero or section-family collisions land in review rather than in wrong groups
- duplicate-target conflicts are reduced before review because the selector is coherence-aware

Current landed slice:

- field-content recommendation finalization now runs through `DBVC_CC_V2_Mapping_Assignment_Service`
- target transforms and review payloads now consume deterministic selection output instead of trusting raw candidate `0`
- ambiguous section selections now default to `unresolved`, carry reason codes plus alternatives, and show reviewer-visible ambiguity evidence inside the existing inspector surfaces

### FC-05 - Semantic Reranker

Status: `OPEN`

Goal:

Add a bounded semantic reranker that compares source unit intent to the full target context chain, but only after deterministic constraints have reduced the search space.

Key work:

- build compact semantic inputs from source unit summaries and `context_chain[]`
- compare unit role, section family, chain purpose, and leaf purpose
- keep semantic output additive to deterministic constraints
- record semantic reasons and uncertainty in candidate evidence

Likely files:

- new: `addons/content-migration/v2/mapping/dbvc-cc-v2-semantic-reranker-service.php`
- update: `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`
- update: `addons/content-migration/v2/ai-context/` prompt helpers only where needed

Acceptance criteria:

- semantic scoring improves ranking among already valid targets
- semantic scoring cannot revive ineligible targets
- reviewer payloads can explain semantic evidence without hiding deterministic reasons

### FC-06 - Transform, QA, And Package Readiness Gates

Status: `WIP`

Goal:

Use Field Context and `value_contract` to prevent invalid writes and to stop package readiness from hiding degraded mappings.

Key work:

- enforce output shaping from `value_contract`
- block or warn on clone publish restrictions
- add QA issues for section mismatch, ambiguity, provider drift, and degraded context
- gate package readiness on unresolved high-risk context failures

Likely files:

- update: `addons/content-migration/v2/transform/` target transform services
- update: `addons/content-migration/v2/package/dbvc-cc-v2-url-qa-report-service.php`
- update: `addons/content-migration/v2/package/dbvc-cc-v2-package-qa-service.php`
- update: `addons/content-migration/v2/package/dbvc-cc-v2-package-build-service.php`

Acceptance criteria:

- invalid value shapes are blocked before package creation or import
- provider drift and degraded context show up as explicit readiness issues
- package automation stops silently auto-approving weak field-context matches

Current landed slice:

- mapping-index artifacts now carry sanitized `field_context_provider` metadata into canonical recommendations
- URL QA reports now surface additive warnings for degraded provider coverage, provider diagnostics, and recommendations that were manually kept despite ambiguous deterministic selection
- readiness routing now treats those Field Context QA codes as first-class review or audit signals without adding a new screen
- target transforms now load the slot graph so each ACF transform item carries additive `value_contract` and `value_contract_validation` metadata
- target transforms now block definite contract mismatches such as invalid URL writes or text landing in image or reference-only slots before package readiness can mark a page ready
- canonical recommendations now preserve transform contract status and contract warnings so existing review and inspector flows can explain why a mapping stayed blocked
- URL QA now compares saved provider metadata against the current slot graph and blocks readiness when provider identity or schema fingerprints drift
- run readiness and package QA now include compact benchmark rollups so release-gate metrics are visible without adding another route or artifact family
- benchmark-backed release thresholds now run through a shared gate inside URL QA, readiness, package QA, and the package workspace using quality score plus reviewed ambiguity plus manual override plus rerun thresholds instead of a separate benchmark screen

### FC-07 - Reviewer UX, Benchmark Harness, And Release Gate

Status: `OPEN`

Goal:

Make the improved system auditable and measurable before it is treated as production-ready for client work.

Key work:

- extend existing inspector surfaces to show chain path, ambiguity margin, and section-family reasoning
- add queue or audit chips for section mismatch, ineligible target fallback, and ambiguous chain match
- surface typed unresolved classes and structural competition pressure in existing review and QA payloads
- build a benchmark set of known Vertical pages with expected group and field mappings
- track group precision, field precision, unresolved quality, and reviewer override rate across tranches
- keep the landed release-threshold policy calibrated against real Vertical benchmark pages before treating the system as client-ready

Likely files:

- update: `addons/content-migration/v2/admin-app/components/inspectors/InspectorMappingTab.js`
- update: `addons/content-migration/v2/admin-app/components/inspectors/InspectorAuditTab.js`
- update: `addons/content-migration/v2/admin-app/components/inspectors/RecommendationDecisionCard.js`
- update: `addons/content-migration/v2/admin-app/components/inspectors/targetPresentation.js`
- new fixtures and PHPUnit coverage under `tests/phpunit/` and `addons/content-migration/tests/fixtures/`

Acceptance criteria:

- reviewers can see exactly why a candidate won and why alternatives were rejected or deferred
- benchmark metrics are captured before and after each major mapping tranche
- production rollout can be gated on measured accuracy instead of anecdotal spot checks

## Fastest Delivery Order

To improve production usefulness quickly without overcomplicating the system, implement in this order:

1. `FC-01` context chain and slot graph
2. `FC-03` deterministic eligibility and candidate pool rebuild
3. `FC-04` deterministic selection and unresolved bias
4. benchmark seeding from `FC-07` in parallel with the above
5. `FC-02` source unit modeling where current section blobs are still too coarse
6. `FC-06` QA and readiness gates
7. `FC-05` semantic reranker after deterministic quality improves
8. remaining reviewer UX polish from `FC-07`

That sequence keeps the first gains deterministic, measurable, and low-risk.

## Explicit Out Of Scope For This Plan

- Object Type Context semantic layer work
- changes inside the Vertical theme repo
- direct parsing of raw Vertical ACF JSON as runtime mapping truth
- new standalone review screens outside the current V2 inspector and readiness surfaces
- remote-provider-first hardening as a prerequisite for same-runtime Vertical sites

## Update Rule

Update this plan whenever one of these changes:

- the active phase status
- the phased delivery order
- the target slot or source unit model
- the reviewer UX evidence model
- the release gate or benchmark thresholds
