# DBVC Bricks Reference Mapping Implementation Plan

Date: 2026-06-09

Status: initial backend implementation in progress. Core export/import support exists for Bricks `templatePreviewPostId` and `templatePreviewTerm` references. Proposal review now exposes Bricks reference preflight summaries in REST payloads, shows a compact selected-proposal notice, and can block proposal apply when unresolved references are present and the unresolved-reference policy is set to `block`. Broader Bricks condition/query rules remain pending.

Related docs:

- `docs/import-identity-matching.md`
- `docs/PROPOSAL_DIFF_SYSTEM_MINOR_UPDATE_IMPLEMENTATION_GUIDE.md`
- `docs/DBVC_BRICKS_ADDON_HANDOFF.md`
- `addons/bricks/docs/BRICKS_ADDON_PLAN.md`

## Goal

Add a minor, focused enhancement that localizes known Bricks entity ID references during DBVC imports.

The enhancement should make Bricks template imports safer when a source site stores numeric IDs inside Bricks settings, such as:

- `_bricks_template_settings.*.templatePreviewPostId`
- Bricks template conditions that target concrete posts, terms, users, or archives
- Bricks query/settings fields that store concrete post or term IDs

The system must not perform broad recursive numeric replacement. Only known, registered Bricks reference paths may be localized.

## Recommendation

Implement this as a small core DBVC reference-localization subsystem with a Bricks reference provider.

Reasoning:

- The write path that needs localization is core `DBVC_Sync_Posts::import_post_from_json()`.
- Manual single-file JSON imports do not necessarily use the Bricks add-on package workflow.
- Proposal/diff, transfer packets, legacy import, and Entity Editor import should all share the same reference contract.
- Bricks-specific path knowledge should stay isolated in a provider so core does not become a Bricks parser.

The Bricks add-on can extend the provider with package/drift-specific rules later, but the initial provider should be available to the core post import/export path.

## Non-Goals

- Do not preserve source numeric WordPress IDs as the primary fix.
- Do not rewrite every number found in Bricks payloads.
- Do not support unknown Bricks paths by guessing from field names alone.
- Do not make unresolved references writable silently.
- Do not require a full packaged proposal export/import for the feature to work.
- Do not change Bricks Builder storage format beyond localizing known ID values.

## Entity JSON Contract

Add a top-level entity JSON property, not a WordPress post meta key:

```json
{
  "dbvc_entity_references": [
    {
      "schema": "dbvc.entity_reference.v1",
      "provider": "bricks",
      "kind": "post",
      "source_id": 107582,
      "source_value_type": "string",
      "path": "meta._bricks_template_settings.0.templatePreviewPostId",
      "meta_key": "_bricks_template_settings",
      "context": {
        "post_type": "listing",
        "slug": "sample-listing",
        "vf_object_uid": "source-entity-uid",
        "title": "Sample Listing"
      },
      "policy": "localize_on_import",
      "confidence": "high"
    }
  ]
}
```

This property should be included in exported post JSON and mirrored into `manifest.json` / `entities.jsonl` when proposal packages are generated.

Manual copy support depends on this top-level property: if an operator copies one `bricks_template` JSON from one site to another, the JSON still carries enough reference context for the importer to resolve `107582` to the local `/sample-listing` ID.

If the property is absent, the importer may perform a best-effort scan of known Bricks paths in the incoming payload and build transient references before applying.

## Reference Descriptor Fields

Each reference descriptor should include:

- `schema`: fixed version string.
- `provider`: `bricks`.
- `kind`: `post`, `term`, `user`, or `unknown`.
- `source_id`: the numeric ID stored in the source Bricks payload.
- `source_value_type`: `string`, `integer`, or `array`.
- `path`: dot-path from the entity JSON root to the stored value.
- `meta_key`: the post meta key containing the value.
- `context`: lookup hints, such as UID, post type, slug, taxonomy, title, and source URL.
- `policy`: usually `localize_on_import`.
- `confidence`: `high`, `medium`, or `low`.
- `status`: optional preflight/apply state, such as `resolved`, `unresolved`, `ambiguous`, or `blocked`.

Do not store this as normal post meta. Storing it as top-level JSON avoids polluting the destination database and avoids accidental Bricks or ACF interpretation.

## Initial Bricks Path Rules

Phase 1 should support only high-confidence paths:

| Path | Kind | Notes |
| --- | --- | --- |
| `meta._bricks_template_settings.*.templatePreviewPostId` | post | Resolve by UID first, then post type + slug. Preserve string value if Bricks stored a string. |
| `meta._bricks_template_settings.*.templatePreviewTerm` | term | Resolve scoped `taxonomy::term_id` values by UID first, then taxonomy + slug. Preserve Bricks `taxonomy::local_id` format. |
| `meta._bricks_template_settings.*.templateConditions.*` concrete single post selectors | post | Add only after local fixture confirms exact Bricks shape. |
| `meta._bricks_template_settings.*.templateConditions.*` concrete term selectors | term | Add only after local fixture confirms concrete condition selectors. Current local fixtures show `taxonomy::all` archive conditions, which should not be localized. |

Keep all other Bricks content/query settings inspect-only until fixtures prove their exact storage shape.

## Export Flow

