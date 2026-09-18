# R3 — Registry-Backed Brand Control Center

**Reuse map before designing any R3-B / R3-C / R3-D / R4 / R5.x slice:** read
[`knowledge/EXISTING-SUPPORT-INVENTORY.md`](../knowledge/EXISTING-SUPPORT-INVENTORY.md).
Every ACF family + every cross-cutting piece of infrastructure (descriptor
system, mutation pipeline, REST conventions, Bricks exclusion, kill-switch
pattern, media-frame factory, drawer UI conventions, asset-URL pattern) is
inventoried there with file:line references and per-slice adoption checklists.
Do not rederive.

## Production outcome

R3 establishes a narrow, provider-aware registry and ships a minimal Brand Control Center that lists approved global controls and opens them through the existing Visual Editor descriptor and panel system.

The release must preserve current Shared Globals behavior and should initially rely on already-proven option-owned contracts. It is not the full expanded client experience and does not add new ACF mutation families.

Current implementation baseline: `SharedGlobalFieldsController` manually creates off-render descriptors only for configured option-owned `relationship`/`post_object` fields. It is a compatibility provider precedent, not a generic registry or descriptor factory.

## Prerequisite and sequencing

R0 must be complete. Under the default product sequence, R1 and R2 should already be production-ready. R3 does not depend on Media Manager internals and must not refactor or expand that module.

## User problem

Global controls are currently discovered through a narrow relationship/post-object allowlist and are tied to a specific Shared Globals interface. DBVC needs a safe, standardized way to expose approved controls without equating “global” with arbitrary `wp_options` access.

## Primary personas

- Agency administrator
- Senior content editor
- Site administrator
- VerticalFramework integration developer

## Existing surfaces extended

- Shared Globals settings and popover
- Reserved toolbar overflow or the existing Shared Globals entry point
- Main editor/inspector panel
- Existing descriptor hydration
- Existing shared acknowledgement and journal

The actual entry point should be selected after R0. Avoid adding a new permanent toolbar button when an existing entry can evolve safely.

## In scope

### Registry foundation

- Define the smallest normalized registry contract required by R3–R6.
- Support one or more providers using existing DBVC extension conventions.
- Validate provider and control IDs.
- Normalize categories, source metadata, owner metadata, field family, scope, and status.
- Filter controls by current-user visibility and provider availability.
- Keep registry results separate from authoritative descriptors.

### Shared Globals compatibility

- Adapt current Shared Globals allowlisted option fields into registry records.
- Preserve current settings and existing relationship/post-object behavior.
- Avoid a mandatory migration if runtime adaptation is sufficient.
- Maintain existing reload, acknowledgement, mutation, journal, and cache behavior.

### Minimal Brand Control Center

- List registered controls even when they are not rendered on the current page.
- Show a minimal safe summary: label, category/group, owner/source, field family, and current status.
- Open editable controls in the existing main panel through fresh server descriptor resolution.
- Clearly represent inspect-only, unavailable, and unsupported controls.
- Use lazy descriptor hydration.

### Diagnostics

Add development/admin-observable diagnostics using current logging conventions for:

- duplicate control IDs;
- invalid provider output;
- missing ACF field definitions;
- unresolved owners;
- unsupported field families;
- provider exceptions or version mismatch.

Do not expose sensitive values in logs.

## Out of scope

- Search-heavy or fully designed expanded center UI
- All ACF option-field families
- Site Manager drawer
- Site Assurance or changes to the already-shipped Media Manager/broader Media Health scope
- Pinned controls or named workspaces
- Usage indexing or site-wide impact counts
- Preview mode
- Bulk saving, staging, or undo
- Design-token or Bricks setting writes
- New custom database tables

## Conceptual request flow

```text
Open Brand Control Center
    ↓
Request normalized registry list
    ↓
Server validates providers and filters visibility
    ↓
Render lightweight control records
    ↓
User selects a control
    ↓
Request fresh descriptor by opaque control reference
    ↓
Existing descriptor/resolver determines inspect/edit status
    ↓
Open existing main panel
    ↓
Existing save contract, acknowledgement, journal, and reload behavior
```

The opaque control reference must not contain enough client-authoritative information to target arbitrary storage.

## Implementation slices

### R3-A — Contract and validation

- Add registry/provider contract using existing code organization.
- Add normalized validation and duplicate handling.
- Add unit tests for valid, invalid, duplicate, absent, and permission-filtered providers.
- Do not render a new UI yet.

**Gate:** registry output is deterministic, filtered, and cannot introduce write authority.

**Checkpoint (2026-08-23) — implemented, foundation-only:**

