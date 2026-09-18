# R3 resume prompt — Registry-Backed Brand Control Center

> **ARCHIVED 2026-08-29 — R3 core (R3-A + R3-BX + R3-B + R3-C-1 + R3-C-2 + R3-D) is signed off and ship-ready.**
>
> The last living slice this file drove was R3-D (production hardening). Its checkpoint lives in `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md` §R3-D + `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/BRAND-CONTROL-CENTER-RELEASE-NOTES-AND-ROLLBACK.md` + EVIDENCE-LOG E-097.
>
> **Follow-on slices — each needs its own resume file when picked up:**
> - **R4** (Expanded drawer, UI-only) — plan in `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R4-EXPANDED-GLOBAL-BRAND-CONTROL-CENTER.md`. Reuses the R3-A registry contract as-is; no new backend surface, no new registered writes.
> - **VerticalControlProvider** (cross-repo) — lives in the Vertical theme. Reads `../../curation/vertical-approved-controls.json` (400 approved records exported by the R3-BX curation tool) and implements the R3-A `ControlProvider::getControls` + R3-C-1 `ControlProvider::buildDescriptor` interface. No DBVC change needed.
> - The one residual R3 gate carried into R4 is real-browser QA of the drawer + main-editor-panel coexistence at 1440×900 and 1280×720 (Media Manager D-049 shape). Not a blocker on R3 core ship.
>
> The fenced block and reference material below were the last operational state of this file. They stay in place for historical continuity — do not copy the fenced block into a new session; write a fresh R4 or VerticalControlProvider resume file whose scope matches those slices.

**How to use this file (historical — R3 archived):** copy the fenced block below into a fresh Claude Code chat. It is self-contained (no self-reference to this file) and gives the fresh agent enough to propose an R3-D plan and start work. The reference material below the fence is optional depth for anyone who wants it.

**R3-C is complete (2026-08-29)**: R3-C-1 (backend + descriptor-factory extraction + REST routes + PHP tests) landed 2026-08-28 (E-095). R3-C-2 (drawer JS module + control-center CSS + jsdom coverage + AssetLoader enqueue) landed 2026-08-29 (E-096). The kill switch is now operationally live behind the two-part gate (both switches default off). The only R3 slice remaining before ship is **R3-D** (production hardening).

**When a slice lands (historical guidance — this file is archived):** update both the fenced block (green baselines + next-slice pointer) AND the reference below (exact-point + evidence/decision counters).

---

