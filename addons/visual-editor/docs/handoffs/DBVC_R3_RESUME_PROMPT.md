# R3 resume prompt — Registry-Backed Brand Control Center

Copy-paste the fenced block below into a fresh Claude Code chat to resume R3 implementation.
Update the "Exact point we're at" section as slices land.

---

```
Continue the DBVC Visual Editor R3 work — Registry-Backed Brand Control Center.

FIRST, read this session-resume handoff in full before touching anything:
  addons/visual-editor/docs/handoffs/DBVC_R3_RESUME_PROMPT.md

It links every required doc, states the process constraints, gives the validation
commands + green baselines, and pins the exact point we're at.

## Exact point we're at (as of 2026-08-24)

R3 — Registry-Backed Brand Control Center. Done and validated:
**Slice R3-A** (registry foundation) and **Phase R3-BX** (parallel curation
tool — kill-switch-gated admin surface for approving which options-page ACF
fields become Brand Control Center controls). R3-BX writes committable
artifacts to `addons/visual-editor/curation/`; when the maintainer curates
and exports, the JSON seeds a future `VerticalControlProvider`. R3-BX does
NOT gate R3-B. Neither R3-A nor R3-BX has wired anything into
`Addon::register` for the runtime registry yet — that's still R3-B.

**R3-BX operator notes:** enable via Settings → Visual Editor → **Enable
Brand Control Center curation tool** (`dbvc_visual_editor_curation_tool_enabled`,
default off). Admin submenu appears under Settings → Visual Editor →
**BCC Curation**. Decisions persist in `dbvc_visual_editor_curation_decisions`
option. Turn the kill switch off after export; the option and artifacts
survive.

Next slices, in this order:
- **R3-B — Shared Globals compatibility provider (headless).** Adapt the existing
  `SharedGlobalFieldsController`'s configured relationship/post_object fields into
  `ControlRecord`s. Register on `Addon::register()` under a Media-Manager-style
  feature gate. First real provider. No REST route yet. No UI yet.
- **R3-C — Minimal center UI + open route.** Two new REST routes
  (`GET /control-center/controls`, `POST /control-center/open`) plus the smallest
  production interface that lists registered controls and hands each Open click to
  a fresh server-side descriptor resolution → existing panel opens for edit. UI
  must implement the accepted R3 mockup (see below); do NOT invent shapes the
  mockup doesn't cover.
- **R3-D — Hardening.** Capability + nonce + Bricks-Builder exclusion +
  browser coverage + release-notes/rollback.

Parallel workstream (independent of R3-B): a UI/UX agent may be crafting the R3
mockup deliverable from the handoff pair listed below. R3-B does NOT gate the
mockup; the mockup does NOT gate R3-B. R3-C waits for both.

## Hard process constraints (do not violate)

- **Preserve the dirty working tree.** The repo has a large pre-existing set of
  uncommitted changes on branch `codex/visual-editor-linked-posts-plan`.
- **NO git operations of any kind.** Never run reset / restore / stash / clean /
  checkout / switch / branch / broad `git add` / commit / push. Don't change
  branches. Don't skip hooks. (Informational: current branch
  `codex/visual-editor-linked-posts-plan`; main is `master`.)
- **Preserve `~/.config/dbvc-local-agent.env`.** It carries a local WordPress
  application password outside the repo (chmod 600). Never write raw credentials
  into any repo file, commit message, log line, chat message, or `.env` INSIDE
  the repo. If you need authenticated REST QA, follow the load recipe in
  `docs/development/local-agent-auth.md` (set -a + source + curl --user).
- **Verify content mutations ONLY via disposable PHPUnit fixtures.** Never mutate
  live-site content on `dbvc-codexchanges.local` (no writing real posts / terms /
  meta to prove a slice works). Tests create + tear down their own fixtures.
- **Do NOT toggle persistent WP options** merely to satisfy a test. Read-only
  observation is fine; mutation is not.
- **Desktop only, permanent (D-058).** Do not propose, plan, mock, or QA
  mobile / tablet / touch / real-handset behavior. Real AT (VoiceOver / JAWS /
  NVDA) is not a required gate. See `docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md` §0.
- **No new write authority.** The registry is a discovery-only read surface. All
  mutations continue to route through the existing `EditableRegistry` /
  `MediaFindingDescriptorBridge` / `MutationService` pipeline. Opening a control
  from R3 opens the existing panel; it does not introduce a Media-Manager-style
  parallel writer.
- Work in bounded, explicitly-authorized slices. Reconcile docs after each slice.
- Scratchpad for temp files, not `/tmp`.

## Required reads (in order)

1. **Reuse map — read before planning any slice** —
   `docs/dropins/dbvc-visual-editor-brand-controls-guide/knowledge/EXISTING-SUPPORT-INVENTORY.md`
   (ACF family × resolver × controller × option-owned save; cross-cutting
   infrastructure; per-slice adoption checklists; known gaps.)
2. **Plan** —
   `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md`
   (Slice R3-A checkpoint at the bottom captures exactly what's built.)
3. **Wiring schematic (for the UI/UX agent, but useful context for you too)** —
   `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md`
4. **Current Visual Editor component map** —
   `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md`
5. **Canonical Visual Editor handoff** —
   `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`
6. **Governing directives (scope + boundaries)** —
   `docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md`
7. **Tracking (skim so you know where to add rows after each slice)** —
   `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/{IMPLEMENTATION-TRACKER,EVIDENCE-LOG,DECISION-LOG,RISK-REGISTER}.md`
8. **Local auth env recipe (only if you need live REST QA)** —
   `docs/development/local-agent-auth.md`

Starting-point code (all `addons/visual-editor/src/Registry/`):
- `ControlProvider.php` (interface)
- `ControlRecord.php` (value object with normalization + safe list projection)
- `ControlRegistry.php` (list / register / dedupe / visibility filter / `getVisibleRecord`)

Starting-point test:
- `tests/phpunit/VisualEditorControlRegistryTest.php` (11 tests / 44 assertions)

Existing single-provider precedent to adapt in R3-B:
- `addons/visual-editor/src/Rest/Controllers/SharedGlobalFieldsController.php`
  (its `handle()` method walks the configured relationship/post_object fields;
  R3-B lifts the enumeration into a new `SharedGlobalsControlProvider` that returns
  `ControlRecord`s. The controller stays intact — R3-B does not remove it, so
  Shared Globals continues to work without regression.)

## Validation commands + current green baselines

Run from the plugin root
(`/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`):

- Full PHP suite: `vendor/bin/phpunit` → **831 tests, 6 inherited failures + 1
  pre-existing dirty-tree failure (`ProposalDiffContractTest::test_entity_drawer_exposes_stable_mode_and_field_selectors`
  — the working tree's `src/admin-app/index.js` drifted from the assertion; NOT
  in the Visual Editor namespace, NOT caused by R3, NOT to be fixed here per the
  "preserve dirty tree" constraint)**. The 6 inherited are outside the R3 area:
  BricksAddonPhase11, BricksAddonPhase7, CapabilityLandscape,
  ContentCollectorV2Phase29, ContentCollectorV2Phase32,
  ContentMigrationPhase37W0Settings. Some (CapabilityCli,
  ContentMigrationPhase4ImportExecutor, ContentCollectorV2Phase8) also flap
  in/out order-dependently and pass in isolation. Confirm any suspected new
  failure by running it with `--filter` in isolation.
- R3 subset: `vendor/bin/phpunit --filter "VisualEditorControlRegistry"` →
  **11 tests OK**.
- R3-BX subset: `vendor/bin/phpunit --filter "VisualEditorCuration"` →
  **21 tests / 76 assertions OK**.
- jsdom Media Manager (should be unaffected by R3-B; R3-C adds new jsdom cases):
  `node --test tests/visual-editor-media-manager-state.test.cjs` → **43 pass**.
- Media Manager PHP subset (also unaffected):
  `vendor/bin/phpunit --filter "VisualEditorMedia"` → **115 tests OK**.
- JS lint: `npm run lint:visual-editor-media-manager` (clean). NOTE:
  `api-client.js` is NOT in the lint set and has pre-existing `no-undef` on
  `DBVCVisualEditorBootstrap` — leave those alone.
- Agent docs (required when REST / hook / table / settings surfaces change):
  `composer agent-docs:refresh` then `composer agent-docs:check` →
  **54 curated / 436 discovered / 0 unmapped** (R3-BX added the curation admin
  submenu and three admin-ajax handlers, and shifted three visual-editor
  extension-point discovery-id hashes because bootstrap.php gained a new
  option; all four rotations are mapped in `docs/agents/manifest.json`). New `do_action` /
  `apply_filters` extension points, REST routes, and custom tables must be
  mapped in `docs/agents/manifest.json` (kept alphabetically sorted within each
  list). A per-file line-shift can cause discovery-id hashes to change — refresh
  will report the new ids, and you paste them into the manifest.

## Doc reconciliation checklist (do this after each slice, every time)

Under `docs/dropins/dbvc-visual-editor-brand-controls-guide/`:
- `releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md` — add a dated "Slice … checkpoint"
- `tracking/IMPLEMENTATION-TRACKER.md` — update the single R3 row
- `tracking/EVIDENCE-LOG.md` — next id is **E-093** (E-088 = R3-A; E-089 = R3-BX; E-090 = R3-BX post-landing polish — asset URL bugfix + filemtime versioning + column-width fix + client-side filter engine; E-091 = skip ACF `group` containers from the candidate list; E-092 = existing-support inventory doc)
- `tracking/DECISION-LOG.md` — next id is **D-062** (D-058 = desktop-only scope; D-059 = R3-BX curation tool policy; D-060 = closed, superseded; D-061 = R3 UI form = left-anchored tabbed drawer + new toolbar icon; existing Shared Globals popover untouched) if a decision is worth recording
- `tracking/RISK-REGISTER.md` — only if a risk changes

Plus:
- `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md` (canonical; boundary + next-tasks)
- `addons/visual-editor/CHANGELOG.md` (Unreleased, plain-language user-facing entry)
- Re-run agent-docs after any REST / hook / setting change
- Update THIS file's "Exact point we're at" section when the slice lands

## Approach for R3-B (Shared Globals compatibility provider)

Bounded scope:
1. New file `addons/visual-editor/src/Registry/Providers/SharedGlobalsControlProvider.php`
   implementing `ControlProvider`. Constructor takes the same dependencies
   `SharedGlobalFieldsController` uses to enumerate configured fields (get the
   configured names via `DBVC_Visual_Editor_Addon::get_shared_global_field_names()`
   or inject a small resolver). `getControls()` walks each configured name, calls
   `get_field_object($name, 'option', false, true)`, filters to
   `relationship`/`post_object`, and returns one `ControlRecord` per valid field
   with:
   - `id`: sanitized field name
   - `providerId`: `shared_globals` (interface returns it)
   - `label`: the ACF field label
   - `category`: `globals`
   - `group`: the ACF field group title
   - `ownerType`: `option`
   - `ownerSubtype`: `acf_options`
   - `fieldFamily`: `relationship` or `post_object` (whichever the ACF field is)
   - `status`: `available` when the field resolves + the current user could edit
     the shared-globals descriptor probe used by `SharedGlobalFieldsController::canManageSharedGlobalOptions`;
     `unavailable` otherwise
   - `source`: the sanitized field name + field key (opaque; used at open time by
     an R3-C descriptor factory that mirrors `SharedGlobalFieldsController::buildDescriptor`)
   - `meta`: `{badge: 'Shared Global'}` (matches the existing badge label)
   - `visibleTo`: closure that runs the same `canManageSharedGlobalOptions` probe
     for the CURRENT user at list time
2. Wire in `addons/visual-editor/src/Bootstrap/Addon.php`:
   instantiate a `ControlRegistry`, register the `SharedGlobalsControlProvider`
   inside the `Addon::register` MM-style gate (or a new
   `is_control_center_enabled` gate — see step 3), store the registry on the
   instance so R3-C can wire the REST route against it.
3. **Feature gate decision**: mirror the Media Manager pattern —
   `dbvc_visual_editor_control_center_enabled` option, default off.
   `DBVC_Visual_Editor_Addon::is_control_center_enabled()` helper. Record as a
   new decision `D-059` in `DECISION-LOG.md`.
4. New test `tests/phpunit/VisualEditorSharedGlobalsControlProviderTest.php`:
   - happy path: configured fields → `ControlRecord`s with the shape above
   - non-relationship/post_object fields skipped
   - unresolvable configured name skipped
   - visibility closure: subscriber sees nothing, administrator sees all
   - no regression when the provider is not registered (registry still lists empty)
5. Docs reconciliation per the checklist above. `SharedGlobalFieldsController`
   STAYS INTACT — do not remove the existing route.

R3-C guidance (after R3-B ships): two new REST routes under
`/dbvc/v1/visual-editor/control-center/`, both permission-gated exactly like the
Media Manager routes (auth + `canUseVisualEditor` + mode-active + capability).
The mockup drives the exact UI shape; do not invent shapes the mockup does not
cover. When live REST QA is needed, use the local auth env recipe from
`docs/development/local-agent-auth.md`; do NOT log in through the login form and
do NOT enter credentials into any field.

## Common failure signals to watch for

- **Agent docs check fails with "stale discovery snapshot"** — run
  `composer agent-docs:refresh` first, then `composer agent-docs:check`.
- **Agent docs check fails with "unknown discovery ID"** — a hash changed
  because a source file was reformatted. Grep the discovery snapshot for the
  hook / setting / route name, update the id in `docs/agents/manifest.json`.
- **Full suite reports `ProposalDiffContractTest` failing** — that's the pre-existing
  dirty-tree failure. Leave it alone. Confirm by running in isolation — it
  will still fail.
- **jsdom or media-manager PHP starts failing** — R3-B should not touch either.
  If they fail after R3-B, something wired wrong; roll back the wiring.
```

---

## What's in this session (short summary you can also share)

- R3-A landed 2026-08-23: registry + interface + value object + 11 tests (E-088).
- Desktop-only scope formalized 2026-08-23 as D-058, superseding the older
  D-036 "mobile deferred" clause. Real AT / VoiceOver removed as a required gate.
- D-049 real-browser QA closed on the REST side (E-086) and browser side
  (E-087) — only real Safari remains outstanding, and Claude for Chrome can't
  drive Safari.
- Mockup handoff pair written: `R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md`
  and `CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md` under
  `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/`.