- `addons/visual-editor/src/Registry/ControlProvider.php` (new): interface with `getProviderId()` + `getControls()`. Providers may return either arrays or pre-built `ControlRecord` instances; the registry coerces both.
- `addons/visual-editor/src/Registry/ControlRecord.php` (new): value object with id, providerId, label, category, group, ownerType, ownerSubtype, fieldFamily, status, opaque `source` bag (internal only), sanitized `meta`, and an optional `visibleTo` closure. `fromArray()` normalizes to safe whitelists (owner→{option,post,term,user}, fieldFamily→{text,image,gallery,relationship,post_object,other}, status→{available,inspect_only,unsupported,unavailable}) and returns null for malformed input. `toListItem()` is the safe list projection — never includes the internal `source` bag.
- `addons/visual-editor/src/Registry/ControlRegistry.php` (new): collects providers, validates on ingest, rejects duplicates (provider id, and record local id within a provider), applies per-user visibility at list time, and returns records sorted deterministically (providerId asc, then id asc). `getVisibleRecord($publicId)` is the internal bridge R3-C will use to hand a provider back its own opaque hint at open time. Rejections fire `dbvc_visual_editor_control_registry_invalid` for developer observability.
- No `Addon::register` wiring yet — the registry has no provider to serve. R3-B adds the Shared Globals compatibility provider that becomes the first real registration, and only then does bootstrap wire everything.
- Verified (`tests/phpunit/VisualEditorControlRegistryTest.php`, 11 tests / 44 assertions): register + list a single provider; duplicate provider id rejected + observed; provider with invalid id rejected; duplicate local id within same provider rejected + observed; same local id across two providers coexists under distinct `publicId`s; malformed records skipped + observed; per-user visibility closure filters at list time (subscriber vs administrator); category/status filters prune before visibility; unknown owner/family/status values default to safe choices; empty registry lists nothing; `getVisibleRecord` resolves + fails closed on unknown / malformed / visibility-blocked ids.
- Full PHP suite 810 tests, six inherited failures + one pre-existing `ProposalDiffContractTest` failure surfaced by the dirty working tree's drift in `src/admin-app/index.js` (not R3-A caused, not fixed here); agent docs 54 / 432 / 0 (new `do_action` extension point `dbvc_visual_editor_control_registry_invalid` mapped). No REST route, no mutation path, no UI — R3-B/C/D still to come.

### R3-BX — Manual Approved Field Selection (curation tool, temporary)

Parallel phase, not part of the release cadence. Ships a kill-switch-gated
admin page (**Settings → Visual Editor → BCC Curation**) that enumerates
every options-page-owned ACF field on the site, records human include /
ignore / defer decisions in a dedicated option, and exports the include set
to `addons/visual-editor/curation/vertical-approved-controls.json` (+
companion `.md` review sheet). The JSON is shaped to seed a future
`VerticalControlProvider::getControls()` verbatim and reports which R5
family slice unlocks each entry.

Never mutates content. Reads ACF metadata + live option values only, writes
to its own `dbvc_visual_editor_curation_decisions` option, and turns off
without data loss via `dbvc_visual_editor_curation_tool_enabled`.

**Checkpoint (2026-08-24) — implemented, kill-switch gated:**

- `addons/visual-editor/src/Curation/FieldCandidateProvider.php` (new): walks
  `acf_get_field_groups()` + `acf_get_fields()`, filters to `options_page` /
  `options_page_key` location rules only, descends into `type=group`
  subfields (tabs/messages/accordions skipped as pseudo-fields), emits
  repeater/flexible/clone containers as candidate rows but does NOT descend
  into their subfields. Case-preserving field-name sanitizer keeps Vertical's
  `colorPrimary`-style camelCase intact. Constructor callables give tests a
  seam to hand in fixture arrays with no ACF dependency.
- `addons/visual-editor/src/Curation/FieldCurationRecommender.php` (new):
  keyword-driven recommender (`include` / `ignore` / `defer` / `review`)
  with reasoning + suggested category, plus `deriveUnlocksAt($field_type)`
  → the R5 slice string used by the exporter's `unlocks_summary`. Operational
  options pages (`integrations-settings`, `admin-settings`) default all fields
  to `ignore`; deferred families are repeater/flexible/clone/google_map/
  oembed/file.
- `addons/visual-editor/src/Curation/CurationStore.php` (new): persists to
  a single option (`dbvc_visual_editor_curation_decisions`), validates
  decision + priority against whitelists, sanitizes category/group/notes
  (notes capped at 500 chars), stamps `decided_at` / `decided_by` on
  non-empty writes, prunes empty decisions from the map. `setDecisionsBulk`
  applies one partial decision to many ids in one option write.
- `addons/visual-editor/src/Curation/CurationExporter.php` (new): writes JSON
  + companion Markdown review sheet to `addons/visual-editor/curation/`.
  Only `include` records land in the export. Payload envelope carries
  `schema` (`dbvc.ve.curation.v1`), `exported_at`, `source_site`, `counts`,
  `unlocks_summary`, and `records[]` with `unlocks_at` on each row so the
  R5 sequencing payoff is visible without opening the site.
- `addons/visual-editor/src/Admin/CurationPage.php` (new): the temporary
  admin submenu under `dbvc-export`. Renders a filterable/sortable table
  with checkboxes + a bulk-action bar; every filter (options page / field
  type / group / decision / recommendation / category / label search) is
  URL-persisted. Three admin-ajax handlers (save one decision, bulk save,
  export) all require `manage_options` + nonce. Uses `get_field($key,
  'option')` for the Current Value column with a small display formatter.
