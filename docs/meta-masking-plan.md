# Meta Masking UI & API Plan

This plan outlines the implementation steps for masking meta fields inside live proposal reviews. It builds on the reference copy/links in `docs/meta-masking.md` and adds concrete UI + backend work needed before coding.

## UX Flow

1. **Toolbar entry point**
   - A new `Apply masking rules` primary button sits next to the bulk selectors above the All Entities table.
   - Tooltip content comes directly from `docs/meta-masking.md#live-proposal-masking` (help link text “Masking guide”).
   - Clicking opens a drawer/modal titled “Apply masking rules” anchored to the current proposal.
   - The button also triggers a floating utility drawer (details below) so the masking controls live in a consistent tools panel rather than competing with other toolbar controls.

2. **Masking panel**
   - The panel now streams masked entities in paged chunks so large proposals don’t blow memory. A progress indicator (“XX% loaded”) surfaces while pages load.
   - Instead of per-entity rows, reviewers pick a single bulk action (Ignore / Auto-accept & suppress / Override) that applies to every masked field in the current proposal.
   - When `Override` is chosen, override value + optional note inputs appear. Tooltip copy links to `docs/meta-masking.md#override-masked-value`.

3. **Apply flow**
   - Apply runs in chunks (50 fields per request by default) and shows a `% applied` ticker in the Tools panel header. Once done, masking data, entity badges, and duplicate info are refetched automatically.
   - The payload is cached in `sessionStorage`, enabling an “Undo last masking” button that replays the inverse (sets fields back to ignore) if the reviewer needs to revert.
   - Add a persistent “Revert masking decisions” control that replays the masking query in reverse (clearing accept/keep decisions + suppression/override stores for every current mask pattern match) so reviewers can re-open a proposal after rules change.

## REST API Surface

### `GET /dbvc/v1/proposals/<id>/masking`
Returns the current masking candidates.

Response body:
```json
{
  "proposal_id": "abc123",
  "updated_at": "2024-05-14T19:33:00Z",
  "fields": [
    {
      "vf_object_uid": "post_999",
      "entity_type": "post",
      "title": "Landing Page",
      "needs_review": true,
      "meta_path": "meta._secret_array.0.value",
      "label": "Meta › _secret_array › #0 › Value",
      "diff_section": "meta",
      "proposed_value": "***",
      "current_value": null,
      "default_action": "ignore"
    }
  ]
}
```

Server logic:
- Load manifest + snapshots.
- Run `dbvc_mask_parse_list` on `dbvc_mask_meta_keys` + `dbvc_mask_subkeys`.
- For each entity diff path that falls under `meta.*` or term meta, check if the key or dot-path matches the mask patterns.
- Include only entities marked `needs_review` / `diff_state.needs_review` or flagged `media_needs_review`.
- Determine `default_action` through settings (default to `ignore`, future preference keys stored per proposal).
- Server returns chunk metadata (page, per_page, total_pages, has_more) so clients can stream the list without exhausting memory.

### `POST /dbvc/v1/proposals/<id>/masking/apply`

Request body:
```json
{
  "items": [
    {
      "vf_object_uid": "post_999",
      "meta_path": "meta._secret_array.0.value",
      "action": "ignore"
    },
    {
      "vf_object_uid": "post_999",
      "meta_path": "meta._secret_api_key",
      "action": "auto_accept",
      "suppress": true
    },
    {
      "vf_object_uid": "term_888",
      "meta_path": "meta._sensitive_label",
      "action": "override",
      "override_value": "Sanitized text",
      "note": "Matches public snippet"
    }
  ]
}
```

Behavior per action:
- **ignore** → Record a `keep` decision for the path by calling `set_entity_decision(..., 'keep')`.
- **auto_accept** → Record an `accept` decision; also persist a “suppressed masked field” record under a new option `dbvc_masked_field_suppressions[proposal_id][vf_object_uid][meta_path] = true` so future exports skip it automatically.
- **override** → Store the override payload in a new option `dbvc_mask_overrides[proposal_id][vf_object_uid][meta_path] = { value, note, timestamp }`, record an `accept` decision, and ensure the importer swaps this override in place of the bundle value at apply-time.

Response:
```json
{
  "proposal_id": "abc123",
  "applied": {
    "ignore": 3,
    "auto_accept": 5,
    "override": 1
  },
  "entities": [
    {
      "vf_object_uid": "post_999",
      "overall_status": "resolved",
      "decision_summary": { "accepted": 2, "kept": 3 },
      "diff_state": { "needs_review": false }
    }
  ]
}
```

The handler recomputes entity summaries (reusing `summarize_entity_decisions` and `evaluate_entity_diff_state`) so the React app can immediately refresh the All Entities table without a second request.

## Data Model Additions

| Store | Purpose |
|-------|---------|
| `dbvc_masked_field_suppressions` | Tracks which proposals/entities/fields were auto-accepted & suppressed so exporters/importers can stabilise behavior. |
| `dbvc_mask_overrides` | Persists override strings + timestamps for auditing and importer substitution. |
| `dbvc_masking_preferences` (optional) | Remembers per-proposal/per-entity default actions so re-opening the modal shows previous selections. |

Each store follows the same cleanup rules as `dbvc_proposal_decisions` (delete empty proposals when selections clear).

