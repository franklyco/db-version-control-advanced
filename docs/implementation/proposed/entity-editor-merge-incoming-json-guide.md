# Entity Editor Merge Incoming JSON Implementation Guide

Last updated: 2026-07-02
Current phase: `P0`
Status legend: `OPEN` | `WIP` | `CLOSED` | `DEFERRED`

## Objective

Add a focused Entity Editor workflow that lets an operator choose an existing entity JSON row, open the normal JSON editor modal, paste an incoming DBVC entity JSON blob, preview a proposed merge, and optionally save or partially import the merged result.

The feature should solve the common cross-site case where two entities have the same intended canonical purpose but different local IDs, slugs, UIDs, Bricks condition references, or other site-local values.

This should remain a quick, safe Entity Editor tool. It should not become a second proposal system, a generalized migration workbench, or a full field-by-field merge engine in the first version.

## Current Support

DBVC already has adjacent primitives:

- `Edit JSON` opens a selected sync file with lock-token protection.
- `Save JSON` validates, backs up, and atomically replaces the selected sync file.
- `Save + Partial Import` saves JSON and applies JSON-present core fields, meta, and taxonomies to one matched local entity without deleting missing meta keys.
- `Save + Full Replace` exists for explicit destructive replacement.
- `New From Raw JSON` can create or update a matched entity from a pasted payload, but it chooses the target by DBVC matching, not by the selected Entity Editor row.
- `Import Sync JSON > Update Matched Entity` can update high-confidence UID-matched post/CPT files, but it is not a selected-entity merge surface.

Missing today:

- a second pasted JSON input attached to the selected entity editor modal
- a selected-current-entity merge target contract
- a reusable merge preview service
- local identity preservation controls
- Bricks-specific mismatch notes for template scope and condition references
- a generated merged JSON preview before save/import

## UX Shape

Entry point:

- Add a small `Merge Incoming JSON` action inside the existing Entity Editor JSON modal.
- Keep it unavailable until a file is loaded and locked through the existing editor flow.
- Do not add a new top-level menu or separate importer screen.

Modal/panel flow:

1. User opens a current entity row from the Entity index.
2. User clicks `Merge Incoming JSON`.
3. UI opens an inline panel or child modal with:
   - a textarea for the incoming JSON blob
   - `Preview Merge`
   - a compact identity toolbar
   - blocker/warning notes
   - a proposed merged JSON preview
   - an `I confirm` checkbox before save/import actions enable
4. User previews the merge.
5. User chooses one of:
   - `Save Merged JSON`
   - `Save Merged JSON + Partial Import`

Do not include `Full Replace` in v1. A later version can add destructive apply modes after the diff/proposal layer is ready to show exact delete/replace impact.

## Identity Toolbar

The preview UI should include a small toolbar for the few values operators reasonably expect to control.

Toolbar fields:

| Field | Default v1 behavior | Operator option |
|---|---|---|
| UID | Keep local/current UID | Use incoming UID |
| Slug | Keep local/current slug | Use incoming slug |
| Post or term ID | Always keep local/current ID | Display incoming ID as ignored |
| Title | Use incoming title | Keep local/current title |

Rules:

- ID should not be overrideable in v1 because WordPress IDs are site-local and changing them in JSON is the main source of duplicate-file and wrong-target risk.
- UID override is allowed only after confirmation because UID changes can affect future matching behavior.
- Slug override should show a warning if the target slug already belongs to another entity of the same subtype.
- Title can be a simple current/incoming toggle because it is low risk and easy to reason about.
- Toolbar changes must invalidate the current confirmation checkbox and require a fresh preview hash.

Recommended labels:

- `UID: keep local | use incoming`
- `Slug: keep local | use incoming`
- `ID: keep local`
- `Title: use incoming | keep local`

## Opinionated Merge Policy

The first version should generate one proposed JSON result using simple defaults.

Post/CPT payloads:

- Identity:
  - preserve local/current `ID`
  - preserve local/current UID unless the toolbar explicitly selects incoming UID
  - preserve local/current slug unless the toolbar explicitly selects incoming slug
  - title follows the toolbar setting
- Core content fields:
  - incoming wins when the field is present
  - missing incoming fields do not clear current fields
- Meta:
  - merge by meta key
  - incoming present keys win
  - missing incoming keys do not delete local/current keys
  - protected local meta remains local when existing DBVC protected-meta rules say it should not be replaced
- Taxonomies:
  - incoming present taxonomy assignments replace the current JSON value for that taxonomy in v1
  - missing incoming taxonomies do not delete current taxonomy data

Term payloads:

- Identity:
  - preserve local/current `term_id`
  - preserve local/current UID unless the toolbar explicitly selects incoming UID
  - preserve local/current slug unless the toolbar explicitly selects incoming slug
  - title/name follows the toolbar setting
- Description and term meta:
  - incoming present values win
  - missing incoming values do not delete current values
- Parent references:
  - preserve local parent unless the incoming parent can be matched by UID or taxonomy+slug
  - surface a warning when the incoming parent cannot be mapped

Bricks templates:

- Treat Bricks data as a whole-template content section in v1.
- Preserve the local post ID, local canonical path, and local UID by default.
- Preserve local Bricks template scope/condition IDs when the incoming values point at source-site IDs that cannot be mapped.
- Surface notes for condition/scope mismatches instead of trying automatic element-level merging.
- Do not implement selective Bricks element merges in v1.

## Preview Notes And Soft Flags

The preview response should distinguish hard blockers from soft notes.

Hard blockers:

- invalid JSON
- unsupported entity kind
- current entity and incoming entity kind mismatch
- current post type or taxonomy mismatch
- missing local entity match for the selected file when partial import is requested
- stale lock token or stale preview hash
- slug override collides with another same-subtype entity

Soft notes:

- incoming numeric ID differs from local ID and will be ignored
- incoming UID differs from local UID and local UID is selected
- incoming slug differs from local slug and local slug is selected
- protected local meta keys were preserved
- incoming protected meta values were ignored
- Bricks template condition IDs differ and local values will be kept
- Bricks template preview post or term IDs differ and may need manual remapping later
- incoming ACF field keys or references are present but not validated against local field groups

Soft notes should not block `Save Merged JSON` or `Save Merged JSON + Partial Import` after confirmation.

## Backend Shape

Add a small reusable merge service rather than placing merge logic directly in REST callbacks.

Recommended class:

- `DBVC\EntityEditor\EntityJsonMergeService`

Responsibilities:

- load the selected current entity through existing Entity Editor safe-path helpers
- parse and classify incoming JSON using shared raw-intake/sync-file classification helpers where practical
- validate current/incoming compatibility
- apply identity toolbar choices
- produce:
  - proposed merged JSON
  - hard blockers
  - soft notes
  - normalized identity summary
  - preview hash
  - optional lightweight field summary counts

Avoid duplicating import behavior:

- `Save Merged JSON` should reuse the existing Entity Editor save path.
- `Save Merged JSON + Partial Import` should reuse the existing partial import path after saving the proposed merged JSON.
- Do not call low-level post/term importers directly from the new merge service.

## REST Contract

Add routes under the existing Entity Editor namespace:

- `POST /dbvc/v1/entity-editor/merge-json/preview`
- `POST /dbvc/v1/entity-editor/merge-json/save`
- `POST /dbvc/v1/entity-editor/merge-json/save-and-partial-import`

Preview request:

```json
{
  "path": "bricks_template/example.json",
  "lock_token": "current-lock-token",
  "incoming_json": "{...}",
  "identity": {
    "uid": "keep_local",
    "slug": "keep_local",
    "title": "use_incoming"
  }
}
```

Preview response:

```json
{
  "ok": true,
  "preview_hash": "stable-hash",
  "summary": {
    "kind": "post",
    "subtype": "bricks_template",
    "local_id": 120684,
    "incoming_id": 120682,
    "uid_policy": "keep_local",
    "slug_policy": "keep_local",
    "title_policy": "use_incoming"
  },
  "blockers": [],
  "notes": [
    {
      "code": "incoming_id_ignored",
      "severity": "note",
      "message": "Incoming ID differs from the local entity and will be ignored."
    }
  ],
  "proposed_json": "{...}"
}
```

Save request:

```json
{
  "path": "bricks_template/example.json",
  "lock_token": "current-lock-token",
  "preview_hash": "stable-hash",
  "incoming_json": "{...}",
  "identity": {
    "uid": "keep_local",
    "slug": "keep_local",
    "title": "use_incoming"
  },
  "confirmed": true
}
```

Server-side save rules:

- Re-run preview immediately.
- Require the preview hash to match.
- Require `confirmed: true`.
- Require no hard blockers.
- Save only the regenerated proposed JSON, not a client-mutated proposed blob.
- Create the same backup/audit record as normal Entity Editor save.
- For save-and-partial-import, call the existing partial import implementation after save.

## UI Implementation Notes

Keep the first UI slice small:

- place the action near existing `Save JSON`, `Save + Partial Import`, and `Save + Full Replace` controls
- reuse the existing blocker/notes panel style from raw-intake and sync-file import modals
- use a compact segmented-control style for toolbar choices
- use an explicit `I confirm merging incoming JSON into this selected entity` checkbox
- disable save/apply buttons when:
  - preview has not run
  - incoming JSON changed after preview
  - toolbar choices changed after preview
  - hard blockers exist
  - confirmation is unchecked
- keep the current JSON editor open after save and replace its content with the merged JSON
- refresh the Entity index after save-and-partial-import

## Phases

| Phase | Status | Goal |
|---|---|---|
| P0 | `OPEN` | Confirm existing helpers, current JSON shapes, and safest service boundaries |
| P1 | `OPEN` | Add reusable merge preview service |
| P2 | `OPEN` | Add REST preview/save endpoints |
| P3 | `OPEN` | Add modal UI, identity toolbar, preview notes, and confirmation gating |
| P4 | `OPEN` | Wire save-only and save-plus-partial-import actions through existing Entity Editor paths |
| P5 | `OPEN` | Add Bricks template soft flags for condition/scope/preview reference mismatches |
| P6 | `OPEN` | Add focused PHPUnit and UI smoke coverage |
| P7 | `DEFERRED` | Integrate richer proposal/diff decisions and selective Bricks element merges |