```
Continue the DBVC Visual Editor R3 work — implement Slice R3-D (production hardening: fail-closed REST guard test coverage, Bricks Builder exclusion verification, drawer + main-editor-panel coexistence browser QA, release-notes + rollback runbook; no new REST routes, no new descriptor code).

## Read first (in this order — do not skip)

1. addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md (boundary line + next-tasks — R3-D bullet). The Media Manager sibling `releases/MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md` is the acceptance shape R3-D's release notes must mirror.
2. addons/visual-editor/src/Rest/Controllers/ControlCenterListController.php + ControlCenterOpenController.php + SharedGlobalFieldsController.php — R3-D's fail-closed guards must cover every branch each controller reaches for. Match the assertion style of `tests/phpunit/VisualEditorMediaManagerR2E2Test.php` (foreign-user session, revoked cap, stale revision, forged token, non-existent target).
3. addons/visual-editor/src/Assets/AssetLoader.php + addons/visual-editor/src/Context/FrontendRuntimeGuard.php — verify the drawer's enqueue path is inside the Bricks-Builder exclusion. The existing Media Manager pattern (guarded inside `shouldLoadFrontendAssets`) is the reuse target.
4. addons/visual-editor/assets/js/brand-control-center-app.js + addons/visual-editor/assets/css/control-center.css — R3-C-2 output. R3-D's browser QA at 1440×900 and 1280×720 verifies the drawer opens, filters, opens a row, coexists with the main panel, honors `prefers-reduced-motion`, and restores focus on close. Rows must never carry `data-owner-id`/`data-field-key`/`data-selector`/`data-path`/`data-descriptor`/`data-token` (already asserted in jsdom; browser check confirms production output).
5. docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md — hard constraints (desktop-only D-058, preserve-source-authority §5, no over-engineering §4, no new write authority).

Everything you need to plan R3-D is in these five; do not follow chains of `see also` links unless a specific question arises.

## Working directory (absolute)

/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main

## Hard constraints (do not violate)

- Preserve dirty working tree. NO git operations of any kind.
- Preserve ~/.config/dbvc-local-agent.env; never write raw credentials into any repo file.
- Verify content mutations ONLY via disposable PHPUnit fixtures — no live-site mutations.
- Do NOT toggle persistent WP options merely to satisfy a test.
- Desktop only (D-058); no mobile/tablet/touch/real-AT.
- No new write authority — R3-D is verification + hardening + release notes only. It adds no new endpoints, no new descriptor code, no new drawer states. Every existing D-063 gate (kill switch, popover coexistence) stays authoritative.

## R3-D scope (bounded — hardening + verification + release notes)

**R3-D preserves everything below (do NOT change):** the D-063 kill switch, the D-061 drawer form, the R3-C-1 REST contract, the R3-C-2 pinned decisions (absorb-descriptor event bridge, dock placement after `shared-globals`, client-side filtering), the descriptor factory extraction. R3-D adds only verification tests + docs.

- **Fail-closed REST coverage** — add `tests/phpunit/VisualEditorControlCenterHardeningTest.php` mirroring `VisualEditorMediaManagerR2E2Test`'s shape. Assert both R3-C-1 routes return without a mutation and without a descriptor when: (a) kill switch off (route not registered — assert 404 via `rest_do_request`); (b) master Visual Editor switch off (same); (c) `wp_set_current_user(0)` unauthenticated → 401; (d) subscriber-role user → 403 (visibility-blocked); (e) foreign-user session id → 404; (f) forged/malformed publicId → 404; (g) fixture provider whose `buildDescriptor` returns null → 404; (h) descriptor with empty `reference_post_types` → 403. Also assert that flipping `OPTION_CONTROL_CENTER_ENABLED` off between list and open makes the open route unreachable.
- **Bricks Builder exclusion verification** — add a fixture-driven test that simulates a Bricks Builder request context (existing pattern in the Media Manager tests) and asserts `AssetLoader::enqueue` short-circuits before reaching the `controlCenter` branch, and that neither `wp_style_is('dbvc-visual-editor-control-center')` nor `wp_script_is('dbvc-visual-editor-control-center')` returns true.
- **Browser QA** — laptop/desktop only per D-058, at 1440×900 and 1280×720. Verify: drawer opens from the toolbar's `sliders` slot, `aria-expanded` toggles correctly on the button, the drawer + main editor panel coexist (drawer stays visible after a row Open), tabs + chip filters + label search all update the row set client-side, Escape closes and returns focus to the toolbar button, `prefers-reduced-motion` disables the slide-in and spinner rotation, no row DOM node carries any of the six forbidden `data-*` attributes, single polite live region exists, and Shared Globals popover behavior is unchanged. Capture screenshots (SVG placeholders if PNG capture is skipped per project convention).
- **Release notes + rollback runbook** — new file `releases/BRAND-CONTROL-CENTER-RELEASE-NOTES-AND-ROLLBACK.md` mirroring `releases/MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md`. Cover: what the feature does, the two-part kill switch, no-mutation and no-new-write-authority guarantees, exactly which two REST routes register (behind the gate), exactly which frontend assets enqueue (behind the gate), Bricks Builder exclusion, D-063 popover-coexistence commitment, safe rollback (flip either switch off; existing values and popover behavior are untouched).
- Docs reconciliation after the slice: release-doc R3-D checkpoint (closes the R3 core bracket; the ship-readiness gate), tracker row + checklist rows (R3-D done, R3 status flips to `Complete`), EVIDENCE-LOG E-097, RISK-REGISTER update only if a new risk emerges, handoff boundary line, CHANGELOG entry, agent-docs refresh (expect no new discoveries), resume file fenced block either archived or bumped to R4 / VerticalControlProvider per maintainer direction.

## Green baselines to preserve

- vendor/bin/phpunit → 854 tests + N new (R3-D hardening tests, ~10-15), same 7 pre-existing failures (6 inherited + 1 dirty-tree ProposalDiffContractTest).
- vendor/bin/phpunit --filter "VisualEditorControlRegistry" → 11 OK.
- vendor/bin/phpunit --filter "VisualEditorSharedGlobalsControlProvider" → 5 OK.
- vendor/bin/phpunit --filter "VisualEditorControlCenterRoutes" → 10 OK.
- vendor/bin/phpunit --filter "VisualEditorCuration" → 29 OK.
- vendor/bin/phpunit --filter "VisualEditorMedia" → 115 OK.
- node --test tests/visual-editor-brand-control-center-state.test.cjs → 14 pass.
- node --test tests/visual-editor-media-manager-state.test.cjs → 42 pass (the R3-B resume-file claim of 43 was stale — the current file has 42 test blocks).
- composer agent-docs:check → 54 / 439 / 0 unmapped (no new REST expected).

## Order of operations

Read the five required files above, then propose the R3-D plan and confirm with me before implementing. Bounded to one session. R3-D closes the R3 core bracket; next is R4 or the cross-repo VerticalControlProvider per maintainer direction.
```

