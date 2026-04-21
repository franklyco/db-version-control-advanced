# Migration Mapper V2 Vertical Field Context Runtime Handoff

## Purpose

This handoff is the current DBVC implementation reference for the VerticalFramework `vf_field_context` runtime integration.

Read this before continuing implementation in a new Codex session. It explains what landed, which files own the behavior, how the data flows, what was validated, and what remains open.

This doc is specific to DBVC / Content Migration V2. The upstream provider contract remains owned by the VerticalFramework theme docs:

- `/wp-content/themes/vertical/docs/field-context-schema.md`
- `/wp-content/themes/vertical/docs/field-context-integration-layer.md`
- `/wp-content/themes/vertical/docs/dbvc-sync.md`
- `/wp-content/themes/vertical/docs/dbvc-field-context-handoff.md`

## Status

Current status: first DBVC runtime implementation pass landed locally.

The DBVC runtime now:

- loads the shared Field Context provider service during Content Migration bootstrap
- reads Vertical's resolved service catalog through the local PHP helper when available
- normalizes `location`, `value_contract`, `clone_context`, provider metadata, and freshness metadata
- exposes key-path, name-path, ACF-key, and group-scoped ACF-key indexes
- enriches V2 target field catalog artifacts with Field Context provider metadata
- adds compact `field_context` traces to ACF groups and fields
- uses Field Context traces during mapping candidate generation
- propagates selected candidate Field Context traces into mapping recommendations
- lowers confidence and requires review for degraded, missing, non-writable, and clone-projected targets

The implementation is additive. Existing DBVC target refs remain stable, especially:

- `core:post_title`
- `core:post_content`
- `meta:post:<subtype>:<meta_key>`
- `acf:<group_key>:<field_key>`
- `taxonomy:<taxonomy_key>`

## Core Rules

DBVC must treat Vertical's resolved service projection as the source of truth.

DBVC should not:

- read raw Vertical `acf-json` files directly for Field Context
- consume smart-authoring artifact runs as runtime truth
- parse `key_path` to infer clone ownership or hierarchy
- parse `resolved_purpose` to infer value shape
- infer write safety from ACF type alone
- call Vertical's Field Context publish, backup, or rollback actions
- assume raw `acf_key` is unique for clone projections

DBVC should:

- use `resolved_purpose` / `effective_purpose` for semantic meaning
- use `value_contract` for value shape, references, choices, container behavior, and write safety
- use `location` / `object_context` for target object compatibility
- use `clone_context` for clone source/projection provenance
- prefer `key_path` and group-scoped indexes over raw `acf_key` when available
- carry provider `source_hash`, `schema_version`, `contract_version`, and `site_fingerprint` into diagnostics

## Implementation Map

### Bootstrap

File:

- `addons/content-migration/bootstrap/dbvc-cc-addon-bootstrap.php`

Change:

- Added `require_once` for `addons/content-migration/shared/dbvc-cc-field-context-provider-service.php`.

Why:

- The provider class existed but was not loaded by the Content Migration bootstrap.
- V2 catalog and mapping code can now call `DBVC_CC_Field_Context_Provider_Service`.

### V2 Settings And Defaults

File:

- `addons/content-migration/v2/shared/dbvc-cc-v2-contracts.php`

Added constants:

- `OPTION_FIELD_CONTEXT_INTEGRATION_MODE`
- `OPTION_FIELD_CONTEXT_USE_LEGACY_FALLBACK`
- `OPTION_FIELD_CONTEXT_WARN_ON_DEGRADED`
- `OPTION_FIELD_CONTEXT_BLOCK_ON_MISSING`
- `FIELD_CONTEXT_MODE_AUTO`
- `FIELD_CONTEXT_MODE_LOCAL`
- `FIELD_CONTEXT_MODE_REMOTE`
- `FIELD_CONTEXT_MODE_OFF`

Added methods:

- `get_field_context_settings()`
- `get_allowed_field_context_modes()`

Default values:

- integration mode: `auto`
- use legacy fallback: `1`
- warn on degraded: `1`
- block on missing: `0`

File:

- `addons/content-migration/v2/admin/dbvc-cc-v2-configure-addon-settings.php`

Added configure group:

- `Vertical Field Context`

Added controls:

- Field Context integration mode
- Use legacy context fallback
- Warn on degraded Field Context
- Block when Field Context is missing

Note:

- Remote endpoint credentials are still filter-configured through `dbvc_cc_field_context_remote_provider_config`; the configure UI does not yet own remote credentials.

### Provider Normalizer

File:

- `addons/content-migration/shared/dbvc-cc-field-context-provider-service.php`

The provider now normalizes the Vertical payload into DBVC-owned shape.

Top-level result includes:

```json
{
  "available": true,
  "profile": "mapping",
  "transport": "local",
  "provider": {},
  "catalog_meta": {},
  "consumer_policy": {},
  "groups_by_acf_key": {},
  "entries_by_acf_key": {},
  "entries_by_key_path": {},
  "entries_by_name_path": {},
  "entries_by_group_and_acf_key": {},
  "diagnostics": {},
  "error": {}
}
```

The provider preserves `entries_by_acf_key` for backward compatibility, but this index can collapse clone-projected duplicates. New code should prefer:

1. `entries_by_key_path`
2. `entries_by_group_and_acf_key`
3. `entries_by_name_path`
4. `entries_by_acf_key` only as a fallback

Group normalization now includes:

- `location`
- `object_context`
- `value_contract`
- `clone_context`
- `resolved_purpose`
- `default_purpose`
- `effective_purpose`
- `status_meta`
- `coverage`
- `resolved_from`

Entry normalization now includes:

- `matched_by`
- `value_contract`
- `clone_context`
- `resolved_purpose`
- `default_purpose`
- `effective_purpose`
- `status_meta`
- `resolved_from`

`object_context` is derived from ACF location rules into:

```json
{
  "post_types": [],
  "taxonomies": [],
  "options_pages": [],
  "unknown_rules": []
}
```

Diagnostics now include:

- `legacy_only_count`
- `missing_count`
- `non_writable_count`
- `clone_projection_count`
- `clone_publish_blocked_count`
- `duplicate_acf_key_count`
- `source_hash_missing`
- `provider_schema_version`
- `provider_site_fingerprint`
- warning entries for degraded conditions

Runtime observation from validation:

- catalog status: fresh
- local transport works
- provider: `vertical-field-context`
- contract version: `1`
- schema version: `1`
- group count: `46`
- catalog entry count from provider metadata: `3356`
- DBVC's raw `entries_by_acf_key` count is lower because raw ACF-key indexing collapses clone projections
- duplicate ACF-key projection count: `429`
- clone-projected direct framework-default publish blocks: `448`

That duplicate count is expected with clone projections. It is the reason future code must prefer key-path or group-scoped identity.

### V2 Target Field Catalog Enrichment

File:

- `addons/content-migration/v2/schema/dbvc-cc-v2-target-field-catalog-service.php`

The V2 target field catalog now asks the Field Context provider for a full mapping index and embeds an additive provider block:

```json
{
  "field_context_provider": {
    "available": true,
    "transport": "local",
    "provider": "vertical-field-context",
    "provider_version": "2.1.7",
    "contract_version": 1,
    "schema_version": 1,
    "site_fingerprint": "example",
    "catalog_status": "fresh",
    "resolver_status": "ok",
    "generated_at": "2026-04-20 17:52:06",
    "source_hash": "example",
    "cache_layer": "persistent",
    "cache_version": "example",
    "group_count": 46,
    "entry_count": 3356,
    "degraded": true,
    "blocked": false,
    "diagnostics": {},
    "error": {}
  }
}
```

`source_artifacts` now includes:

- `field_context_source_hash`
- `field_context_site_fingerprint`

`stats` now includes:

- `field_context_group_count`
- `field_context_entry_count`

ACF group rows may now include:

```json
{
  "field_context": {
    "provider": "vertical-field-context",
    "contract_version": 1,
    "schema_version": 1,
    "site_fingerprint": "example",
    "source_hash": "example",
    "catalog_status": "fresh",
    "resolver_status": "ok",
    "key_path": "group_...",
    "name_path": "core_group",
    "location": [],
    "object_context": {},
    "resolved_purpose": "",
    "default_purpose": "",
    "effective_purpose": "",
    "resolved_from": "",
    "status_meta": {},
    "coverage": {},
    "value_contract": {},
    "clone_context": {},
    "has_override": false
  }
}
```

ACF field rows may now include:

```json
{
  "field_context": {
    "provider": "vertical-field-context",
    "contract_version": 1,
    "schema_version": 1,
    "site_fingerprint": "example",
    "source_hash": "example",
    "catalog_status": "fresh",
    "resolver_status": "ok",
    "matched_by": "acf_key",
    "resolved_from": "",
    "status_meta": {},
    "group_purpose": "",
    "field_purpose": "",
    "resolved_purpose": "",
    "default_purpose": "",
    "key_path": "",
    "name_path": "",
    "parent_key_path": "",
    "parent_name_path": "",
    "object_context": {},
    "value_contract": {},
    "clone_context": {},
    "has_override": false,
    "warnings": []
  }
}
```

Warnings added at field level:

- `field_context_legacy_only`
- `field_context_missing`
- `field_context_non_writable`
- `field_context_clone_framework_default_blocked`

Important detail:

- Catalog enrichment calls `get_mapping_index([])`, not domain-scoped criteria.
- Domain criteria produced a valid provider response but zero entries because the Vertical provider does not use DBVC domain strings as object selectors.
- DBVC should use the full runtime catalog for schema enrichment, then narrow by object compatibility locally.

### Mapping Candidate Enrichment

File:

- `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`

Changes:

- `trace.field_context_provider` is copied from the enriched target catalog.
- ACF pattern extraction now considers:
  - field name
  - field label
  - `field_context.field_purpose`
  - `field_context.group_purpose`
  - `value_contract.content_type`
  - `value_contract.value_shape`
  - `value_contract.reference_kind`
- Pattern refs now preserve an optional `field_context` block, not just a string target ref.
- Candidates created from Field Context carry:
  - `field_context`
  - `warnings`
  - `reason = field_context_pattern_match`

Confidence adjustments:

- add `0.03` when `field_purpose` exists
- subtract `0.08` for `legacy_only`
- subtract `0.18` for `missing`
- subtract `0.25` for `value_contract.writable = false`
- subtract `0.10` for clone-projected targets whose `publish_policy.framework_default_writable = false`

The adjustments are intentionally small except for non-writable targets. They are a first-pass safety layer, not a final scoring model.

### Recommendation Propagation

File:

- `addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php`

Changes:

- `trace.field_context_provider` is copied from mapping index into mapping recommendations.
- Selected candidate `field_context` is copied into each recommendation.
- Field Context warnings are copied into recommendation `warnings`.
- `requires_review` becomes true when selected Field Context warnings exist.

This means review/package layers can inspect selected target context without calling the provider again.

## Runtime Data Flow

Current flow:

1. Content Migration bootstrap loads DBVC shared Field Context provider service.
2. `DBVC_CC_V2_Target_Field_Catalog_Service::build_catalog()` builds the existing legacy target catalog.
3. The V2 catalog service calls `DBVC_CC_Field_Context_Provider_Service::get_mapping_index([])`.
4. The provider selects local transport first when Vertical helpers are available.
5. The provider calls `vf_field_context_get_service_catalog_payload($criteria, 'mapping')`.
6. The provider normalizes the Vertical service payload into DBVC indexes and diagnostics.
7. The V2 catalog service embeds `field_context_provider` and adds `field_context` blocks to matching ACF groups and fields.
8. The mapping index service builds candidates from the enriched catalog.
9. Candidates carry `field_context` and warnings.
10. Recommendation finalization copies selected candidate Field Context into recommendations.
11. Review/package/QA layers can now consume the trace from artifacts instead of re-querying Vertical.

## Current Context Map

| Concern | Owner file | Current state | Next owner |
| --- | --- | --- | --- |
| Provider availability and transport | `shared/dbvc-cc-field-context-provider-service.php` | Local-first and remote-filter capable | Add cache/ETag and stronger remote auth |
| Field Context V2 settings | `v2/shared/dbvc-cc-v2-contracts.php`, `v2/admin/dbvc-cc-v2-configure-addon-settings.php` | Defaults and admin controls added | Add remote credential/config UI only if explicitly approved |
| Provider normalization | `shared/dbvc-cc-field-context-provider-service.php` | Normalizes provider/catalog/group/entry/location/value/clone metadata | Extract to dedicated normalizer class if it grows |
| Clone identity safety | `shared/dbvc-cc-field-context-provider-service.php` | Adds key-path/name-path/group-scoped indexes and duplicate ACF-key diagnostics | Update all consumers to prefer key-path/group-scoped lookup |
| V2 target catalog enrichment | `v2/schema/dbvc-cc-v2-target-field-catalog-service.php` | Adds provider block and per-group/per-field traces | Add fixture coverage and schema docs |
| Candidate generation | `v2/mapping/dbvc-cc-v2-mapping-index-service.php` | Uses purpose/value-shape terms and carries field context on candidates | Add object compatibility boosts/penalties |
| Recommendation finalization | `v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php` | Copies selected Field Context and warnings into recommendations | Expose in review UI and QA reports |
| Review UI | `v2/review/*`, `v2/admin-app/*` | Not yet updated for visible Field Context display | Add compact inspector panel and warning chips |
| Package QA | `v2/package/*` | Not yet using Field Context warnings directly | Add readiness blockers and stale-source checks |
| Import execution | `v2/import/*`, legacy import bridge | Not yet value-contract-aware | Use `value_contract` for transform/write behavior |

