# AGENTS.md

## Mission

You are working inside the DBVC plugin.

Current objective:
Continue implementation of the **DBVC Visual Editor** add-on in `addons/visual-editor/`.

The Visual Editor lets an authorized frontend user inspect and edit Bricks-rendered dynamic data in place while preserving source-owner accuracy, save-contract safety, and clear UI treatment for current, shared, related, loop-owned, and inspect-only sources.

Work in **Spartan Mode**:
minimal waste, minimal drift, minimal assumptions, maximum clarity.

---

## Prime Directive

Do not switch to Bricks Portability, Content Collector, AI packages, or other DBVC modules unless the user explicitly asks for that module.

For broad prompts like "continue", "next logical steps", "resume implementation", or "what is open", assume the scope is the Visual Editor add-on and its current implementation guide.

If task instructions conflict, pause and ask for scope clarification before editing unrelated modules.

Follow this order before coding:

1. Read this file first.
2. Read the Visual Editor implementation docs listed below.
3. Inspect the current Visual Editor code paths before changing behavior.
4. Reuse existing DBVC and Visual Editor patterns for bootstrap, assets, Bricks instrumentation, descriptor sessions, resolver registration, REST routes, mutation contracts, journaling, docs, and QA notes.
5. If docs and code differ, prefer the real code pattern unless the task explicitly says otherwise.

---

## Current Focus

Only work on the Visual Editor add-on unless the user explicitly broadens scope.

Primary working directory:

- `addons/visual-editor/`

Primary docs to keep current:

- `addons/visual-editor/README.md`
- `addons/visual-editor/ARCHITECTURE.md`
- `addons/visual-editor/CHANGELOG.md`
- `addons/visual-editor/docs/README.md`
- `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF_2026_05_24.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_FIELD_INDEX_PLAN.md`
- `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md`
- `addons/visual-editor/docs/knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md`
- `addons/visual-editor/docs/qa/QA_CHECKLIST.md`
- `addons/visual-editor/docs/qa/TEST_LOG.md`

---

## Active Implementation State

Current major branch:
Visual Editor support for Bricks-rendered dynamic data across singular pages, query loops, native ACF loops, related owners, shared option fields, current-owner connected collections, and archive entry points.

Recent stable work to preserve:

- shared hover/focus badge model with differentiated labels and dashed border colors
- draggable, closable, session-persistent `dbvc-ve-panel`
- smart panel viewport fitting and Media Library-safe outside-click behavior
- longer Visual Editor session TTL plus focus/visibility heartbeat refresh
- lazy descriptor hydration and bounded viewport-aware prefetch
- native ACF repeater, flexible, relationship, post-object, taxonomy loop provenance
- nested repeater-in-repeater and mixed repeater/flexible path handling
- ACF image, background-image, and gallery support where save contracts are proven stable
- additive gallery management with Media Library append, replace, remove, move buttons, desktop drag/drop sorting, no-reload `Save`, and `Save and Reload`
- current-owner connected-items container markers for ACF relationship and post_object query roots
- derived Bricks Query Editor connected-items editors where final query IDs prove one current-owner or exact shared-option source
- current-owner empty derived query-loop badges that let users add the first connected item when the source field is proven empty
- exact shared-option fallback collection editors with explicit shared acknowledgement and current-page seed/undo controls
- post-owned linked-term collection resolver and native Bricks `post-taxonomy` element handling where one owner post and one taxonomy are proven
- archive-aware page context with direct term/archive-option saves only where owner contracts are proven
- Toolbar 2.0 shell, status/review popover, Go To Object navigation, configured Shared Globals popover, Visual Editor settings/exclusions, and grouped Review Fields index

Current open/next areas:

- primary next recommended slice: native post-term collection empty-loop support, using the existing `post_terms_collection` resolver and save contract only when one owner post and one taxonomy are proven
- close browser QA for current no-reload media saves: rendered image markers, background-image markers, and the `xxrpfg` gallery on `/vertical/websites-for-contractors/`
- close browser QA for native post-term collection and native `post-taxonomy` badges across repeated cards
- run live-save smoke tests for nested grouped descendants inside supported repeater/flexible/related-owner paths, then verify same-source fields do not cross-sync incorrectly after save
- continue archive work only where concrete owner contracts are proven: current taxonomy archive term descendants and option-backed archive fields with explicit shared-option warnings
- keep collection fields, galleries, and non-concrete archive loop owners inspect-only unless a dedicated contract exists
- defer broad relationship collection mutation expansion, shared connected-item collections, loop-owned non-post connected-item collections, taxonomy collection mutation, repeater row insert/remove/reorder, and flexible row insert/remove/reorder until current collection and grouped descendant contracts are stable

Paused/WIP context to preserve:

- shared non-current post flexible descendants through `shared_flexible_layout`
- direct/repeater/flexible gallery collection replacement and no-reload DOM patch browser checks
- nested grouped descendants inside supported repeater/flexible/related-owner paths
- empty/condition-skipped image or gallery elements whose source already has a value
- native post-term collection empty-loop badge surfacing and save UX
- Toolbar 2.0 Shared Globals, settings/exclusions, and Review Fields browser QA
- shared connected-item collections
- loop-owned non-post connected-item collections
- taxonomy collection mutation
- durable row/layout insert, remove, and reorder branches

---

## Non-Negotiable Technical Rules

### 1. Source ownership first
Every descriptor must make the actual editable source clear:

- current post/page/CPT object
- queried related post
- queried related term
- queried user
- ACF options/shared field
- current taxonomy archive term
- CPT archive option-backed field
- loop-owned field
- inspect-only/derived source