## P0. Current-State Review

Status: `OPEN`

Tasks:

- Confirm selected file loading can reuse the same safe-path helper used by edit/download/save.
- Confirm lock-token requirements match `Save JSON` and partial import.
- Identify the smallest shared payload classifier between raw-intake, sync-file import, and this merge service.
- Confirm protected-meta behavior is centralized enough to report preserved/ignored keys consistently.
- Confirm Bricks condition/scope data shapes in exported `bricks_template` JSON.
- Confirm term parent matching behavior before enabling term save-and-partial-import.

Exit criteria:

- The service boundary is documented in code comments or tests.
- No new importer path is introduced.
- The first implementation can support posts/CPTs and either support or explicitly block terms until term semantics are verified.

## P1. Merge Preview Service

Status: `OPEN`

Tasks:

- Create the reusable merge service.
- Add current/incoming compatibility validation.
- Implement identity toolbar policies.
- Implement opinionated merge policy for post/CPT payloads.
- Add blockers and soft notes.
- Generate a deterministic preview hash from:
  - selected path
  - current file content hash
  - incoming JSON hash
  - identity toolbar choices
  - generated proposed JSON hash

Exit criteria:

- Invalid and mismatched payloads return blockers.
- Valid same-type payloads return proposed JSON and notes.
- Incoming numeric IDs are ignored and surfaced as notes.
- Current/local IDs are preserved.

## P2. REST Endpoints

Status: `OPEN`

Tasks:

- Register preview/save/save-and-partial-import routes under the existing Entity Editor namespace.
- Reuse Entity Editor permission checks.
- Reuse lock-token validation.
- Re-run preview inside write endpoints.
- Block stale preview hashes and missing confirmation.
- Return the same style of notices/blockers used by existing import modals.

Exit criteria:

- Preview is read-only.
- Save writes the same file the selected editor modal has locked.
- Save-and-partial-import updates only JSON-present data through the existing partial import path.

## P3. UI Wiring

Status: `OPEN`

Tasks:

- Add `Merge Incoming JSON` action to the current editor modal.
- Add incoming JSON textarea.
- Add identity toolbar:
  - UID
  - slug
  - ID display-only
  - title
- Add preview button.
- Add blockers/notes panel.
- Add proposed JSON preview.
- Add confirmation checkbox.
- Add save buttons.

Exit criteria:

- The user can preview before saving.
- Confirmation resets after any input or toolbar change.
- Hard blockers are visible and prevent writes.
- Soft notes remain visible but do not prevent confirmed writes.

## P4. Save And Partial Import Reuse

Status: `OPEN`

Tasks:

- Route `Save Merged JSON` through the existing save implementation.
- Route `Save Merged JSON + Partial Import` through the existing partial import implementation.
- Ensure backups, logs, lock checks, and index refresh match current Entity Editor behavior.
- Keep duplicate-file canonicalization behavior unchanged.

Exit criteria:

- No new low-level importer call path exists.
- Save-only does not change the WordPress database.
- Save-and-partial-import updates the selected matched local entity.
- Existing raw-intake and sync-file import tests still pass.

## P5. Bricks Template Soft Flags

Status: `OPEN`

Tasks:

- Detect `bricks_template` post/CPT payloads.
- Compare current and incoming template condition/scope settings.
- Detect obvious preview post/term ID mismatches.
- Preserve local condition/scope values when incoming IDs cannot be mapped.
- Add soft notes instead of hard blockers for unmapped Bricks-specific references.

Exit criteria:

- Bricks template merges surface likely frontend rendering mismatches.
- Merges do not silently replace local condition IDs with source-site IDs.
- The first version does not attempt selective Bricks element-level merging.

## P6. Test Plan

Status: `OPEN`

PHPUnit coverage:

- preview blocks invalid JSON
- preview blocks current/incoming kind mismatch
- preview blocks current/incoming subtype mismatch
- preview preserves local post ID
- preview preserves local UID by default
- preview can use incoming UID when selected and confirmed later
- preview preserves local slug by default
- preview can use incoming slug when selected and no slug collision exists
- preview blocks slug collision when incoming slug is selected
- title policy toggles between current and incoming title
- meta merge preserves missing current keys
- protected meta preservation is reported as a soft note
- save blocks stale preview hash
- save blocks missing confirmation
- save-and-partial-import reuses the existing partial import path
- Bricks condition/scope mismatches produce soft notes

UI smoke coverage:

- modal opens from an existing editor row
- preview button renders blockers and notes
- changing toolbar choices resets confirmation
- save buttons remain disabled until confirmed
- successful save updates the editor textarea with proposed JSON

## Deferred Rich Merge Work

Defer these until the proposal/diff UI is ready to provide per-field decisions:

- selective field-level accept/reject controls
- deleting local meta keys from absent incoming keys
- destructive full-replace merge mode
- relationship remapping beyond known DBVC UID/slug matches
- automatic Bricks condition resolution
- selective Bricks element-level merge
- media hydration or bundle import from pasted JSON
- multi-entity pasted merge batches