1. During `DBVC_Sync_Posts::prepare_post_export()`, call a reference collection service after the post payload is assembled.
2. The Bricks provider inspects known Bricks meta keys and exact path rules.
3. For each source ID, load the referenced source object when possible.
4. Add UID, slug, type, taxonomy, and title hints to the descriptor.
5. Write `dbvc_entity_references` into the exported JSON.
6. When generating a proposal manifest, copy the same references into the manifest entry and `entities.jsonl`.

If a referenced object cannot be loaded on the source site, still include the raw path and source ID with `confidence: low` and `status: source_missing`.

## Import Flow

1. Before writing post meta in `DBVC_Sync_Posts::import_post_from_json()`, call a reference localization service.
2. Build a source-to-local map from:
   - current import batch `source_id => local_id` mappings
   - proposal manifest references
   - `dbvc_entity_references` inside the JSON
   - UID lookup
   - post type + slug or taxonomy + slug lookup when UID is unavailable and the policy allows it
3. Resolve only descriptors with known providers and known paths.
4. Patch only the exact path value.
5. Preserve the original scalar type where possible.
6. Record localization results in import history and proposal/apply receipts.

If a high-confidence Bricks reference is unresolved, proposal preflight should warn or block depending on setting. Legacy/manual import should warn and record the unresolved reference instead of silently claiming success.

## Settings

Add an import setting:

- `dbvc_localize_bricks_entity_references`
- Default: enabled for proposal/packaged import preflight, enabled for manual import with warnings.

Add an unresolved-reference policy:

- Option: `dbvc_bricks_reference_unresolved_policy`
- Default: `warn`
- `warn`: preserve source value and log warning. Recommended default for legacy/manual import.
- `block`: block proposal apply until mapped or explicitly ignored. Recommended for proposal apply.
- `clear`: remove the reference only when the path rule explicitly supports clearing. Defer this mode.

Do not tie this setting to exact source ID creation.

## Proposal/Diff UI Impact

Preflight should show a Bricks References section:

- resolved references: source ID, local ID, object label, path
- unresolved references: source ID, path, missing lookup hints
- ambiguous references: candidates and reason
- blocked references: why apply cannot proceed

Entity drawers should show Bricks reference paths as structured rows, not raw nested array noise.

## Exact ID Creation Relationship

Exact source post ID creation can reduce remapping work on clean targets, but it does not replace reference localization.

Reasons:

- It is posts-only through WordPress `import_id`.
- Terms do not have a safe public exact-ID insert API.
- Source IDs may already be occupied.
- Orphaned `postmeta`, comments, term relationships, or third-party table rows may already reference an otherwise unused post ID.
- Mixed imports still need a source-to-local ID map.

Reference localization must work whether exact-ID creation is enabled or disabled.

## Implementation Phases

### Phase 0: Fixtures and Shape Audit

- Capture Bricks template JSON examples for:
  - template preview post
  - specific post condition
  - specific term condition
  - non-concrete post type/archive conditions
- Document exact storage paths and value types.
- Add fixtures under `tests/fixtures/` or a narrowly named Bricks reference fixture folder.

### Phase 1: Descriptor Collection

- Add reference provider interface and collection service.
- Add Bricks provider with only `templatePreviewPostId`.
- Add `dbvc_entity_references` to post export JSON.
- Add manifest propagation.
- Add tests proving manual single JSON contains the reference descriptor.

### Phase 2: Localization Service

- Add exact path patcher with scalar type preservation.
- Resolve references by UID, then source-to-local import map, then safe slug/type fallback.
- Apply localization before post meta writes.
- Record warnings/results in import history.
- Add tests for source ID `107582` resolving to local ID for `/sample-listing`.

### Phase 3: Proposal Preflight and Apply

- Add preflight summary for Bricks references.
- Block or warn based on unresolved-reference policy.
- Write apply receipt details.
- Add tests for resolved, unresolved, occupied-ID, and ambiguous-slug cases.

Current implementation note: proposal list/entity REST payloads include `bricks_references` counts and rows. The selected proposal UI shows a compact resolved/unresolved notice. Apply records localization results in `dbvc_post_history`, and proposal apply returns a `dbvc_bricks_reference_preflight_blocked` REST error before import when `dbvc_bricks_reference_unresolved_policy=block` and preflight has unresolved supported Bricks references.

### Phase 4: Expand Bricks Rules

- Add template condition post/term selectors after fixture confirmation.
- Add Bricks query setting references only after exact storage shape is proven.
- Keep unsupported Bricks references inspect-only.

## Validation

Minimum validation before release:

- Exported `bricks_template` JSON includes `dbvc_entity_references`.
- Manual single-file import localizes `templatePreviewPostId`.
- Proposal upload shows resolved/unresolved Bricks reference preflight rows.
- Proposal apply localizes references before writing `_bricks_template_settings`.
- Existing non-Bricks post imports are unchanged.
- Bricks payloads with arbitrary numeric style/layout values are not modified.
- Unresolved reference policy is honored.

## Open Decisions

1. Should the first UI expose manual mapping for unresolved references, or only show warnings and require JSON/source cleanup?
2. Should Bricks add-on settings surface advanced rule toggles, or should the first release keep this entirely under core Import Defaults?