- `addons/visual-editor/assets/js/curation.js` +
  `addons/visual-editor/assets/css/curation.css` (new): vanilla JS with
  event delegation for inline saves + bulk apply + export; debounced
  textarea saves. Scoped `.dbvc-ve-curation` CSS with color-coded row
  states (`is-include` / `is-ignore` / `is-defer`) that are never color-alone
  (each state carries a text label).
- `addons/visual-editor/bootstrap.php`: new `OPTION_CURATION_TOOL_ENABLED`
  constant, `is_curation_tool_enabled()` helper (independent of the master
  Visual Editor switch — curation is admin tooling, not runtime), settings
  group + field-meta entry, `register_admin_curation_page()` static
  bootstrap. `SETTINGS_VERSION` bumped 4 → 5 to trip `ensure_defaults()`.
- Wiring: the curation page is always instantiated during bootstrap so the
  three AJAX handlers exist while the option is on; the admin submenu
  itself is added only when `is_curation_tool_enabled()` is true, so
  flipping the option off hides the entry point on the next request.

Verified (`tests/phpunit/VisualEditorCuration{CandidateProvider,Recommender,Store,Exporter}Test.php`,
21 tests / 76 assertions):

- Only options-page groups produce candidates; a post-owned group in the
  same fixture produces zero.
- Group subfields walked recursively; tab labels flow into
  `ancestor_labels`; camelCase names preserved.
- Repeater/flexible/clone emitted with `is_repeater_family=true`; their
  subfields are NOT enumerated.
- Message/accordion pseudo-fields skipped.
- Records sorted deterministically by options-page then field-name path.
- Recommender: deferred families → `defer`; operational pages → `ignore`;
  ignore-keyword wins when both include and ignore match; brand keyword
  → `include` + `Brand` category; unmatched → `review`;
  `deriveUnlocksAt` maps every documented family to the right R5 slice.
- Store: malformed ids rejected; invalid decision values dropped to empty;
  empty decisions prune the id; bulk write; summarize counts;
  notes truncated at 500 chars.
- Exporter: include-only emission; `unlocks_summary` aggregation;
  destination directory auto-created; empty-include scaffold still valid.

Full PHP suite: **831 tests** (810 baseline + 21 new curation), same 6
inherited failures + 1 pre-existing dirty-tree `ProposalDiffContractTest`
failure (not R3-BX caused, not fixed here). One existing test updated —
`VisualEditorMediaManagerR1ATest::test_media_manager_setting_is_default_off_and_requires_visual_editor`
now asserts `SETTINGS_VERSION === '5'` after the 4→5 bump (a legitimate
version bump because a new option participates in `ensure_defaults`).

Agent docs: **54 curated / 436 discovered / 0 unmapped**. Three
extension-point discovery-id hashes rotated (line-shift after the new
option in bootstrap.php: `excluded_post_types`, `excluded_taxonomies`,
`shared_global_field_names`). Four new discovery ids mapped: the new
admin submenu + the three curation AJAX handlers.

R3-BX is a **parallel utility**, not a release gate. R3-B (Shared Globals
compat provider) can proceed against the same registry independently; R3-C
consumes the curation JSON as its UI fixture once curation completes.

**Post-landing polish (2026-08-27) — same slice, admin-only, no test changes:**

- **Fixed a broken asset URL in `CurationPage::enqueueAssets`.** The
  original code computed the plugin URL via
  `plugins_url('addons/visual-editor', dirname(__DIR__, 3) . '/db-version-control.php')`.
  From `src/Admin/CurationPage.php`, `dirname(__DIR__, 3)` is
  `.../addons`, not `.../db-version-control-main` — the plugin root is
  4 levels up (`Admin → src → visual-editor → addons →
  db-version-control-main`). WP's `plugins_url()` then computed the plugin
  URL as `.../plugins/db-version-control-main/addons`, and appending the
  path `addons/visual-editor` produced a doubled `addons/addons/...` URL
  that 404'd. Fixed by anchoring on the addon's own real
  `bootstrap.php` at `dirname(__DIR__, 2) . '/bootstrap.php'` — no more
  depth-guessing and no dependency on the DBVC plugin main-file name.
- **Switched CSS + JS asset versioning to `filemtime()`.** Same pattern
  the existing `AssetLoader` uses. Previously hard-coded `'0.1.0'` meant
  the browser cached the assets forever between edits; filemtime auto-
  busts on every save. Falls back to `'0.1.0'` if the file is
  unreadable so a stale link is still emitted rather than none.
- **Fixed the column-width overflow.** The table now uses
  `table-layout: fixed` with an explicit `<colgroup>` sizing all 12
  columns, wrapped in a horizontal-scroll container (`.dbvc-ve-curation__table-wrap`)
  so narrow admin windows scroll the table rather than pushing the WP
  admin content sideways. Long values in Label / Field name / Current
  value / VF context truncate with ellipsis and show the full value on
  hover via `title="..."`. Decision radios moved from `<br>`-separated
  layout to a flex-column `.dbvc-ve-curation__decision-radios` so row
  heights stay stable.