---

## Reference (deeper depth — optional)

Everything below is expanded context for anyone who wants more than the fenced block gives. The fenced block is complete on its own; a fresh agent does not need to read this section to do R3-B.

### Exact point we're at (as of 2026-08-29)

R3 — Registry-Backed Brand Control Center. **R3-D is the next (and final) core-R3 slice.**

Done and stable (no reopens expected):

- **R3-A** — registry foundation shipped (E-088).
- **R3-BX** — parallel curation admin tool shipped, polished, and used (E-089, E-090, E-091, E-092, E-093). The maintainer curated **770 options-page candidates** and exported the seed to `addons/visual-editor/curation/vertical-approved-controls.json` (**400 approved controls** with categories + adopted priorities). The curation tool remains kill-switch-gated (`dbvc_visual_editor_curation_tool_enabled`, default off) but is direction-locked as a future permanent admin governance surface (D-062; see `docs/dropins/dbvc-visual-editor-brand-controls-guide/deferred/BRAND-CONTROL-CENTER-ADMIN-OPTIONS-PHASE.md`).
- **R3-B** — Shared Globals compatibility provider shipped (E-094, D-063). `Registry\Providers\SharedGlobalsControlProvider` adapts the existing configured Shared Globals field list onto the runtime `ControlRegistry` as a discovery-only surface. Two-part kill switch (new `dbvc_visual_editor_control_center_enabled` + master Visual Editor switch, both default off). `Bootstrap\Addon` now instantiates `ControlRegistry` unconditionally and exposes `getControlRegistry()`. `SharedGlobalFieldsController` stays intact — parallel discovery, not a replacement. 5 PHPUnit tests / 28 assertions; `SETTINGS_VERSION` 5→6. No new write authority.
- **R3-C-2** — Brand Control Center drawer UI shipped (E-096). New `assets/js/brand-control-center-app.js` (~900 lines, IIFE mirroring `media-manager-app.js`) + `assets/css/control-center.css` translate the accepted mockup. Wires the already-registered `sliders` toolbar icon (dock placement after `shared-globals`), consumes the R3-C-1 REST routes with client-side filtering, and hands the panel-open payload to `overlay-app.js` via a `dbvc:visual-editor:absorb-descriptor` document event. `AssetLoader` gained a kill-switch-gated enqueue branch + `controlCenter` bootstrap block + ~40 drawer i18n strings; `overlay.css` gained one net-new `:root` token `--dbvc-ve-z-drawer: 120015`; `overlay-app.js` gained four surgical additions (helpers, dock toolbar entry, click branch, absorb-descriptor bridge). 14 jsdom tests pass; media-manager jsdom baseline preserved at 42 (the earlier resume-file claim of 43 was stale). Full PHPUnit unchanged at 854/7; agent docs 54/439/0 (one filter hash rotated). Rows carry ONLY `data-public-id` — no forbidden target attrs. Kill switch still default off.
- **R3-C-1** — Brand Control Center backend shipped (E-095). Extracted `SharedGlobalFieldsController::buildDescriptor` + every private helper participating in it into a stateless `Registry\Providers\SharedGlobalsDescriptorFactory`; controller delegates (popover route response byte-identical). `ControlProvider` interface widened with `buildDescriptor(ControlRecord, sessionId, pageContext): ?EditableDescriptor` (parallel-factory alternative rejected — see the R3-C-2 fenced block above for a summary; the choice was implementation-level and did not require its own DECISION-LOG row per the D-063 gate). `SharedGlobalsControlProvider::buildDescriptor` re-resolves the ACF field via the existing seam, re-validates type ∈ {relationship, post_object}, delegates to the factory. New `ControlRegistry::buildDescriptorForRecord()` keeps the private providers map encapsulated. Two new session-scoped REST controllers `ControlCenterListController` (`GET .../control-center/controls`) and `ControlCenterOpenController` (`POST .../control-center/open`) wired into `Rest\Routes` under the kill switch; both fail closed on the standard axes. `Rest\Routes::__construct` gained a `ControlRegistry` param; `Bootstrap\Addon` passes `$this->control_registry`. 10 PHPUnit tests / 36 assertions. No frontend JS/CSS in this slice.
- **R3 mockup** — static HTML/CSS reference at `docs/ui-mockups/dbvc-visual-editor/r3-brand-control-center/` (accepted 2026-08-26; drawer form pinned via D-061; `sliders` icon registered in `overlay-app.js`; R3-C-2 translated this into production).
- **Adoption inventory** — `docs/dropins/dbvc-visual-editor-brand-controls-guide/knowledge/EXISTING-SUPPORT-INVENTORY.md` maps every ACF family × resolver × controller × per-slice reuse checklist. Read this before designing anything new for R3-D.

