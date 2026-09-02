# Brand Control Center — Release Notes & Rollback (R3-D)

Ship-readiness summary for the Registry-Backed Brand Control Center (R3-A registry foundation → R3-B Shared Globals provider → R3-C-1 backend + REST routes → R3-C-2 drawer UI → R3-D hardening). Companion to the per-phase release doc in this folder.

## What shipped

**R3-A — provider-agnostic control registry (foundation).** Discovery-only `ControlProvider` interface + `ControlRecord` value object with whitelisted normalization and a safe list projection that excludes the opaque per-record `source` bag; `ControlRegistry` collects providers, validates/normalizes/de-dupes records, and re-applies per-user visibility on every list call. Duplicate providers, duplicate local ids, and malformed records are rejected observably via `dbvc_visual_editor_control_registry_invalid`. `getVisibleRecord(publicId)` is the internal bridge R3-C-1's open route uses. No REST, no UI, no `Addon::register` wiring yet.

**R3-B — Shared Globals compatibility provider (headless).** `Registry\Providers\SharedGlobalsControlProvider` adapts the existing configured Shared Globals field list onto the registry as a discovery-only surface: one `ControlRecord` per configured `relationship`/`post_object` field with `category=globals`, `ownerType=option`, `ownerSubtype=acf_options`, an opaque `source={field_name, field_key}` hint, and a `visibleTo` closure that reproduces `SharedGlobalFieldsController::canManageSharedGlobalOptions` verbatim. Two-part kill switch introduced (D-063): `OPTION_CONTROL_CENTER_ENABLED` + master switch, both default off. `Bootstrap\Addon` instantiates `ControlRegistry` unconditionally and exposes `getControlRegistry()` for downstream slices. `SharedGlobalFieldsController` is untouched — parallel discovery, not a replacement.

**R3-C-1 — Brand Control Center backend + descriptor factory extraction.** Extracted `SharedGlobalFieldsController::buildDescriptor` and its private helpers into a stateless `Registry\Providers\SharedGlobalsDescriptorFactory` (byte-identical to the pre-extraction inline emit; associative-array key ordering preserved). Widened the R3-A `ControlProvider` interface with `buildDescriptor(ControlRecord $record, string $sessionId, array $pageContext): ?EditableDescriptor` and implemented it on `SharedGlobalsControlProvider`. New `ControlRegistry::buildDescriptorForRecord()` keeps the private providers map encapsulated. Two new session-scoped REST controllers wired into `Rest\Routes` under the kill switch:
- `ControlCenterListController` — `GET /dbvc/v1/visual-editor/session/{session_id}/control-center/controls` returns the safe list projection.
- `ControlCenterOpenController` — `POST /dbvc/v1/visual-editor/session/{session_id}/control-center/open` resolves a `publicId` → provider `buildDescriptor` → per-descriptor capability recheck → session attach → returns `{descriptors, descriptorHydrations}` in the same shape the existing frontend panel already consumes.

**R3-C-2 — drawer UI translation.** `assets/js/brand-control-center-app.js` (~900 lines) translates the accepted mockup at `docs/ui-mockups/dbvc-visual-editor/r3-brand-control-center/` into production. Mirrors `media-manager-app.js`'s lifecycle. Scoped `assets/css/control-center.css` with a single new `--dbvc-ve-z-drawer: 120015` `:root` token. `AssetLoader` gained a kill-switch-gated enqueue branch + a `controlCenter: {enabled, restBase}` bootstrap block + ~40 drawer i18n strings. `overlay-app.js` gained a `sliders` dock toolbar entry after `shared-globals` (D-063 keeps the popover slot untouched), a `control-center` click branch that dispatches `dbvc:visual-editor:control-center:toggle`, and a `bindControlCenterBridge()` document listener for `dbvc:visual-editor:absorb-descriptor` that reuses the existing internal `mergeSharedGlobalInventory` + `openToolbarDescriptorPanel` helpers — the same helpers the Shared Globals popover already uses, so the panel behaves identically regardless of entry point. Filtering across tab / status / priority / field-family / search is client-side; the wire stays stateless.

**R3-D — production hardening (this doc).** New `VisualEditorControlCenterHardeningTest` locks down: the two-part kill switch's registration guarantee (both REST routes registered only when both switches are on; absent when either is off; mid-session flip makes them unreachable), and the Bricks Builder isolation (drawer stylesheet + script do NOT enqueue inside a Bricks Builder request — verified against `FrontendRuntimeGuard`'s isBuilderRequest via `$_GET['bricks']`).