## Validation Performed

Syntax checks passed:

```bash
php -l addons/content-migration/bootstrap/dbvc-cc-addon-bootstrap.php
php -l addons/content-migration/shared/dbvc-cc-field-context-provider-service.php
php -l addons/content-migration/v2/shared/dbvc-cc-v2-contracts.php
php -l addons/content-migration/v2/admin/dbvc-cc-v2-configure-addon-settings.php
php -l addons/content-migration/v2/schema/dbvc-cc-v2-target-field-catalog-service.php
php -l addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php
php -l addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php
```

Runtime probe 1:

- temporary file: `/tmp/dbvc_field_context_runtime_probe.php`
- command used local MySQL socket
- result: provider available through local transport
- result: normalized provider, catalog meta, diagnostics, group sample, and entry sample printed successfully

Runtime probe 2:

- temporary file: `/tmp/dbvc_v2_catalog_field_context_probe.php`
- domain used: `butlerautomation.com`
- result: V2 target field catalog built
- result file: `/wp-content/uploads/contentcollector/butlerautomation.com/_schema/dbvc_cc_target_field_catalog.v2.json`
- result: `field_context_provider.available = true`
- result: sample ACF field included `field_context`

Runtime probe 3:

- temporary file: `/tmp/dbvc_mapping_field_context_probe.php`
- result: mapping index created 3 content items
- result: 11 candidates carried `field_context`
- result: provider source hash flowed into mapping trace

Whitespace checks passed:

```bash
git diff --check
```

Ran in both:

- `/wp-content/plugins/db-version-control-main`
- `/wp-content/themes/vertical`

## Known Gaps

### 1. Review UI Does Not Yet Surface Field Context

The artifact trace is now present, but reviewer UI surfaces have not been updated.

Next places to inspect:

- `addons/content-migration/v2/review/dbvc-cc-v2-recommendation-review-service.php`
- `addons/content-migration/v2/admin-app/components/inspectors/InspectorMappingTab.js`
- `addons/content-migration/v2/admin-app/components/inspectors/InspectorAuditTab.js`
- `addons/content-migration/v2/admin-app/components/inspectors/targetPresentation.js`

Recommended UI additions:

- compact Field Context provider status
- field/group purpose summary
- value shape and writable status
- clone projection flag
- warning chips for degraded context
- source hash and contract/schema version in audit/details

### 2. Package QA Does Not Yet Enforce Field Context Readiness

Mapping recommendations carry warnings, but package readiness has not been hardened.

Next places to inspect:

- `addons/content-migration/v2/package/dbvc-cc-v2-url-qa-report-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-qa-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-build-service.php`

Recommended QA additions:

- warn when provider is unavailable after a previous source hash was present
- warn when catalog status is stale/missing/partial
- warn or block on selected target `value_contract.writable = false`
- warn or block when selected target is clone-projected and framework-default publishing is blocked
- warn on `status_meta.code = legacy_only`
- require review on `status_meta.code = missing`
- include provider `source_hash` and `site_fingerprint` in package summary

### 3. Transform And Import Are Not Yet Value-Contract-Aware

Current target transform still infers output shape mostly from target ref.

Next file:

- `addons/content-migration/v2/transform/dbvc-cc-v2-target-transform-service.php`

Recommended changes:

- use `candidate.field_context.value_contract.value_shape` for output shape
- use `value_contract.reference_kind` for media, post, term, user, and relationship routing
- use `allowed_values` for select/checkbox/radio validation
- use `multiple` and `write_behavior` for scalar vs list transforms
- defer or block when `writable = false`
- preserve existing behavior when Field Context is unavailable