Next slices, in this order:

- **R3-D** — Production hardening (this slice): fail-closed REST guard tests (new `VisualEditorControlCenterHardeningTest`), Bricks Builder exclusion verification, browser QA of the drawer + main-editor-panel coexistence at 1440×900 and 1280×720, `releases/BRAND-CONTROL-CENTER-RELEASE-NOTES-AND-ROLLBACK.md` mirroring the Media Manager sibling. No new REST routes, no descriptor changes, no drawer states. This is a verification + docs slice; the goal is ship-readiness for R3 core.
- **R4** — Expanded drawer (categories, richer UI): a UI-only expansion of the R3-C-2 drawer; no new backend surface, no new registered-writes. R4 reuses the R3-A registry contract as-is.
- **VerticalControlProvider** (separate cross-repo slice, after R3-D) — reads the exported curation JSON at `addons/visual-editor/curation/vertical-approved-controls.json` and produces `ControlRecord[]` covering the 400 approved controls. Implements the R3-A `ControlProvider::getControls` + R3-C-1 `ControlProvider::buildDescriptor` interface.

### Hard process constraints (do not violate)

- **Preserve the dirty working tree.** The repo has a large pre-existing set of uncommitted changes on branch `codex/visual-editor-linked-posts-plan`.
- **NO git operations of any kind.** Never run reset / restore / stash / clean / checkout / switch / branch / broad `git add` / commit / push. Don't change branches. Don't skip hooks. (Informational: current branch `codex/visual-editor-linked-posts-plan`; main is `master`.)
- **Preserve `~/.config/dbvc-local-agent.env`.** It carries a local WordPress application password outside the repo (chmod 600). Never write raw credentials into any repo file, commit message, log line, chat message, or `.env` INSIDE the repo. If you need authenticated REST QA, follow the load recipe in `docs/development/local-agent-auth.md` (set -a + source + curl --user).
- **Verify content mutations ONLY via disposable PHPUnit fixtures.** Never mutate live-site content on `dbvc-codexchanges.local` (no writing real posts / terms / meta to prove a slice works). Tests create + tear down their own fixtures.
- **Do NOT toggle persistent WP options** merely to satisfy a test. Read-only observation is fine; mutation is not.
- **Desktop only, permanent (D-058).** Do not propose, plan, mock, or QA mobile / tablet / touch / real-handset behavior. Real AT (VoiceOver / JAWS / NVDA) is not a required gate.
- **No new write authority.** The registry is a discovery-only read surface. All mutations continue to route through the existing `EditableRegistry` / `MediaFindingDescriptorBridge` / `MutationService` pipeline.
- Work in bounded, explicitly-authorized slices. Reconcile docs after each slice.
- Scratchpad for temp files, not `/tmp`.

### Required reads in priority order (deeper than the fenced block)

1. **Adoption inventory (read FIRST before planning any slice)** — `docs/dropins/dbvc-visual-editor-brand-controls-guide/knowledge/EXISTING-SUPPORT-INVENTORY.md`. §1 = ACF family × resolver × controller × option-owned save × R5 slice. §2 = cross-cutting reusable infrastructure. §3 = per-planned-slice adoption checklist. §4 = known gaps.
2. **Plan** — `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md`. R3-A + R3-BX checkpoints at the bottom.
3. **Wiring schematic** — `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md` (informative for R3-C; skim for R3-B).
4. **Current Visual Editor component map** — `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md`.
5. **Canonical Visual Editor handoff** — `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`.
6. **Governing directives** — `docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md`.
7. **Tracking (skim so you know where to add rows after each slice)** — `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/{IMPLEMENTATION-TRACKER,EVIDENCE-LOG,DECISION-LOG,RISK-REGISTER}.md`.
8. **Local auth env recipe** (only if you need live REST QA) — `docs/development/local-agent-auth.md`.

### R3-D implementation approach (bounded — verification + docs)