## Feature gates & isolation (verified)

- **Two flags, default-off.** The Brand Control Center is active only when the Visual Editor master flag **and** the Control Center flag are both on (`is_control_center_enabled()` = `is_enabled()` AND the feature flag). Turning off either closes the entire surface (routes, drawer JS/CSS, and toolbar entry all disappear).
- **Capability + authentication.** Every REST route's `permission_callback` (`canAccess`) requires a logged-in user with the base capability (`edit_others_posts`, filterable). Each open call additionally checks `EditModeState::isRestRequestAuthorized()` (edit-mode cookie present and set) and re-checks `CapabilityManager::canEditDescriptor` on the freshly-minted descriptor before attaching it to the session.
- **Per-user visibility.** Every list call re-applies the record's `visibleTo` closure against the current user — the same closure that fronts `canManageSharedGlobalOptions` for the popover. Subscribers see nothing; administrators see every configured Shared Globals record.
- **Bricks Builder exclusion.** `AssetLoader::enqueue` short-circuits via `EditModeState::shouldLoadFrontendAssets()` → `isActive()` → `runtime_guard->shouldRunFrontend()` before reaching the drawer branch. Verified by `VisualEditorControlCenterHardeningTest::test_drawer_assets_do_not_enqueue_inside_bricks_builder`.
- **No new write authority.** The drawer opens rows into the existing main editor panel via the existing `mergeSharedGlobalInventory` + `openToolbarDescriptorPanel` bridge — same panel, same save path, same `MutationService` pipeline, same journal + audit + cache invalidation as the Shared Globals popover.
- **Parallel popover coexistence (D-063).** `SharedGlobalFieldsController::handle()`'s public response is byte-identical to its pre-R3-C-1 shape. The Shared Globals popover keeps working exactly as it did before R3; users who prefer the popover can continue using it while the drawer opt-in stays off.

## Side effects & boundaries

- **Writes:** none from R3 itself. The drawer opens a control into the existing panel; whatever the user saves in the panel routes through the existing `MutationService` pipeline (validation → sanitize → resolver save → change journal → audit event → cache invalidation).
- **Never:** creates new REST endpoints for writing; adds new write authority; caches descriptors client-side beyond the panel's existing hydration cache; sends any raw target (owner id, field key, selector, path, descriptor, token) into the DOM. Rows carry only `data-public-id` — enforced structurally in `brand-control-center-app.js`'s row builder and asserted by `visual-editor-brand-control-center-state.test.cjs`.
- **Storage:** none. The registry is in-process; provider registrations live for the request. No custom table, no persistent option beyond `OPTION_CONTROL_CENTER_ENABLED` (a single '0'/'1' setting).
- **Frontend enqueue footprint (when both switches on):** one CSS file (~600 lines) + one JS module (~900 lines) + one added toolbar button. All Bricks-Builder-isolated. Zero editor / media / TinyMCE / wp-color-picker enqueue that the Visual Editor overlay didn't already load.

## Residual / deferred

- **~~Real-browser QA~~ — CLOSED 2026-08-29.** Real-browser QA of the drawer + main-editor-panel coexistence at 1440×900 and 1280×720 landed via `qa/R3-DRAWER-BROWSER-QA-REPORT.md`. All 12 checklist items pass at both viewports against a live 400-row registry (1 Shared Globals + 399 Vertical). See the report for per-item pass evidence + measured drawer/panel geometry at both viewports.
- **Real assistive-technology sit-with-a-screen-reader QA** (VoiceOver/JAWS/NVDA) is **not a required gate** per D-058 (desktop-only, no real-AT). Automated axe / keyboard / reduced-motion checks stay in the coverage matrix.
- **Real Safari** — Claude in Chrome only drives Chrome; WebKit-in-Chromium is not Safari. Residual carried at the same shape as the Media Manager's D-049 Safari residual.
- **VerticalControlProvider** — separate cross-repo slice. Reads `addons/visual-editor/curation/vertical-approved-controls.json` (400 approved records exported by the R3-BX curation tool) and produces `ControlRecord[]`. Implements the R3-A `getControls` + R3-C-1 `buildDescriptor` interface. Does not require any DBVC change; ships as a Vertical add-on.
- **R4** — Expanded drawer (categories, richer UI). A UI-only expansion of the R3-C-2 drawer; no new backend surface, no new registered-writes. R4 consumes the same R3-A registry contract.

## Rollback runbook

Rollback is feature-level and non-destructive — no data migration is involved.