Do not make a marker writable if the backend owner/path is ambiguous.

### 2. Render verification before editability
Do not infer editability from DOM text alone.

Writable descriptors must be backed by Bricks dynamic data inspection, resolver classification, render-value verification where applicable, and an explicit mutation contract.

### 3. Inspect-only before writable
For new advanced source shapes, ship inspect-only markers first unless a safe read/write/save contract already exists.

### 4. Save contracts must be explicit
Each writable descriptor must identify:

- resolver
- owner entity
- field name/key
- nested group/repeater/flexible path when applicable
- mutation type
- acknowledgement requirement for non-current/shared sources
- reload-after-save behavior when needed

### 5. No broad fallback guessing
Avoid selector-only, text-guessing, or last-known-owner fallbacks that could write to the wrong ACF row, term, option, post, or related object.

### 6. Keep performance bounded
Keep bootstrap light. Prefer public marker maps, on-demand descriptor hydration, in-flight request reuse, active-marker prefetch, and bounded viewport warmup over eager full-page descriptor hydration.

### 7. Builder mode guard stays intact
The Visual Editor must not load or mutate state inside Bricks Builder mode.

### 8. Preserve existing behavior
Do not regress currently working singular fields, related post/term fields, shared options fields, repeater/flexible fields, image/media fields, gallery paths, connected-items collection markers, or panel UX.

---

## Build Order For New Visual Editor Slices

Before writing implementation code for a new slice, produce or update a concise plan covering:

1. the exact source shape being enabled
2. current code paths involved
3. descriptor metadata requirements
4. resolver classification changes
5. mutation contract requirements
6. UI/badge/panel impact
7. save and rollback risk
8. live template/page examples for testing
9. docs to update
10. validation to run

Then implement in narrow phases:

1. inspect and document current behavior
2. add or refine owner/path detection
3. surface inspect-only markers if needed
4. add resolver and save-contract support
5. wire UI labels/warnings/acknowledgements
6. validate read path
7. validate save path only when safe
8. update docs and QA notes

---

## WordPress Standards

- Sanitize input.
- Escape output.
- Enforce capabilities.
- Verify nonces.
- Keep REST routes permission-aware.
- Treat frontend session tokens and descriptor payloads as untrusted input.
- Do not expose editable metadata to unauthorized users.
- Do not perform destructive mutations without a specific save contract.

---

## LocalWP Safety Boundary

Treat `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges` and `dbvc-codexchanges.local` as the only allowed LocalWP environment unless the user explicitly expands scope.

When Visual Editor work needs Bricks template context, inspect the current database/runtime or the DBVC synced child-theme template exports under:

- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/themes/vertical/sync/db-version-control-main/bricks_template/`

Useful current fixture:

- `bricks_template-flo-verticals-single-26763.json` contains gallery element `xxrpfg`; the root-level image-gallery block is around line `38317`, and its dynamic source `{acf_gallery_section_gallery}` is around line `38323`.

Do not touch:

- other LocalWP sites
- other LocalWP databases
- shared LocalWP infrastructure
- LocalWP app state

Use disposable fixture data for destructive QA.

---

## Files And Structure

Place code in the most logical existing Visual Editor add-on location.

Prefer existing module boundaries:

- bootstrap/add-on wiring in `src/Bootstrap/`
- runtime guards and page context in `src/Context/`
- Bricks inspection/instrumentation in `src/Bricks/`
- descriptor registry contracts in `src/Registry/`
- source resolvers in `src/Resolvers/`
- REST controllers/builders in `src/Rest/`
- mutation contracts and writes in `src/Save/`
- frontend overlay assets in `assets/js/` and `assets/css/`
- durable journal work in `src/Journal/` only when needed

Use clear names.
Keep files small.
Keep responsibilities narrow.
Avoid duplicating resolver or mutation logic.

---

## Validation Standard

Before reporting completion, validate what changed with the narrowest useful checks.

Use as applicable:

- `php -l` for touched PHP files
- targeted PHPUnit if coverage exists
- `git diff --check` for touched files
- focused browser/Playwright smoke checks on `dbvc-codexchanges.local` when frontend behavior changed
- runtime PHP probes only when they are read-only or explicitly safe
- manual user testing notes when browser automation cannot safely cover the case

For Visual Editor functionality, explicitly report whether each relevant path was tested:

- marker surfacing
- badge label/source classification
- panel load
- descriptor payload
- save request
- post-save rendered value
- reload-after-save behavior
- session expiry/refresh behavior if touched
- builder mode guard if touched

Do not claim tested unless actually tested.

---

## When Finished

Report back with:

1. what changed
2. files touched
3. validation performed
4. tradeoffs / blockers / assumptions
5. next steps

Always include next steps at the end of each implementation turn.

---

## Avoid

- switching to Bricks Portability or other DBVC modules because old instructions mention them
- broad refactors unrelated to the active Visual Editor slice
- giant controller classes
- hidden side effects
- direct raw ACF/meta mutations scattered across resolvers
- duplicate path traversal logic
- brittle one-off UI state handling
- silent save failure
- eager full-page descriptor hydration
- making inspect-only markers writable without a contract
- treating archive pages as fake singular posts
- expanding into unrelated DBVC modules

---

## Spartan Reminder

Be sharp.
Be modular.
Be reversible.
Be honest.

Inspect first.
Verify ownership first.
Surface inspect-only first when uncertain.
Add save contracts only when safe.
Document what changed.
Always include next steps.