Import execution should eventually consume the same value contract:

- map plain strings to scalar fields
- map rich text only where value shape allows
- map attachment references according to return format
- map term references through taxonomy constraints
- block non-writable controls and container-only targets

### 4. Remote Provider Mode Is Still Basic

The service supports remote transport through:

- `dbvc_cc_field_context_remote_provider_config`
- `dbvc_cc_field_context_remote_request_args`

Remote hardening is still open.

Recommended additions:

- store/cache remote ETag or `source_hash`
- send `If-None-Match` when a cached payload exists
- treat `304` as cache-valid after cache exists
- add explicit handling for `400`, `401`, `403`, `404`, `409`, `503`
- add retry/backoff for transient transport failures
- expose remote endpoint test results in DBVC settings
- avoid per-field REST loops; request catalog/group scopes and normalize locally

### 5. Fixture And Test Coverage Is Not Yet Updated

Existing mapping fixtures do not yet assert Field Context enrichment.

Recommended test additions:

- provider normalizer fixture with group + field + clone projection
- V2 target catalog fixture that includes `field_context_provider`
- V2 target catalog fixture with per-field `field_context.value_contract`
- mapping index fixture where candidate includes `field_context`
- recommendation fixture where selected candidate warnings require review
- clone projection fixture that proves duplicate `acf_key` does not overwrite key-path identity

### 6. Source Freshness Needs Run-Level Tracking

Current artifacts can carry source hash, but run-level stale checks need follow-up.

Recommended additions:

- store provider `source_hash` in run profile or stage summary
- compare current catalog `source_hash` to the hash used when mapping/recommendations were generated
- warn when package build uses stale mapping recommendations
- add "rebuild target catalog" as a recommended action when source hash changes

## Recommended Next Phases

### Phase FC-DBVC-1 - Reviewer Visibility

Goal:

- Make Field Context evidence visible where humans approve mappings.

Tasks:

- Add compact Field Context block to recommendation review payload.
- Add display helpers for:
  - provider status
  - field purpose
  - group purpose
  - value shape
  - writable status
  - clone projection status
  - warnings
- Update inspector UI to show Field Context without adding a new complex screen.
- Confirm warnings force `requires_review`.

Acceptance:

- Reviewer can see why a target matched.
- Reviewer can see when a match is weak because context is missing, legacy-only, non-writable, or clone-projected.
- No additional provider request is required from UI code.

### Phase FC-DBVC-2 - Package QA And Readiness Gates

Goal:

- Prevent unsafe packages from looking ready when Field Context says the target is unsafe or stale.

Tasks:

- Add URL QA warnings for selected mapping recommendations with Field Context warnings.
- Add package-level readiness blockers for strict settings.
- Add stale `source_hash` detection.
- Add provider unavailable warning when a previous run used provider context.

Acceptance:

- Package summary reports Field Context provider status.
- Package QA records blocked/degraded Field Context reasons.
- Strict mode can block missing/non-writable/clone-projected targets.

### Phase FC-DBVC-3 - Value-Contract-Aware Transform

Goal:

- Use deterministic `value_contract` for output shape instead of target-ref guesses.

Tasks:

- Update target transform service to read selected candidate Field Context.
- Map `value_contract.value_shape` to DBVC output shape.
- Use `allowed_values` for choice validation.
- Use `reference_kind` and `return_format` for media/post/term references.
- Defer unsupported nested/container-only writes.

Acceptance:

- Transform output shape agrees with Vertical `value_contract`.
- Non-writable/control/container targets are deferred or blocked.
- Existing fallback behavior remains when Field Context is unavailable.

### Phase FC-DBVC-4 - Clone-Safe Matching

Goal:

- Fully stop relying on raw ACF keys for clone-projected targets.

Tasks:

- Prefer `field_context.key_path` when mapping source patterns to target candidates.
- Store key-path identity in candidate and recommendation artifacts.
- Add group-scoped lookup helper for `acf:<group_key>:<field_key>`.
- Add QA warning when a selected target came from raw `entries_by_acf_key` fallback and duplicate ACF-key projections exist.
- Add clone projection display and routing recommendations.

Acceptance:

- Clone-projected targets preserve source/projection provenance.
- Consumer-instance clone projections do not silently overwrite source-owned default assumptions.
- Duplicate ACF-key projections are visible in diagnostics and do not collapse candidate identity.

