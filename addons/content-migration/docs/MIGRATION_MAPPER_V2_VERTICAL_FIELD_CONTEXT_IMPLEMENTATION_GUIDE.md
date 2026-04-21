# Migration Mapper V2 Vertical Field Context Implementation Guide

## Goal

Replace the normal Content Migration V2 mapping context source for Vertical ACF targets with Vertical's resolved field-context service payload while keeping DBVC's existing catalog and migration artifacts stable enough for incremental rollout.

This guide is for the future implementation tranche. It is not an implementation patch.

## Step 1 - Add Provider Abstraction

Create a shared provider interface with a small contract that matches DBVC's needs rather than exposing every Vertical implementation detail.

Suggested interface:

```php
interface DBVC_CC_Field_Context_Provider_Interface {
    public function is_available(): bool;
    public function get_catalog( array $criteria = array(), string $profile = 'mapping' ): array;
    public function get_group( string $group_identifier, array $criteria = array(), string $profile = 'mapping' ): array;
    public function get_entry( string $key_path, array $criteria = array(), string $profile = 'mapping' ): array;
    public function get_object_field( int $post_id, $field_selector, string $profile = 'full' ): array;
    public function get_status( array $criteria = array() ): array;
}
```

Implementation notes:

- Use DBVC's `DBVC_CC_*` class/file prefix in code unless a broader plugin convention says otherwise.
- Add `DBVC_CC_Field_Context_Provider_Local`, `DBVC_CC_Field_Context_Provider_Remote`, `DBVC_CC_Field_Context_Normalizer`, and `DBVC_CC_Field_Context_Match_Scorer` as first-slice helpers.
- Use a service wrapper to choose local same-runtime first when Vertical helper functions exist.
- Fall back to remote REST only when configured and authenticated.
- Return a normalized unavailable result when neither provider is available.
- Do not silently switch to raw theme ACF JSON traversal.
- Keep the fallback to DBVC's existing ACF runtime catalog explicit and visible in artifact metadata.

## Step 2 - Implement Local Provider

Local mode should call Vertical's helper wrappers directly:

- Catalog: `vf_field_context_get_service_catalog_payload( $criteria, $profile )`
- Group: `vf_field_context_get_service_group_payload( $group_identifier, $criteria, $profile )`
- Entry by key path: `vf_field_context_get_service_entry_payload( $key_path, $criteria, $profile )`
- Object field: `vf_field_context_get_service_object_field_payload( $post_id, $field_selector, $profile )`
- Direct object field context: `vf_field_context_get_entry_for_post_field( $post_id, $field_selector )`

Guardrails:

- Check `function_exists()` before calling helper functions.
- Validate `provider.name`, `contract_version`, and expected envelope keys.
- Default catalog/group/profile calls to `mapping`.
- Use `full` only for focused reviewer details or object-field inspection where the payload size is justified.

## Step 3 - Implement Remote Provider

Remote mode should use:

- `GET /wp-json/vertical-framework/v1/field-context`

Supported query concepts:

- `post_id`
- `profile`
- `group`
- `key_path`
- `name_path`
- `acf_key`
- `acf_name`

Remote guardrails:

- Respect the one-selector-per-request rule.
- Do not attempt remote `field_selector` calls because they are same-runtime only.
- Prefer scoped catalog/group requests and local DBVC normalization/cache over per-field REST loops.
- Use `If-None-Match` when a cached `source_hash` or ETag is available.
- Treat `304` as cache-valid, not as a provider failure.
- Map `400`, `401/403`, `404`, transport errors, and malformed envelopes into structured DBVC provider errors.
- Do not rely on `409` or `503` until Vertical implements those error modes.
- Keep remote provider degradation additive and warning-oriented until REST auth, retry/backoff, batch lookup, and richer error semantics are stable.

## Step 4 - Normalize Provider Envelopes

Add a normalizer that accepts Vertical service payloads and returns a DBVC-internal shape that can be embedded in target catalog, mapping, recommendation, review, and package artifacts.

Recommended top-level normalized block:

```json
{
  "field_context_provider": {
    "transport": "local",
    "provider": "vertical-field-context",
    "contract_version": 1,
    "catalog_status": "fresh",
    "source_hash": "example",
    "cache_layer": "runtime",
    "cache_version": "example",
    "matched_by": "catalog"
  }
}
```

Recommended group block:

```json
{
  "key_path": "provider-canonical-key-path",
  "name_path": "provider-canonical-name-path",
  "location": [],
  "object_context": {
    "post_types": [],
    "taxonomies": [],
    "options_pages": [],
    "unknown_rules": []
  },
  "resolved_purpose": "",
  "status_meta": {},
  "coverage": {},
  "resolved_from": "default"
}
```

Recommended field block:

```json
{
  "target_ref": "acf:group_key:field_key",
  "key_path": "provider-canonical-key-path",
  "name_path": "provider-canonical-name-path",
  "parent_key_path": "provider-canonical-parent-key-path",
  "parent_name_path": "provider-canonical-parent-name-path",
  "group_key": "group_key",
  "group_name": "group_name",
  "acf_key": "field_key",
  "acf_name": "field_name",
  "scope": "field",
  "type": "text",
  "container_type": "",
  "branch_chain": [],
  "context": {},
  "default_context": {},
  "resolved_purpose": "",
  "default_purpose": "",
  "status_meta": {},
  "has_override": false,
  "resolved_from": "default",
  "matched_by": "key_path"
}
```

Normalizer rules:

- Treat `key_path` as opaque canonical provider data. Do not synthesize or parse separators, and compare only exact strings.
- Use explicit fields such as `name_path`, `parent_key_path`, `parent_name_path`, `group_key`, `group_name`, `acf_key`, `acf_name`, `scope`, `type`, and `container_type` for hierarchy and display.
- Preserve raw `location` as provided and add a DBVC-normalized `object_context` helper block.
- Preserve unknown fields where they are useful for reviewer diagnostics, but keep artifact additions additive.
- Add explicit diagnostic reason codes for lower-confidence matches and provider degradation.

## Step 5 - Enrich Target Field Catalog

Update `DBVC_CC_V2_Target_Field_Catalog_Service` first.

Implementation shape:

1. Build the existing catalog through the current service.
2. Ask the field-context provider for a `mapping` profile catalog.
3. Normalize the provider payload.
4. Merge field-context evidence into the V2 target field catalog artifact.
5. Preserve a top-level provider status block even when the provider is unavailable.
6. Keep existing target refs stable for current downstream consumers.

When provider data is available:

- Add group-level `field_context` blocks to ACF groups.
- Add field-level `field_context` blocks to ACF fields or a parallel lookup keyed by target ref.
- Add normalized object compatibility derived from group `location`.
- Treat `core_group` style page/vertical post type rules and `services_group` service CPT rules as object compatibility examples, not hardcoded group names.
- Store provider metadata in the catalog bundle.

When provider data is unavailable:

- Keep existing DBVC behavior.
- Add `field_context_provider.status = "unavailable"` or equivalent.
- Add a clear reason such as `missing_local_helpers`, `remote_not_configured`, `remote_auth_failed`, or `contract_mismatch`.

## Step 6 - Update Candidate Matching And Scoring

Update `DBVC_CC_V2_Mapping_Index_Service` to use the enriched catalog.

Add `DBVC_CC_Field_Context_Match_Scorer` so scoring rules are testable outside the mapping index service and reusable by AI mapping or reviewer QA.

Recommended matching precedence:

1. `key_path`
2. Scoped `acf_key`
3. `name_path`
4. `acf_name` with group/branch/object disambiguation
5. `acf_name` alone as low-confidence fallback

Recommended scoring adjustments:

- Exact `key_path` match: boost and record `matched_by = key_path`.
- Exact scoped `acf_key` match: boost and record `matched_by = acf_key`.
- `name_path` match: smaller boost and record `matched_by = name_path`.
- `acf_name` fallback: lower confidence, require review when duplicated or branch context is weak.
- Group `location` object match: boost candidates whose location matches the source target object class.
- Group `location` object mismatch: lower confidence or block automated acceptance when the ACF candidate is otherwise weak.
- Branch/section purpose alignment: boost when source section/context tags align with ancestor branch purpose or branch family.
- Field purpose alignment: boost only after object and branch compatibility are acceptable.
- `status_meta.status = legacy_only`: lower confidence and warn.
- `status_meta.status = missing`: lower confidence and require review when context is needed.
- `resolved_from = legacy`: lower confidence.
- `resolved_from = override` or `local`: allow normal semantic confidence when the purpose is complete.
- Remote provider unavailable or incomplete: warn/degrade, but avoid hard-blocking broad automation until remote mode is hardened.

Artifact trace to attach to candidates:

```json
{
  "field_context": {
    "provider": "vertical-field-context",
    "contract_version": 1,
    "source_hash": "example",
    "matched_by": "acf_key",
    "resolved_from": "override",
    "status": "complete",
    "object_compatible": true,
    "branch_context_used": true,
    "warnings": []
  }
}
```

## Step 7 - Propagate Recommendation Trace

Update `DBVC_CC_V2_Recommendation_Finalizer_Service`.

Requirements:

- Copy field-context trace from selected candidates into `mapping-recommendations.v2.json`.
- Preserve enough context for reviewer QA without requiring another provider lookup.
- Mark recommendations as requiring review when context degradation crosses the scoring threshold.
- Keep generated AI suggestions separate from provider-resolved context.

Recommended additional fields:

- `field_context.provider`
- `field_context.contract_version`
- `field_context.source_hash`
- `field_context.matched_by`
- `field_context.resolved_from`
- `field_context.status_meta`
- `field_context.group_purpose`
- `field_context.branch_purpose`
- `field_context.field_purpose`
- `field_context.object_context`
- `field_context.warnings`

## Step 8 - Enrich Schema Presentation And Reviewer QA

Update `DBVC_CC_V2_Schema_Presentation_Service` so reviewers can see field-context labels and reasons in the existing workflow.

Recommended presentation additions:

- Group purpose.
- Branch purpose or ancestor chain summary.
- Field purpose.
- Object compatibility summary from group `location`.
- Context status such as complete, override, legacy-only, or missing.
- `matched_by` evidence.

Update `DBVC_CC_V2_Recommendation_Review_Service` so review payloads include concise diagnostics beside the current field recommendation display.

Do not add a new complex UI initially. Reuse existing inspector/detail surfaces and expose data in a compact field-context block.

## Step 9 - Add Package And Readiness Safety Checks

Update package and QA services after recommendation trace is available.

Recommended checks:

- Provider missing after a previous run used a provider source hash.
- Provider source hash changed since recommendations were generated.
- Catalog status stale, partial, or missing.
- Candidate matched only by duplicated `acf_name`.
- Group `location` mismatch for the target object type.
- Field context status missing or legacy-only where semantic context was required for confidence.
- Remote provider contract mismatch.

Recommended severity:

- Blocker: object type mismatch with no stronger exact evidence.
- Blocker: provider contract mismatch when recommendations depend on provider context.
- Warning: provider source hash changed.
- Warning: legacy-only context.
- Warning: `acf_name` fallback.
- Warning: missing branch context when the same field name repeats across branches.

## Step 10 - Validation Plan

Fixture tests:

- Copy representative Vertical example payloads into DBVC-owned test fixtures.
- Test catalog summary normalization.
- Test group mapping normalization.
- Test full post-field normalization.
- Test `400`, `401`, `403`, and `404` provider error normalization.
- Add or update fixtures with group `location` once Vertical refreshes examples.
- Add duplicate `acf_name` fixture coverage across groups and branches.
- Add branch-context fixture coverage for parent paths and resolved branch purpose.

Recommended Vertical fixture requests:

- `provider-group-mapping-page.json`
- `provider-group-mapping-service.json`
- `provider-group-summary-location.json`
- `provider-entry-branch-context-full.json`
- `provider-duplicate-acf-name.json`
- `provider-remote-error-401.json`
- `provider-remote-error-403.json`
- `provider-remote-error-404.json`
- `provider-remote-error-400-field-selector-without-post-id.json`
- `provider-batch-planned-shape.json`, marked non-implemented.

Scoring tests:

- Exact `key_path` beats same `acf_name`.
- Exact `acf_key` beats duplicated `acf_name`.
- `acf_name` fallback requires review.
- Branch context boosts a repeated shared field name only under the matching branch.
- `legacy_only` lowers confidence.
- `missing` context prevents automation when the match depends on semantic context.
- Group `location` post type mismatch lowers confidence or blocks acceptance.

Runtime probes:

- Run local provider probe only inside the allowed `dbvc-codexchanges.local` environment when the provider is installed there.
- Run remote REST probe only when external provider access is enabled and authentication is configured.
- Probe `mapping` catalog, focused group, focused entry, and object-field lookup in local mode.
- Probe ETag/304 behavior in remote mode when available.

Suggested validation commands for the implementation tranche:

```bash
vendor/bin/phpunit --filter ContentCollectorV2VerticalFieldContextTest
vendor/bin/phpunit
npm run build
```

Use existing DBVC runtime smoke scripts only after the adapter is implemented and loaded.

## Vertical Questions And Fixture Requests Before Coding

- Refresh `provider-group-mapping.json` and `provider-catalog-summary.json` so current examples include group `location`.
- Confirm all docs and fixtures present `key_path` as an opaque provider ID, not a parseable hierarchy string.
- Should `mapping` profile include enough ancestor or branch data for DBVC to avoid `full` profile calls in normal catalog builds?
- Is a batch lookup endpoint planned soon enough to avoid remote per-field loops?
- What is the intended stable authentication mechanism for DBVC remote mode?
- Should provider payload include explicit object-context normalization for options pages and taxonomy rules, or should DBVC own that normalization from raw ACF `location`?

## Rollout Recommendation

Ship this behind an additive V2 field-context provider service:

1. Land provider abstraction and normalizer tests.
2. Enrich V2 target field catalog while preserving existing refs and fallback behavior.
3. Add mapping index scoring with trace output.
4. Add recommendation, review, and package QA trace propagation.
5. Add minimal reviewer diagnostics in existing UI surfaces.
6. Make provider degradation visible before using it to block automation broadly.

Keep raw Vertical theme ACF JSON out of DBVC's normal runtime path.