- **Hoisted filters entirely into client-side JS — no page reload.**
  The server no longer pre-filters the row set from URL params
  (`applyFilters()` in PHP renamed to `prepareRows()`; every candidate
  is now rendered into the DOM with filterable `data-*` attributes:
  `data-options-page`, `data-field-type`, `data-group-key`,
  `data-decision`, `data-recommendation`, `data-category`, `data-search`).
  `curation.js` grew a filter engine that reads the form state, iterates
  rows, and sets the `hidden` attribute per match. Runs instantly on
  every select change, debounced 180ms on label search, re-runs on form
  submit (which is `preventDefault`'d — Enter still filters, no reload).
  A live "Showing X of N candidates" chip renders above the table
  (`.dbvc-ve-curation__filters-status`, `aria-live="polite"`). Reset
  filters is now a `<button type="button">` that clears every filter
  control client-side. When a row's decision or category changes via
  inline save, the JS updates that row's `data-*` attributes and re-
  applies the filter, so a row you just marked Include vanishes
  immediately if a `decision=ignore` filter is active.

Verification for the polish pass:

- `--filter "VisualEditorCuration"` → still **21 tests / 76 assertions
  OK** (the tests never exercised URL-param filtering, so dropping the
  server-side filter was safe by construction).
- Curl against the site confirmed: correct URL 200s, doubled URL 404s
  (proves the fix hit the exact bug pattern).
- No new files, no new tests, no changes outside the R3-BX namespace.

**Follow-up (2026-08-27) — skip ACF `group` containers from the candidate list:**

- **Product change:** `FieldCandidateProvider::walkFields` no longer emits
  candidate rows for `type === 'group'` fields. Groups are structural
  containers — they namespace subfields but have no value, no editor, no
  descriptor, and no R5 slice that can serve them. The walker still
  recurses into a group's `sub_fields`, so every leaf still appears as a
  normal candidate row carrying the group's ancestor labels (nothing is
  lost, only the container row is removed).
- **Recommender cleanup:** `FieldCurationRecommender::FAMILY_UNLOCK_MAP`
  dropped the `'group' => 'R5.2'` entry (which was semantically wrong —
  R5.2 handles scalar/media families with editors, not containers). The
  entry is now dead code with the provider change; the removal makes the
  intent explicit and `deriveUnlocksAt('group')` falls through to the
  safe `later` default.
- **New coverage:** `test_group_containers_are_not_emitted_but_their_leaves_are_walked_with_ancestor_labels`
  (replaces the earlier `test_group_subfields_are_walked_and_ancestor_labels_captured_from_tabs_and_groups`)
  proves groups do not appear and leaves still do, with ancestor labels
  intact from the nearest preceding tab. Added
  `test_a_group_with_no_leaves_contributes_zero_candidates` to prove a
  three-deep group tree with no leaves contributes zero rows (as it
  should — nothing to edit at any level). Recommender test gained an
  explicit `deriveUnlocksAt('group') === 'later'` assertion documenting
  the intentional omission.
- **Curation state migration:** if the operator marked any group rows
  Include/Ignore/Defer before this change landed, those decisions stay
  in `dbvc_visual_editor_curation_decisions` — the change hides them
  from future renders and from the JSON export (the export walks
  candidates × decisions, so a decision keyed to an id no longer emitted
  as a candidate is silently dropped). No cleanup migration needed;
  decisions become inert.
- **Validation:** `--filter "VisualEditorCuration"` → **22 tests / 78
  assertions OK** (was 21/76 — added the two new candidate cases +
  the `group` unlocks_at assertion in the recommender test). Full PHP
  suite unchanged in identity (six inherited + one pre-existing
  `ProposalDiffContractTest`). Agent docs unchanged (no new REST /
  option / extension-point surface).

### R3-B — Existing Shared Globals compatibility provider

- Convert current configured fields into registry records.
- Preserve current field identity and option owner semantics.
- Verify current relationship/post-object controls open and save without regression.
- Keep old Shared Globals path functioning.

**Gate:** no behavior regression when registry-backed discovery is disabled or unavailable.

**Checkpoint (2026-08-28) — implemented, kill-switch gated:**

- New provider `Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider`
  implements the R3-A `ControlProvider` interface. Constructor takes
  `CapabilityManager` + two callable seams (configured-names resolver and
  field-object resolver) so tests hand in fixtures without ACF. The
  provider walks each configured Shared Globals field name, skips
  anything that is not `relationship` or `post_object`, and returns
  discovery-only `ControlRecord` arrays (`category=globals`,
  `ownerType=option`, `ownerSubtype=acf_options`, opaque
  `source={field_name, field_key}` hint for R3-C's descriptor factory,
  `meta.badge = "Shared Global"`). The record's `visibleTo` closure
  reproduces `SharedGlobalFieldsController::canManageSharedGlobalOptions`
  exactly — same option-owned probe descriptor, same `canEditDescriptor`
  call — so per-user visibility matches the existing popover.
- New `OPTION_CONTROL_CENTER_ENABLED = 'dbvc_visual_editor_control_center_enabled'`
  setting (default `'0'`), `is_control_center_enabled()` helper (requires
  BOTH the master Visual Editor switch and this switch), and a new
  `control_center` group in the Visual Editor settings admin. Mirrors the
  media-manager kill-switch pattern.