### Phase FC-DBVC-5 - Remote Provider Hardening

Goal:

- Make remote provider mode usable for separate DBVC and Vertical sites.

Tasks:

- Add cache store keyed by endpoint + criteria + profile + source hash.
- Add ETag / `If-None-Match`.
- Handle remote auth and transport failures with structured provider errors.
- Add admin self-test for configured endpoint.
- Add docs and fixtures for remote-only mode.

Acceptance:

- Remote mode can build a target field catalog without local Vertical helper functions.
- Remote errors degrade DBVC safely and visibly.
- Cache freshness is explicit.

### Phase FC-DBVC-6 - Artifact Contract Tests

Goal:

- Lock the new Field Context artifact shape.

Tasks:

- Add fixtures for provider normalizer, V2 catalog, mapping index, recommendation finalizer, and package QA.
- Add PHPUnit or existing fixture runner assertions.
- Include clone-projection examples.
- Include degraded and unavailable provider examples.

Acceptance:

- Future implementation can safely refactor without dropping Field Context fields.
- Fixtures prove `location`, `value_contract`, `clone_context`, and provider metadata survive through the pipeline.

## Fresh Session Prompt

Use this prompt when continuing in a new Codex session:

```text
We are continuing DBVC / Content Migration V2 Vertical Field Context integration in:

/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/plugins/db-version-control-main

Start by reading:

- addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md
- addons/content-migration/docs/MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_RUNTIME_HANDOFF.md
- addons/content-migration/docs/MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_IMPLEMENTATION_GUIDE.md

Also cross-check the Vertical provider contract docs in:

/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical/docs/field-context-schema.md
/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical/docs/field-context-integration-layer.md
/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical/docs/dbvc-sync.md
/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical/docs/dbvc-field-context-handoff.md

Do not use raw Vertical acf-json files or Field Context smart-authoring artifacts as runtime source of truth.
Use the resolved Vertical service projection through DBVC_CC_Field_Context_Provider_Service.
Treat key_path as opaque provider identity.
Use resolved_purpose for semantic meaning, value_contract for value shape/write behavior, location/object_context for object scope, and clone_context for clone provenance.

Current implemented state:

- DBVC provider service normalizes provider metadata, source_hash, location/object_context, value_contract, clone_context, and diagnostics.
- V2 target catalog embeds field_context_provider plus per-group/per-field field_context traces.
- V2 mapping candidates carry field_context traces and warnings.
- V2 mapping recommendations copy selected candidate field_context and warnings.
- Runtime probes passed locally.

Next logical implementation phase:

Start Phase FC-DBVC-1 reviewer visibility unless the user directs otherwise.
Expose compact Field Context evidence in recommendation review payload/UI, then move to package QA readiness gates.
```

## Validation Commands For Next Session

Syntax checks:

```bash
php -l addons/content-migration/bootstrap/dbvc-cc-addon-bootstrap.php
php -l addons/content-migration/shared/dbvc-cc-field-context-provider-service.php
php -l addons/content-migration/v2/shared/dbvc-cc-v2-contracts.php
php -l addons/content-migration/v2/admin/dbvc-cc-v2-configure-addon-settings.php
php -l addons/content-migration/v2/schema/dbvc-cc-v2-target-field-catalog-service.php
php -l addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php
php -l addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php
git diff --check
```

Local runtime probe pattern:

```bash
php -d mysqli.default_socket='/Users/rhettbutler/Library/Application Support/Local/run/smixn_vNA/mysql/mysqld.sock' \
  -d pdo_mysql.default_socket='/Users/rhettbutler/Library/Application Support/Local/run/smixn_vNA/mysql/mysqld.sock' \
  /tmp/dbvc_field_context_runtime_probe.php
```

If the LocalWP site id changes, refresh the socket from:

```text
~/Library/Application Support/Local/sites.json
```

## Important Caveats

- Current Field Context catalog is degraded because many entries remain `missing` or `legacy_only`. This is expected during rollout.
- Clone projections create duplicate raw ACF keys. This is expected and must be handled with `key_path`, `clone_context`, and group-scoped matching.
- `entries_by_acf_key` exists for backward compatibility only; it is not clone-safe.
- Field Context is additive to DBVC. If unavailable, DBVC should continue existing behavior and record provider degradation.
- DBVC must not mutate Vertical framework defaults, site overrides, smart-authoring runs, publish previews, backups, or rollback logs.