## React Component Notes

- Reuse existing data fetch hook around `get_proposal_entities` to trigger the new `/masking` endpoint when the Apply Masking button modal opens.
- Keep modal state in the same component that renders the All Entities table so the button + table share selection & proposal context.
- When `POST /masking/apply` resolves, dispatch:
  1. Optimistic update to entity table rows (update `diff_state` + `decision_summary`).
  2. Toast success/failure notifications.
  3. Optionally refetch `/masking` data to collapse the modal if no fields remain.

### Tooltip Wiring

All tooltip triggers pull copy from `docs/meta-masking.md`:
- `apply` button tooltip → `#live-proposal-masking`
- Ignore dropdown helper → `#ignore-masked-field`
- Auto-accept helper → `#auto-accept-and-suppress`
- Override helper → `#override-masked-value`

The React components should import the doc URL from a shared constant so only one change is needed if the docs move.

## Outstanding Questions (need approval)

1. **Override storage**
   - **Why this matters:** Overrides contain sanitized values that must be injected during apply, logged for auditing, and eventually cleaned up. Storing these blobs in options (`dbvc_mask_overrides`) keeps retrieval simple but introduces two concerns: options autoload size/performance and lifecycle cleanup once proposals are applied/deleted. Conversely, embedding overrides inside the proposal directory (e.g., `overrides.json` next to `manifest.json`) keeps data co-located with the bundle but complicates REST access and requires extra file I/O whenever the React UI updates a single field.
   - **Decision:** Store overrides in options-backed, proposal-scoped blobs (`dbvc_mask_overrides[proposal_id]`). Cleanup runs after successful applies or when proposals are purged so autoload size stays bounded, and sensitive values avoid hitting disk in plain JSON.

2. **Suppression semantics**
   - **Why this matters:** When a reviewer selects “auto-accept & suppress,” they expect masked fields to disappear from future proposals so sensitive values never reappear. There are two scopes:
     * **Proposal-scoped suppressions** – only the current proposal flags the fields; future exports still rely on the global `dbvc_mask_*` settings, meaning ops must remember to update those separately.
     * **Global mask mutation** – automatically append the field pattern to the site-wide mask configuration so exports immediately respect the reviewer decision.
   - **Decision:** Keep suppressions local to the reviewed proposal (`dbvc_masked_field_suppressions[proposal_id]`). Export-time masking (e.g., `_dbvc_import_hash`, `vf_object_uid`) continues unchanged so manifests retain deterministic meta, while the UI simply hides/auto-resolves those fields for the reviewer. Follow-up tooling can backfill global masks if desired.

3. **Importer hookup**
   - **Why this matters:** Overrides and auto-accept directives only have effect if the importer replaces the masked meta before writing to the database. There are two obvious insertion points:
     * **Inside `DBVC_Sync_Posts::apply_entity` (and term equivalents):** direct modifications to the payload as it’s applied guarantee coverage but require touching core importer logic.
     * **Dedicated filter/action:** expose the sanitized overrides via a filter so integrators can adjust payloads without editing the importer; DBVC itself would add a default filter callback.
   - **Decision:** Extend `DBVC_Sync_Posts::apply_entity` (and the term importer) to look up overrides/suppressions before meta is persisted, swapping values inline. Also introduce a filter (`dbvc_masked_meta_override`) so site owners can inject custom behavior without editing core.

4. **UI placement**
   - **Why this matters:** The All Entities toolbar already hosts filters, selection counts, and column toggles. Adding a new primary button + tooltip risks wrapping or clipping on smaller viewports, especially when the WordPress admin menu is collapsed.
   - **Decision:** Introduce a floating utility drawer anchored to the entity table. A small “Tools” pill (or button) docks at the right edge; opening it reveals Resolver Summary, masking controls, hash sync shortcuts, etc. When new actions become available, the drawer border animates/pulses to signal attention. This keeps the toolbar lean on all breakpoints.

## Tools Panel & Status Badges

- **Tools panel behavior**
  - The “Tools” pill expands a toolbox directly beneath the status badges so masking controls live inline with the entity header instead of covering the table.
  - The panel now focuses on Meta Masking (resolver summary lives above the table): chunked loader with progress, bulk action dropdown (ignore / auto-accept & suppress / override), override inputs, sessionStorage-powered undo button, and apply progress.
  - When masking candidates exist (or other required actions fire), the pill gains a subtle pulse/glow and the panel border highlights (“3 actions pending”) so reviewers know they should reopen it.
  - Panel state is sticky per session; reopening the page remembers whether the reviewer left it open.
  - After editing `src/admin-app/`, run `npm run build` so the panel markup/styles in `build/admin-app.*` reflect the latest code; otherwise WordPress still serves the previous assets and the panel will not appear.

- **Table header status badges**
  - Move the key counts (Needs Review, Unresolved meta, Resolver conflicts, Pending decisions) into pill badges aligned with the All Entities table header.
  - Clicking a badge toggles the corresponding filter, removing extra row controls.
  - Badge colors align with existing badge palette (`dbvc-badge--needs_review`, etc.) for quick recognition.
  - When masking auto-clears meta, badges update in place so reviewers immediately see the reduced counts.

Once these questions are answered, we can proceed with coding the API, UI modal, and importer tie-ins.