- `Bootstrap\Addon::__construct` instantiates `ControlRegistry`
  unconditionally (empty registry is safe — matches how `EditableRegistry`
  is wired). `Bootstrap\Addon::register` registers
  `SharedGlobalsControlProvider` under the kill-switch gate only. New
  `getControlRegistry()` getter exposes the same instance so R3-C REST
  controllers can bind to it. `SharedGlobalFieldsController` stays
  intact — the existing Shared Globals popover keeps working exactly as
  it does today; R3-B is parallel discovery.
- No new REST route, no new write authority, no UI. R3-C will add the
  minimal drawer + open route; R3-D will harden.
- `SETTINGS_VERSION` bumped 5 → 6; `VisualEditorMediaManagerR1ATest`
  assertion updated in place (same pattern R3-BX used).
- **Validation:**
  `vendor/bin/phpunit --filter "VisualEditorSharedGlobalsControlProvider"`
  → **5 tests / 28 assertions OK**. Registry subset unchanged
  (`VisualEditorControlRegistry` 11/44). Curation unchanged (29/96).
  Media Manager unchanged (115/1962). Full PHP suite **844 tests, 7
  failures** (was 839/7 pre-R3-B — identical 6 inherited failures + the
  pre-existing dirty-tree `ProposalDiffContractTest`). Agent docs
  **54 / 437 / 0 unmapped** after refresh; three extension-point hashes
  rotated by bootstrap.php line shifts and were re-mapped in
  `docs/agents/manifest.json`.

### R3-C — Minimal center UI

**Split (2026-08-28):** R3-C is deliberately delivered as two sub-slices so each fits a bounded session and so the backend REST contract can be reviewed independently of the drawer UI translation.

- Add the smallest production interface that lists registered controls.
- Reuse existing panel, loading, status, and event patterns.
- Avoid final expanded visual design; R4 will provide the richer UI.
- Add loading, empty, error, inspect-only, unsupported, and unavailable states.

**Gate:** an authorized user can discover and open a registered control that is absent from the current page.

#### R3-C-1 — Backend + descriptor factory extraction

**Checkpoint (2026-08-28) — implemented, kill-switch gated:**

- Extracted `SharedGlobalFieldsController::buildDescriptor` (plus every private helper that participates in the descriptor build) into a stateless `Registry\Providers\SharedGlobalsDescriptorFactory`. The controller now delegates — the popover route's public response shape is unchanged (a focused extraction-sanity test asserts the produced descriptor's key fields for a fixture ACF field, and the descriptor's associative bags are constructed with keys in the same order the pre-extraction code emitted so JSON encoding is byte-identical).
- `ControlProvider` interface widened with `buildDescriptor(ControlRecord $record, string $sessionId, array $pageContext): ?EditableDescriptor`. Rejected alternative (parallel factory registration surface) captured in the resume file's decision line. `SharedGlobalsControlProvider::buildDescriptor` re-resolves the ACF field via the constructor's existing `$fieldObjectResolver` seam, re-validates the type is still `relationship`/`post_object`, and delegates to the factory. Anonymous providers in `VisualEditorControlRegistryTest` gained a trivial `return null;` default — one line per anon class.
- New `ControlRegistry::buildDescriptorForRecord($record, $sessionId, $pageContext)` looks up the record's provider in the private map and delegates — keeps `$providers` fully encapsulated. Fails closed when the provider is gone.
- Two new session-scoped REST controllers wired into `Rest\Routes::registerRoutes` under the `is_control_center_enabled()` gate (D-063 kill switch, both parts default off):
  - `ControlCenterListController` — `GET /dbvc/v1/visual-editor/session/{session_id}/control-center/controls?category=&status=`. Returns `{ok:true, viewModelVersion:1, query, items}` — the registry's safe projection, no `source` bag.
  - `ControlCenterOpenController` — `POST /dbvc/v1/visual-editor/session/{session_id}/control-center/open` body `{publicId}`. Resolves via `registry.getVisibleRecord()` (null → 404) → `registry.buildDescriptorForRecord()` (null → 404) → 403 when the descriptor's `source.reference_post_types` is empty (same policy the popover surfaces as a warning) → `capabilities.canEditDescriptor()` (false → 403) → `session_registry.addDescriptorToSession()` (false → 404) → returns `{ok, publicId, descriptors, descriptorHydrations}` matching the shape the frontend panel already consumes.
- `Rest\Routes::__construct` gains a `ControlRegistry` param; `Bootstrap\Addon::__construct` passes `$this->control_registry`. No other constructor changes across the codebase.
- No frontend JS/CSS in this slice — R3-C-2 lands the drawer. Kill switch stays operationally inert until the drawer arrives.
- **Validation:** `vendor/bin/phpunit --filter "VisualEditorControlCenterRoutes"` → **10 tests / 36 assertions OK**. R3-A subset unchanged (11/44), R3-B unchanged (5/28), Curation unchanged (29/96), Media unchanged (115/1962). Full PHP suite **854 tests, 7 failures** (was 844/7 pre-R3-C-1 — identical 6 inherited + 1 pre-existing dirty-tree `ProposalDiffContractTest`). Agent docs **54 curated / 439 discovered / 0 unmapped** (2 new REST routes discovered + mapped; 1 REST hash rotated on `SharedGlobalFieldsController`; 1 extension-point hash rotated on `ControlRegistry`).