1. **PHP hardening tests** — new `tests/phpunit/VisualEditorControlCenterHardeningTest.php` sitting alongside `VisualEditorControlCenterRoutesTest.php`. Reuse the same fixture-provider + seeded-session harness. Coverage:
   - Kill switch off (both parts) → routes not registered → `rest_do_request` returns 404 (or `WP_Error` if PHP-level). Guard against the routes ever being reachable when the gate is off.
   - Master Visual Editor switch off while control-center switch on → same 404 (both parts required).
   - Unauthenticated → 401 at `permission_callback`.
   - Subscriber role → visibility closure filters records → open route returns 404 for a record the subscriber cannot see.
   - Foreign-user session id (session's `user_id` != current user) → 404.
   - Forged / malformed publicId (empty, wrong colon shape, unknown provider prefix) → 404.
   - Fixture provider whose `buildDescriptor` returns `null` for the requested record → 404 (already covered by `RoutesTest` but re-assert here as a hardening baseline).
   - Descriptor with empty `source.reference_post_types` → 403 (same, re-asserted).
   - After a successful open, flipping `OPTION_CONTROL_CENTER_ENABLED` off makes the very next open call fail (route no longer registered on the fresh REST-init).

2. **Bricks Builder exclusion verification** — new focused test (either in `VisualEditorControlCenterHardeningTest.php` or a small dedicated file) that toggles a Bricks-Builder request-context flag (mirror how any existing Media Manager test does it — search `VisualEditorMediaManager*Test` for the Bricks fixture pattern) and asserts that `AssetLoader::enqueue` short-circuits before reaching the `controlCenter` branch. Follow-up assertion: after `enqueue()` runs, `wp_style_is('dbvc-visual-editor-control-center')` and `wp_script_is('dbvc-visual-editor-control-center')` both return `false`.

3. **Browser QA (D-058: desktop-only, no mobile)** — laptop/desktop at 1440×900 and 1280×720. If Claude-for-Chrome-against-a-real-LocalWP-session is available (per the local-agent env recipe in `docs/development/local-agent-auth.md`), verify: drawer opens from the toolbar's `sliders` slot; `aria-expanded` on the toolbar button reflects state; drawer + main editor panel coexist (drawer stays visible after a row Open); tabs + chip filters + label search all update the row set client-side; Escape closes and restores focus to the toolbar button; `prefers-reduced-motion` disables the slide-in and spinner rotation; no row DOM node carries any of the six forbidden `data-*` attributes; the drawer shell has exactly one polite live region; the existing Shared Globals popover route response is byte-identical (curl the popover route before and after R3-D lands to confirm). If browser QA is skipped for a session, mark it as a residual gate — do not fabricate results.

4. **Release notes + rollback runbook** — new file `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/BRAND-CONTROL-CENTER-RELEASE-NOTES-AND-ROLLBACK.md`. Mirror the sibling `MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md` shape section-for-section. Cover: what the feature does (registry-backed discovery drawer; opens registered controls into the existing panel), the two-part kill switch (both parts default off, D-063), what it does NOT do (no new mutation authority; the popover is unchanged; no new custom tables), which two REST routes register when on (list + open under the kill-switch gate), which frontend assets enqueue (drawer JS + CSS under the same gate; Bricks Builder exclusion inherits), safe rollback (flip either switch off — the existing Shared Globals popover behavior + all persisted values are unchanged), and pre-flip verification steps (curl the list route; curl the open route with a known publicId; visit a frontend page in Visual Editor mode; confirm popover still works).

5. **Docs reconciliation** — R3 release-doc R3-D checkpoint (closes the R3 core bracket); tracker row + checklist rows (R3 status flips to `Complete`; R3-D checkbox on); EVIDENCE-LOG E-097; RISK-REGISTER only if a new risk emerges; handoff boundary; CHANGELOG entry; agent-docs refresh (expect no new discoveries); resume file archived or bumped to R4 / VerticalControlProvider per maintainer direction.

### R3-C-2 implementation approach (retrospective — captured for reference)

1. New file `addons/visual-editor/assets/js/brand-control-center-app.js`. Model on `assets/js/media-manager-app.js` for lifecycle (module boot, single instance keyed off a data-attribute on the toolbar button, wp-nonce-carrying REST client, first-open resume, teardown on Escape / close / toolbar toggle). Drawer state machine (see mockup COMPONENT-NOTES.md §4):
   - `loading-initial` → `list` (or `empty` / `empty-filtered` / `error`) on first GET.
   - `list` ↔ `loading-refresh` on tab or filter change (client-side only in R3 — no round-trip needed; the drawer stays in `list` while re-filtering the client-held items array).
   - `list` → `opening` (per row, `aria-busy="true"`) on Open click → `opened` (drawer + panel coexist) or `open-error` (inline row notice + Dismiss).
   - `inspect_only`, `unsupported`, `unavailable` rows never surface an Open control; render muted per the mockup.
   Reuse the R3-BX curation `assets/js/curation.js` client-side filter pattern (proven at ~150 rows; drawer scrolls internally, label search debounced 180ms). Row-focus continuity via `body.ownerDocument.activeElement` snapshot. Single polite `role="status"` region — do NOT add another (Component Map §6). Reduced-motion (`@media (prefers-reduced-motion: reduce)`) suppresses the slide-in transition + spinner rotation.

2. New file `addons/visual-editor/assets/css/control-center.css`. Split from `overlay.css` for the same reason `media-manager.css` is separate (module boundary + independent versioning). Scoped strictly under `.dbvc-ve-control-center` and its `__*` elements (COMPONENT-NOTES.md §1 is the selector inventory). Declare `--dbvc-ve-z-drawer: 120015` in `:root` (between panel 120010 and toolbar 120020). Every other color / font / spacing draws from existing `--dbvc-ve-*` tokens.

3. `AssetLoader::enqueue` — add a new branch mirroring the media-manager branch. Enqueue `control-center.css` + `brand-control-center-app.js` under BOTH the master Visual Editor switch AND `is_control_center_enabled()`. Bricks Builder exclusion inherits — the existing guard skips the whole enqueue path inside Bricks. Anchor `plugins_url('', ...)` on the addon's real `bootstrap.php` (NOT `dirname(__DIR__, N)` — E-090 for the exact failure). Version via `filemtime()` with a static fallback.

4. `assets/js/overlay-app.js` — add ONE new toolbar entry using the existing `createToolbarButtonMarkup` helper + the already-registered `sliders` icon. Data-action: `data-dbvc-ve-toolbar-action="open-control-center"`. Delegate to the drawer module's open handler; toggle drawer visibility; `aria-expanded` on the button reflects state; close-on-second-click; Escape closes and returns focus to the button. Do NOT touch any other toolbar entry — the Shared Globals popover button (`layers` icon) stays exactly as it is (D-063).

5. jsdom coverage `tests/visual-editor-brand-control-center-state.test.cjs` (analogous to `visual-editor-media-manager-state.test.cjs`):
   - State transitions (list → loading-refresh on filter change → list; list → opening on row-open click; opening → opened on 200; opening → open-error on 4xx).
   - Row-focus continuity across rerenders (filter change, tab change, retry-after-error).
   - Filter application (tab, chip filters, label search debounce).
   - Tabs: `role="tab"`, `aria-selected`, arrow-key navigation.
   - Safe list projection consumption (no `source` bag on the wire).
   - Reduced-motion no-slide branch.
   - Single-live-region invariant (only one `role="status"` in the shell).
   - Security invariant: rows carry `data-public-id` only — no `data-owner-id` / `data-field-key` / `data-selector` / `data-path` / `data-descriptor` / `data-token` (schematic §6 invariant 2).

6. REST client integration:
   - List: `GET /wp-json/dbvc/v1/visual-editor/session/{sessionId}/control-center/controls?category=&status=` with `X-WP-Nonce`.
   - Open: `POST /wp-json/dbvc/v1/visual-editor/session/{sessionId}/control-center/open` body `{publicId}` with `X-WP-Nonce`.
   - Consume `{ok, viewModelVersion, query, items}` from list.
   - Consume `{ok, publicId, descriptors, descriptorHydrations}` from open — hand `descriptors` + `descriptorHydrations` to the existing panel bootstrap the same way the popover's response is consumed today.

7. Do NOT add new REST routes. Do NOT add new PHP tests. Do NOT change `ControlProvider`, `ControlRegistry`, or the descriptor factory.

### R3-C-1 implementation approach (retrospective — captured for reference)

1. Two new REST controllers under `addons/visual-editor/src/Rest/Controllers/`:
   - `ControlCenterListController` (`GET /dbvc/v1/visual-editor/control-center/controls`, optional `category`/`status` query params): permission_callback + `EditModeState::isRestRequestAuthorized()` same as `SharedGlobalFieldsController`; returns `$this->control_registry->listControls([...])` verbatim (safe list projection only — no `source` bag).
   - `ControlCenterOpenController` (`POST /dbvc/v1/visual-editor/control-center/open`, body `{publicId, sessionId}`): calls `ControlRegistry::getVisibleRecord($publicId)` (fails closed on null — unknown / malformed / visibility-blocked), then hands the internal `ControlRecord` to the record's provider-side descriptor factory to mint a fresh `EditableDescriptor` server-side, adds it to the session (`EditableRegistry::addDescriptorToSession`), and returns the payload the frontend needs to open the row into `.dbvc-ve-panel` (mirror `SharedGlobalFieldsController::handle`'s descriptor/hydrations shape).
   - Wire both into `Rest\Routes::register()` under the `is_control_center_enabled()` gate. Follow the REST-route conventions captured in `EXISTING-SUPPORT-INVENTORY.md` §2.3 verbatim.

