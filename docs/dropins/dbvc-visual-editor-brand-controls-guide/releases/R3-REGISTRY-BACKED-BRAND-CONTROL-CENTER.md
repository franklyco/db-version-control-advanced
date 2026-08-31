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

### R3-C — Minimal center UI

- Add the smallest production interface that lists registered controls.
- Reuse existing popover, panel, loading, status, and event patterns.
- Avoid final expanded visual design; R4 will provide the richer UI.
- Add loading, empty, error, inspect-only, unsupported, and unavailable states.

**Gate:** an authorized user can discover and open a registered control that is absent from the current page.

### R3-D — Production hardening

- Verify capability filtering and nonce handling.
- Verify Bricks Builder exclusion.
- Verify R3 adds no new heavy editor/media enqueue and document the existing active-mode eager asset baseline.
- Add browser coverage and release notes.
- Document rollback and compatibility behavior.

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