**Gate (met):** the two new REST routes work end-to-end against fixture data; the existing Shared Globals popover route is unaffected; the interface extension has not broken any R3-A / R3-B tests.

#### R3-C-2 — Drawer UI translation

**Checkpoint (2026-08-29) — implemented, kill-switch gated:**

- New `assets/js/brand-control-center-app.js` (~900 lines) — IIFE state machine mirroring `media-manager-app.js`'s shape: `bootstrap()`/`config()`/`strings()`/`text()`/`templateText()` helpers, `state` store keyed on `requestSequence`, `ensureRoot()` lazy DOM build, event delegation on `[data-dbvc-ve-control-center-action]`, `mount()` at the bottom wiring document listeners and exposing `window.DBVCVisualEditorBrandControlCenter = {open, close, toggle, list, isOpen, getState}`. Covers every state in the accepted mockup's §4 matrix: loading-initial / list / opening (per row, `aria-busy="true"`) / opened (drawer + panel coexist via `is-focused-source` modifier) / open-error (inline row-notice with Dismiss, assertive for 409) / empty / empty-filtered / error (with Retry). Client-side filtering across tab / status / priority / field-family / search — no round-trips (pinned decision #3). Row-focus continuity across rerenders (`activeElement` snapshot pattern). Single polite `role="status"` live region (Component Map §6). `@media (prefers-reduced-motion: reduce)` suppresses spinner + slide transitions. Rows carry ONLY `data-public-id` — no `data-owner-id` / `data-field-key` / `data-selector` / `data-path` / `data-descriptor` / `data-token` (schematic §6 invariant 2, jsdom-asserted).
- New `assets/css/control-center.css` — direct translation of the mockup's production-scoped `.dbvc-ve-control-center*` selectors. Drops all `.dbvc-ve-control-center-mockup__*` scaffolding. Every color/font/spacing draws from existing `--dbvc-ve-*` tokens in `overlay.css`. Component-local sizing (`--dbvc-ve-control-center-width: 480px`, admin-bar / toolbar-strip offsets, header/section padding, focus ring, shadow, chip padding) lives on `.dbvc-ve-control-center` itself.
- One net-new `:root` token in `overlay.css`: `--dbvc-ve-z-drawer: 120015` (between panel 120010 and toolbar 120020). Every other overlay.css declaration untouched.
- `AssetLoader::enqueue` gained a new branch mirroring the media-manager branch: `isControlCenterEnabled()` gate → enqueue `control-center.css` + `brand-control-center-app.js` (both dependencies on `dbvc-visual-editor-overlay`, `filemtime()`-versioned). New `controlCenter: { enabled, restBase }` block in the localized `DBVCVisualEditorBootstrap` payload; ~40 new `strings.controlCenter*` keys covering drawer copy (title, close, tabs, filter labels, chip labels, action labels, state-panel titles/bodies, live-region announcement templates, category labels). Bricks Builder exclusion inherits — the whole enqueue path is already skipped inside Bricks by `shouldLoadFrontendAssets()`.
- `overlay-app.js` gained four surgical additions:
  1. `isControlCenterEnabled()` + `dispatchControlCenterEvent(name, detail)` helpers next to their media-manager twins.
  2. New toolbar entry inside the dock right after `shared-globals` — `createToolbarButtonMarkup('control-center', 'sliders', controlCenterLabel, 'dbvc-ve-toolbar__button--dock', false, {controls, expanded, hasPopup:'dialog'})` — gated by `isControlCenterEnabled()` (pinned decision #2). Existing Shared Globals (Layers) popover button is unchanged (D-063).
  3. `handleToolbarClick` branch for `action === 'control-center'` dispatches `dbvc:visual-editor:control-center:toggle` and closes the media manager if it was open (mutual exclusion mirrors the existing media-manager branch).
  4. `bindControlCenterBridge()` (called from `mount()`) attaches ONE document listener for `dbvc:visual-editor:absorb-descriptor`. On the drawer's successful open, it reuses the existing internal `mergeSharedGlobalInventory({descriptors, descriptorHydrations, fields:[]})` + `openToolbarDescriptorPanel(token)` helpers — the same path the Shared Globals popover already uses (pinned decision #1). Idempotent via `state.controlCenterBridgeBound`. Failure modes (existing marker on page) short-circuit to `locateFieldIndexMarker(token, true)` for parity with the popover.
- jsdom coverage `tests/visual-editor-brand-control-center-state.test.cjs` (14 tests) covers: initial load renders row per item, forbidden-attr security invariant, tab click filters client-side (no fetch), chip toggle applies+clears per-axis filter, row Open POSTs correct payload + marks opening, successful open dispatches `absorb-descriptor` with the R3-C-1 payload, 404 renders inline row-notice with Dismiss, 409 renders assertive alert notice, empty list renders empty-registry panel-state, empty-filtered renders Clear-filters panel-state, Escape closes + restores focus, toggle-event lifecycle, single polite live region invariant, error panel-state + Retry recovers.
- Kill switch stays default off; when off, `AssetLoader` enqueues nothing, `overlay-app.js`'s `isControlCenterEnabled()` returns false → toolbar entry hidden, bridge listener not bound, drawer JS never boots. When on, drawer opens against the R3-C-1 REST routes.
- **Validation:** `node --test tests/visual-editor-brand-control-center-state.test.cjs` → **14 pass**. `node --test tests/visual-editor-media-manager-state.test.cjs` → **42 pass** (baseline preserved — the R3-B resume-file claim of 43 was stale; corrected). Full PHPUnit unchanged at **854 tests / 7 pre-existing failures**. Agent docs `54 / 439 / 0 unmapped` (one filter hash rotated on `AssetLoader::enqueue` due to the new `controlCenter` block adding lines above the existing `apply_filters` call; re-mapped).

**Gate (met):** an authorized user with both switches on opens the drawer from the toolbar's `sliders` entry, sees the R3-B-registered Shared Globals controls, filters them client-side, opens one into the existing main editor panel, saves via the existing pipeline, and the drawer stays open.

### R3-D — Production hardening

- Verify capability filtering and nonce handling.
- Verify Bricks Builder exclusion.
- Verify R3 adds no new heavy editor/media enqueue and document the existing active-mode eager asset baseline.
- Add browser coverage and release notes.
- Document rollback and compatibility behavior.

**Checkpoint (2026-08-29) — implemented; R3 core is ship-ready:**

- New `tests/phpunit/VisualEditorControlCenterHardeningTest.php` (7 tests / 18 assertions) locks down the two invariants R3-D was created to prove:
  1. **Kill-switch registration guarantee** — both REST routes register only when BOTH the master Visual Editor switch and `OPTION_CONTROL_CENTER_ENABLED` are on; they are absent when either half is off; a mid-session flip from on→off makes them unreachable on the very next REST-init dispatch (the test resets `$wp_rest_server` between passes to force a clean re-init). Complements the behavior-level guards from R3-C-1's `VisualEditorControlCenterRoutesTest` (10 tests) by adding the routes-registration axis those tests didn't cover.
  2. **Bricks Builder isolation** — with `$_GET['bricks']='1'`, `AssetLoader::enqueue` short-circuits via `FrontendRuntimeGuard::shouldRunFrontend()` before reaching the drawer branch: `wp_style_is('dbvc-visual-editor-control-center')` and `wp_script_is('dbvc-visual-editor-control-center')` both return false. Also asserts the media-manager assets stay off, proving both feature branches sit behind the same guard.
- Also asserts the "feature switch off → drawer assets do not enqueue" invariant with the edit-mode cookie forced on (isolates the feature-switch gate from the mode-active gate).
- New `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/BRAND-CONTROL-CENTER-RELEASE-NOTES-AND-ROLLBACK.md` — sibling to `MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md`. Covers *What shipped* (R3-A / R3-B / R3-C-1 / R3-C-2 / R3-D), *Feature gates & isolation (verified)* — every gate cross-referenced to the exact test that proves it, *Side effects & boundaries* (writes: none from R3 itself; storage: none beyond the one setting; enqueue footprint), *Residual / deferred* (real-browser QA of drawer + panel coexistence, marked as a residual gate along the Media Manager D-049 shape), *Rollback runbook* (6 numbered steps + pre-flip verification recipe), *Verification snapshot*.
- No new REST routes; no descriptor changes; no drawer state additions; no new persistent option.
- **Residual gate (browser QA) — CLOSED 2026-08-29** via `qa/R3-DRAWER-BROWSER-QA-REPORT.md`. All 12 checklist items pass at both supported viewports (1440×900 primary, 1280×720 secondary) against a live 400-row registry (1 Shared Globals + 399 Vertical). Drawer geometry, aria-expanded lifecycle, client-side tab/chip/search filtering (0 fetch calls during filter interactions — confirms pinned decision #3), Escape close + focus restore, security invariant (0 forbidden `data-*` on 400 rows), single polite live region, drawer + panel coexistence (drawer left 0–480, panel right 892–1272 at 1280×720 with 412px gap; drawer left 0–480, panel right 1044–1424 at 1440×900 with 564px gap), `@media (prefers-reduced-motion: reduce)` CSS rule confirmed, popover route response shape unchanged after R3-C-1 extraction (canonical top-level keys + 13-key field shape + `shared_relationship_collection` contract). Real Safari + real AT remain **permanently out of scope** per D-058. Media Manager D-049 shape maintained.
- **Validation:** `vendor/bin/phpunit --filter "VisualEditorControlCenterHardening"` → **7 tests / 18 assertions OK**. Full PHP suite **861 tests, 7 pre-existing failures** (was 854/7 pre-R3-D). R3-A / R3-B / R3-C-1 / R3-BX / Media Manager subsets all unchanged. Drawer + Media Manager jsdom baselines preserved (14/42). Agent docs **54 curated / 439 discovered / 0 unmapped** (no new discoveries — R3-D added no REST or hook surface).

**Gate (met):** the drawer + backend behave as specified when both switches are on; when either switch is off the routes are unreachable and no drawer asset enqueues; a Bricks Builder request never sees the drawer; the Shared Globals popover's response is unchanged; the release notes + rollback runbook cover the two-part kill switch and no-write-authority guarantees end-to-end. **R3 core (R3-A + R3-B + R3-C-1 + R3-C-2 + R3-D) is ship-ready.** Remaining follow-ups (R4 expanded UI, cross-repo VerticalControlProvider) are separate slices with their own gates.

### Post-R3 extension point (2026-08-29)

Landed alongside the VerticalControlProvider cross-repo slice to unblock external providers without touching addon source:

- **`dbvc_visual_editor_control_center_providers`** filter in `Bootstrap\Addon::register()` — fires only when both parts of the two-part kill switch are on (respects D-063). Returned `ControlProvider` instances register on the runtime `ControlRegistry` after the built-in `SharedGlobalsControlProvider`. Non-`ControlProvider` entries are silently skipped. Same discovery-only contract; `buildDescriptor()` may return null (rows surface as `status="unsupported"` and never call the open route); no new mutation authority; `MutationService` still gates every save.
- **`DBVC_Visual_Editor_Addon::get_curation_export_path()`** static helper + **`dbvc_visual_editor_curation_export_path`** filter — deterministic absolute path to the R3-BX curation JSON (`addons/visual-editor/curation/vertical-approved-controls.json`), computed from bootstrap.php's own directory so it survives a plugin-folder rename. External providers use this instead of hardcoding a plugin folder name.
- Tests: `VisualEditorControlCenterProvidersFilterTest` (7 tests / 11 assertions) — filter fires only under both-switches-on, external ControlProvider is registered, non-provider entries are silently skipped, kill-switch flip in either half stops the filter from firing, helper returns the committed export by default and honors its filter, helper falls back on non-string filter return.
- Agent docs: 2 new discovered surfaces (`hook.extension_point.dbvc_visual_editor_control_center_providers.0d92c6ae`, `hook.extension_point.dbvc_visual_editor_curation_export_path.03b25a10`) mapped; 3 sibling filter hashes rotated by line shifts from the additions.

## Data and API rules

- Prefer exact ACF field keys over field names.
- Represent the option owner using the same canonical form as current resolvers.
- Do not send arbitrary option IDs, field keys, or nested paths back from the browser as authority.
- List APIs may return a safe summary but should not return full field definitions unnecessarily.
- Full descriptors must be re-created or rehydrated server-side when a control opens.
- Registry caches, if any, must follow existing caching conventions and be invalidated by provider/settings changes.

## VerticalFramework role

R3 must work without VerticalFramework.

After the DBVC registry contract is stable, discovery may justify a small VerticalFramework provider in the same overall release. Treat it as a separate cross-repository slice with separate files, tests, and change notes. Do not directly import DBVC implementation classes across repositories unless an existing integration pattern already does so safely.

A Vertical provider may register a small proven set of existing controls, but R3 does not require cataloging every field in VerticalFramework.

## Security and stale-data requirements

- Registry membership never grants edit permission.
- Capability checks occur when listing and again when hydrating or saving.
- Shared acknowledgement remains mandatory.
- Existing stale-value checks remain unchanged.
- Missing or invalid fields fail closed.
- An unregistered option, field, or owner cannot be supplied manually.
- Sensitive operational settings must not be registered.

## Performance requirements

- Do not hydrate every full descriptor to display the list.
- Avoid per-control repeated ACF field-group or owner queries where a batch or cached lookup already exists.
- Paginate or limit providers if current evidence shows potentially large registries.
- Do not add TinyMCE, Quicktags, or Media Library enqueues solely for the center. Active Visual Editor mode already enqueues editor/media assets; optimizing that baseline is a separate measured task.

## Acceptance criteria

### Registry

- [ ] Providers can register controls through a documented DBVC extension point.
- [ ] Duplicate and malformed registrations fail predictably and are observable.
- [ ] DBVC remains fully functional when no provider is present.
- [ ] Registered controls do not become writable without an existing resolver.
- [ ] Unregistered `wp_options` values cannot be discovered or edited.

### Compatibility

- [ ] Existing Shared Globals settings continue to work.
- [ ] Existing relationship and post-object global editing has no functional regression.
- [ ] Existing acknowledgements, journal entries, cache invalidation, and reload behavior remain intact.
- [ ] Disabling the new center restores the prior surface without data loss.

### UI

- [ ] A registered control can appear when it is not rendered on the current page.
- [ ] Each row exposes source/owner context and a clear status.
- [ ] Opening a row requests a fresh authoritative descriptor.
- [ ] The existing main panel is reused.
- [ ] Loading, empty, error, inspect-only, unsupported, and unavailable states are covered.
- [ ] Keyboard and focus behavior follow current Visual Editor patterns.

### Safety and operations

- [ ] All protected requests enforce nonce and capability checks.
- [ ] No Visual Editor registry or center assets appear in Bricks Builder requests.
- [ ] No new custom table or broad data migration was introduced without proven need.
- [ ] Automated and browser tests pass.
- [ ] Release notes and rollback instructions are complete.

## Rollback

The preferred R3 rollback is feature-level:

1. Disable the registry-backed center through the existing settings/feature mechanism.
2. Retain existing Shared Globals behavior and settings.
3. Remove or disable optional providers without changing stored field values.
4. Revert code without requiring a data migration.

If discovery proves persistence is necessary, document an explicit versioned rollback before implementation.