2. Provider descriptor-factory seam. Two shapes to choose between during implementation:
   - **(a) Extend the R3-A interface** with `ControlProvider::buildDescriptor(ControlRecord $record): EditableDescriptor`. Existing tests that construct anonymous providers will need a trivial default. Simple, one call site.
   - **(b) Parallel factory** registered alongside the provider (a `ControlDescriptorFactory` map keyed by `providerId`). Zero interface churn; slightly more moving parts. Pick (a) unless tests would balloon.
   Either way, extract `SharedGlobalFieldsController::buildDescriptor` into a shared `SharedGlobalsDescriptorFactory` (probably at `src/Registry/Providers/`) that BOTH the existing popover controller and the new open controller call. **The popover route's public behavior must not change** — verify by running `--filter "VisualEditorSharedGlobals"` (if such tests exist) and the full suite.

3. Frontend module `addons/visual-editor/assets/js/brand-control-center-app.js` (analogous to `media-manager-app.js`):
   - Left-anchored tabbed drawer per D-061 — one tab per curation category plus an `All` tab, persistent filter strip, sticky-header scrollable table body, footer row count.
   - Client-side filter engine pattern from R3-BX curation (see `assets/js/curation.js` — proven at ~150 rows).
   - Row-focus continuity on rerender (`body.ownerDocument.activeElement` snapshot pattern from Media Manager).
   - Single polite live region per shell; reduced-motion honors `@media (prefers-reduced-motion: reduce)`.
   - Enqueued by `AssetLoader` under the same gate; Bricks Builder exclusion inherited via `FrontendRuntimeGuard`.
   - Anchor asset URLs on the addon's real `bootstrap.php` and version via `filemtime()` — do NOT recompute the plugin root via `dirname(__DIR__, N)` (see E-090 for the exact failure mode).