1. **Disable the Control Center flag** (`dbvc_visual_editor_control_center_enabled` → off, via Settings → Visual Editor → *Enable Brand Control Center*). Effect: `is_control_center_enabled()` returns false → `Rest\Routes::registerRoutes` no longer registers the list or open route (verified by `test_routes_are_absent_when_feature_switch_is_off`); `AssetLoader::enqueue` skips the drawer stylesheet + script (verified by `test_drawer_assets_do_not_enqueue_when_feature_switch_is_off`); `overlay-app.js`'s `isControlCenterEnabled()` returns false so the toolbar entry disappears on the next page load and the `bindControlCenterBridge()` document listener is not bound. This is the primary, instant kill switch. The rest of the Visual Editor overlay and the Shared Globals popover are unaffected.
2. **Disable the Visual Editor master flag** (`dbvc_addon_visual_editor_enabled` → off) to remove the Brand Control Center and the entire Visual Editor overlay together. `is_control_center_enabled()` requires BOTH switches, so master-off is a superset of feature-off (verified by `test_routes_are_absent_when_master_switch_is_off`).
3. **The Shared Globals popover keeps working under both rollback modes.** `SharedGlobalFieldsController::handle()` is the popover's route — it was refactored in R3-C-1 to delegate to a shared factory but its public response is byte-identical. The popover's toolbar entry is a separate slot (`shared-globals`) that R3 did not touch. Rolling R3 back does not affect the popover in any way.
4. **No content is orphaned.** R3 writes nothing. Any values a user saved via a control opened from the drawer are ordinary WordPress/ACF content that was written by the existing panel's save path — the same code the popover uses. Rolling R3 back does not change any persisted value.
5. **Code revert.** Reverting the R3 add-on code removes the registry, providers, REST controllers, drawer JS/CSS, and the toolbar entry cleanly. There is **no R3-specific schema migration** to undo — `OPTION_CONTROL_CENTER_ENABLED` can be left in place or deleted, and the popover route + descriptor factory extraction can be reverted as a unit (`SharedGlobalFieldsController` regains its inline `buildDescriptor` + private helpers) without any behavioral change to the popover's public response.
6. **Pre-flip verification** (recommended before flipping the switch off in production):
   - `curl` the popover route (GET `/dbvc/v1/visual-editor/session/{id}/shared-global-fields`) with a valid nonce; capture the response.
   - Flip the Control Center switch off.
   - `curl` the popover route again; the response must be byte-identical.
   - `curl` the two Control Center routes; both must return 404.
   - Visit a frontend page in Visual Editor mode; the toolbar's `sliders` slot must be absent, the `layers` (Shared Globals) slot must still work.

## Verification snapshot at wrap-up

- Full PHP suite `vendor/bin/phpunit` → **861 tests, 7 pre-existing failures** (6 inherited: BricksAddonPhase11, BricksAddonPhase7, CapabilityLandscape, ContentCollectorV2Phase29, ContentCollectorV2Phase32, ContentMigrationPhase37W0Settings + 1 pre-existing dirty-tree `ProposalDiffContractTest::test_entity_drawer_exposes_stable_mode_and_field_selectors`).
- R3-A subset `--filter "VisualEditorControlRegistry"` → **11 tests / 44 assertions OK**.
- R3-B subset `--filter "VisualEditorSharedGlobalsControlProvider"` → **5 tests / 28 assertions OK**.
- R3-C-1 subset `--filter "VisualEditorControlCenterRoutes"` → **10 tests / 36 assertions OK**.
- R3-D subset `--filter "VisualEditorControlCenterHardening"` → **7 tests / 18 assertions OK**.
- R3-BX subset `--filter "VisualEditorCuration"` → **29 tests / 96 assertions OK**.
- Media Manager subset `--filter "VisualEditorMedia"` → **115 tests / 1962 assertions OK** (unaffected).
- Drawer jsdom `node --test tests/visual-editor-brand-control-center-state.test.cjs` → **14 pass**.
- Media Manager jsdom `node --test tests/visual-editor-media-manager-state.test.cjs` → **42 pass** (baseline preserved).
- Agent docs `composer agent-docs:check` → **54 curated / 439 discovered / 0 unmapped**.
- Real-browser QA of drawer + panel coexistence: **CLOSED 2026-08-29** — all 12 checklist items pass at 1440×900 and 1280×720 against a live 400-row registry (see `qa/R3-DRAWER-BROWSER-QA-REPORT.md`).