4. Drawer CSS scoped under `.dbvc-ve-control-center` (new stylesheet or additive block on `overlay.css` — choose whichever matches how Media Manager splits it). New z-index token `--dbvc-ve-z-drawer = 120015` declared in `overlay.css` `:root` (between panel 120010 and toolbar 120020).

5. New toolbar entry using `createToolbarButtonMarkup` + `renderToolbarIcon('sliders')` (icon already registered in overlay-app.js). No other toolbar changes; the existing Shared Globals popover button stays untouched.

6. PHP tests — mirror `VisualEditorMediaManagerR2E2Test` shape for both new routes:
   - Kill switch off → 404 or 403.
   - Master switch off → 403.
   - Unauthenticated → 401.
   - Unauthorized-descriptor for a specific record → 403 (visibility-blocked publicId returns null).
   - Unknown publicId → 404.
   - Malformed publicId (missing `:`, empty half) → 400.
   - Happy path list → the R3-B records project through unchanged.
   - Happy path open (Shared Globals record) → yields a valid session descriptor whose shape matches what `SharedGlobalFieldsController::buildDescriptor` produces (proves the extraction preserved behavior).

7. jsdom coverage for the drawer state machine (analogous to `visual-editor-media-manager-state.test.cjs`).

8. `SharedGlobalFieldsController` **stays intact** — do NOT remove or modify its `handle()` route. R3-C is parallel; the existing popover keeps working exactly as it does today (D-063).

### Validation commands + current green baselines

Run from the plugin root (`/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`):

- Full PHP suite: `vendor/bin/phpunit` → **854 tests, 6 inherited failures + 1 pre-existing dirty-tree failure (`ProposalDiffContractTest::test_entity_drawer_exposes_stable_mode_and_field_selectors` — the working tree's `src/admin-app/index.js` drifted from the assertion; NOT to be fixed here per the "preserve dirty tree" constraint)**. The 6 inherited are outside the R3 area: BricksAddonPhase11, BricksAddonPhase7, CapabilityLandscape, ContentCollectorV2Phase29, ContentCollectorV2Phase32, ContentMigrationPhase37W0Settings.
- R3-A subset: `vendor/bin/phpunit --filter "VisualEditorControlRegistry"` → **11 tests / 44 assertions OK**.
- R3-B subset: `vendor/bin/phpunit --filter "VisualEditorSharedGlobalsControlProvider"` → **5 tests / 28 assertions OK**.
- R3-C-1 subset: `vendor/bin/phpunit --filter "VisualEditorControlCenterRoutes"` → **10 tests / 36 assertions OK**.
- R3-BX subset: `vendor/bin/phpunit --filter "VisualEditorCuration"` → **29 tests / 96 assertions OK**.
- Media Manager subset (should be unaffected): `vendor/bin/phpunit --filter "VisualEditorMedia"` → **115 OK**.
- Drawer jsdom: `node --test tests/visual-editor-brand-control-center-state.test.cjs` → **14 pass**.
- Media Manager jsdom (baseline): `node --test tests/visual-editor-media-manager-state.test.cjs` → **42 pass** (the earlier resume-file claim of 43 was stale — the current file has 42 test blocks; corrected 2026-08-29).
- JS lint: `npm run lint:visual-editor-media-manager` (clean). `api-client.js` is NOT in the lint set — leave its pre-existing `no-undef` on `DBVCVisualEditorBootstrap` alone. R3-D can optionally add `brand-control-center-app.js` to the lint script if a per-module lint pattern exists.
- Agent docs: `composer agent-docs:refresh` then `composer agent-docs:check` → **54 curated / 439 discovered / 0 unmapped**. R3-D typically adds no new REST — expect no new discoveries. If line shifts rotate a hash from the hardening test's addition, the check names the stale id so you can paste the fresh id into `docs/agents/manifest.json`.

### Doc reconciliation checklist (do this after each slice, every time)

Under `docs/dropins/dbvc-visual-editor-brand-controls-guide/`:

- `releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md` — add a dated "Slice R3-D checkpoint" (closes the R3 core bracket).
- `tracking/IMPLEMENTATION-TRACKER.md` — flip the R3 status row to `Complete`; check off the R3-D checklist rows.
- `tracking/EVIDENCE-LOG.md` — next id is **E-097** (E-088 R3-A; E-089 R3-BX; E-090 R3-BX post-landing polish; E-091 skip ACF `group` containers; E-092 existing-support inventory; E-093 bulk-approve + deferred-phase doc; E-094 R3-B Shared Globals compat provider; E-095 R3-C-1 backend + descriptor factory extraction + REST routes; E-096 R3-C-2 drawer UI).
- `tracking/DECISION-LOG.md` — next id is **D-064** (D-058 desktop-only; D-059 R3-BX temporary policy; D-060 superseded; D-061 R3 drawer form; D-062 deferred admin-options promotion; D-063 R3 kill-switch + parallel-popover coexistence). R3-D typically adds no new row — the hardening work is verification, not new direction.
- `tracking/RISK-REGISTER.md` — only if a risk changes.

Plus:

- `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md` (canonical; boundary + next-tasks).
- `addons/visual-editor/CHANGELOG.md` (Unreleased, plain-language user-facing entry).
- Re-run agent-docs after any REST / hook / setting change.
- Update THIS file's fenced block (green baselines + next-slice pointer) AND this reference section (exact-point + counters) when the slice lands.

### Common failure signals to watch for

- **Agent docs check fails with "stale discovery snapshot"** — run `composer agent-docs:refresh` first, then `composer agent-docs:check`.
- **Agent docs check fails with "unknown discovery ID"** — a hash changed because a source file gained/lost a hook or moved lines. Grep the discovery snapshot for the hook / setting / route name, update the id in `docs/agents/manifest.json`.
- **Full suite reports `ProposalDiffContractTest` failing** — that's the pre-existing dirty-tree failure. Leave it alone. Confirm by running in isolation — it will still fail.
- **`VisualEditorMediaManagerR1ATest` fails on the `SETTINGS_VERSION` assertion** — R3-B already bumped `SETTINGS_VERSION` 5→6 and updated that assertion. R3-D does not touch settings — if this fails, something else added a persistent option; investigate before bumping.
- **Any R3 subset fails** — R3-D should not touch any R3 backend PHP or drawer JS/CSS. If any subset fails, R3-D accidentally broke a shared file; roll back.
- **The existing Shared Globals popover regresses** — R3-D does not touch `SharedGlobalFieldsController` or `SharedGlobalsDescriptorFactory`. If popover behavior changes, something touched the wrong file; roll back.
- **Hardening test says a route is registered when the kill switch is off** — that's a real bug; look at `Rest\Routes::registerRoutes`'s gate and confirm both parts of `is_control_center_enabled()` are checked (master switch AND `OPTION_CONTROL_CENTER_ENABLED`).
- **Bricks Builder test says the drawer assets DO enqueue inside Bricks** — the `AssetLoader::enqueue()` short-circuit via `shouldLoadFrontendAssets()` must fire before the `controlCenter` branch. Do NOT reorder — the existing guard order is what the R2 Media Manager tests already prove.
- **Browser QA shows two Brand Controls toolbar buttons** — the toolbar HTML is rebuilt from `ensureToolbar()`; verify `state.toolbarNode && state.toolbarNode.isConnected` short-circuits correctly. Do NOT re-append the button on repeated mount() calls.
- **Drawer opens but no rows render** — check `bootstrap().controlCenter.enabled === true` and that `restBase` is set. In prod that comes from `AssetLoader`'s `wp_localize_script` payload; in a test seed both keys in `window.DBVCVisualEditorBootstrap`.
