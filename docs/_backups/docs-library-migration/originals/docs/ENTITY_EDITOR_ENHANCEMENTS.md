# Entity Editor Enhancements

Last updated: 2026-04-07  
Current phase: `P5`  
Status legend: `OPEN` | `WIP` | `CLOSED` | `DEFERRED`

Implementation status: Initial raw JSON intake shipped on 2026-04-07. The phase breakdown below now serves as the landed record plus follow-up structure for this first tranche.

## Objective

Extend the existing DBVC Entity Editor with a raw JSON intake workflow that lets operators paste one DBVC entity payload and turn it into a live WordPress entity without manually staging a sync file first.

For this tranche, the enhancement is specifically:

- add a `New From Raw JSON` action inside Entity Editor
- accept DBVC post/CPT JSON and DBVC term JSON
- auto-detect the payload kind and subtype
- write the payload into the canonical sync tree
- create the WordPress entity when no local match exists
- optionally stage or update an existing match through safe, explicit operator choices

This should remain an Entity Editor feature, not a new addon or separate admin page.

## Primary UX Recommendation

For v1, keep the workflow inside the existing Entity Editor surface at `src/admin-entity-editor/index.js`.

Recommended entry point:

- add a toolbar button near the existing transfer-packet actions
- preferred label: `New From Raw JSON`
- avoid `New Post from Raw JSON` because the same flow should also support taxonomy terms

Recommended v1 modal structure:

1. Large raw JSON textarea
2. Mode/config controls
3. Preflight summary panel
4. Primary action button
5. Success handoff into the normal Entity Editor file modal

Recommended v1 controls:

- `Mode`
  - `Create only (recommended)`
  - `Create or Update Matched`
  - `Stage JSON Only`
- `Open in editor after success`
  - default `on`

Recommended v1 behavior:

- operator pastes JSON
- clicks `Preview`
- system reports detected kind, subtype, target sync path, and whether the action will create, update, or block
- operator confirms
- system writes the sync JSON, performs the selected action, refreshes the Entity Editor index, then opens the resulting file in the existing editor flow

Keep destructive full replace out of the raw-intake v1 modal. If the operator wants a full replace, the safe pattern is:

1. complete raw intake
2. auto-open the resulting file in Entity Editor
3. use the existing `Save + Full Replace` flow with its typed confirmation

## Scope

Include in v1:

- single-payload paste intake for DBVC post/CPT JSON
- single-payload paste intake for DBVC term JSON
- post-vs-term auto-detection
- subtype detection from `post_type` or `taxonomy`
- target sync-path preview using current DBVC filename rules
- create-new behavior for unmatched payloads
- optional matched update path when operator explicitly selects it
- reuse of existing Entity Editor notices, permissions, and success handoff patterns
- audit logging and actionable error messaging

Do not include in v1:

- attachments/media entities
- menus/nav menu items
- options/settings payloads
- arbitrary non-DBVC JSON translation
- multi-payload batch paste
- AI-assisted schema repair or transformation
- a new top-level admin page or submenu dedicated to this one feature
- destructive replace directly inside the raw-intake modal

## Phase 0 Review Findings

Status: `CLOSED`

### Files reviewed

- `README.md`
- `docs/ROADMAP.md`
- `docs/ENTITY_EDITOR_HANDOFF.md`
- `docs/ENTITY_EDITOR_CHECKLIST.md`
- `docs/CROSS_SITE_ENTITY_PACKET_IMPLEMENTATION_GUIDE.md`
- `src/admin-entity-editor/index.js`
- `admin/class-admin-app.php`
- `includes/class-entity-editor-indexer.php`
- `includes/class-import-router.php`
- `includes/import-scenarios/post.php`
- `includes/import-scenarios/term.php`
- `includes/class-sync-posts.php`
- `includes/class-sync-taxonomies.php`

### Confirmed reuse points

1. Entity Editor already has an established toolbar/action area that can host a new raw-intake button.
2. Entity Editor already uses modal-based JSON editing, notices, success states, and post-action refresh patterns.
3. Entity Editor already exposes dedicated REST endpoints under `/entity-editor/*` and uses `can_manage()` permission checks.
4. Entity Editor already detects post vs term payloads and has mature JSON validation/error handling.
5. The upload router already has usable post-vs-term JSON-shape detection and canonical filename builders.
6. `DBVC_Sync_Posts::import_post_from_json(...)` already supports creation when a matching post is missing and site settings allow creation.
7. Term import logic already exists, but today it is only exposed through the internal/protected single-term file import path.

### Important gaps and constraints

1. The current Entity Editor save/import endpoints are file-oriented.
   - They expect an existing relative path plus a lock token.
   - That is the wrong contract for net-new raw intake.
2. The current partial/full replace flows block when no local entity match exists.
   - `DBVC_Entity_Editor_Indexer::resolve_single_candidate()` returns a no-match error for unmatched payloads.
3. Post creation is gated by existing DBVC settings.
   - Raw intake must either honor those settings or surface them clearly in the preflight response.
4. There is no public single-term import helper for this feature to call directly.
   - The current logic lives inside `DBVC_Sync_Taxonomies::import_term_from_file(...)`.
5. File collisions need an explicit policy.
   - A pasted payload may map to an already-existing sync filename even when the entity itself is new or ambiguous.

### Phase 0 decision

Implement this as a focused Entity Editor enhancement with a new raw-intake backend service and dedicated REST endpoints.

Do not force the feature through the existing lock-based save/import endpoints.

## Recommended Implementation Shape

### Backend shape

Add a dedicated raw-intake service instead of growing `DBVC_Entity_Editor_Indexer` into another large mixed-responsibility class.

Recommended placement:

- `includes/Dbvc/EntityEditor/RawJsonIntakeService.php`

Recommended responsibilities:

- decode and validate raw JSON
- detect entity kind and subtype
- calculate target sync path
- inspect file collision state
- inspect live entity match state
- return preview/preflight details
- commit the requested action
- log the final result

### REST surface

Add two new REST endpoints in `admin/class-admin-app.php`:

- `POST /dbvc/v1/entity-editor/raw-intake/preview`
- `POST /dbvc/v1/entity-editor/raw-intake/commit`

Recommended request fields:

- `content`
- `mode`
- `open_after_success`
- `force_overwrite_file` (optional, only if explicitly supported)

Recommended preview response fields:

- `entity_kind`
- `subtype`
- `title`
- `slug`
- `uid`
- `target_relative_path`
- `target_absolute_path`
- `match`
- `file_collision`
- `available_actions`
- `warnings`
- `blocking`

Recommended commit response fields:

- `action`
- `entity_kind`
- `subtype`
- `relative_path`
- `matched`
- `created`
- `import_result`
- `warnings`

### Commit flow recommendation

Recommended v1 commit flow:

1. Decode and validate raw JSON.
2. Detect `post` or `term`.
3. Validate subtype (`post_type` or `taxonomy`) exists locally.
4. Determine canonical filename using the existing router helpers.
5. Inspect:
   - existing sync file at target path
   - existing live entity match by UID and slug/subtype
6. Enforce operator-selected mode:
   - `create_only`
   - `create_or_update_matched`
   - `stage_only`
7. Write canonical JSON into the sync tree.
8. Perform import/create if mode requires it.
9. Refresh/return the resulting relative path so the normal Entity Editor modal can open it.
10. Log action details.

## Progress Tracker

| Phase | Status | Goal |
|---|---|---|
| `P0` | `CLOSED` | Review current Entity Editor architecture and lock the implementation boundary |
| `P1` | `CLOSED` | UX contract, REST contract, and service boundaries |
| `P2` | `CLOSED` | Detection and preflight backend |
| `P3` | `CLOSED` | Commit flow: sync write plus post/term create or matched update |
| `P4` | `CLOSED` | Entity Editor UI/modal wiring |
| `P5` | `CLOSED` | Tests, QA, docs, and rollout closure |
| `P6` | `DEFERRED` | Advanced follow-ups beyond v1 |

Update this table at the end of each landed tranche.

## Implementation Phases

## P1. UX Contract + REST Contract

Status: `CLOSED`

### Outcome

Define the operator flow and API contract before writing behavior so the feature does not become an ad hoc special case inside the Entity Editor page.

### Tasks

- Define the final operator-facing button and modal labels.
- Lock the v1 mode set:
  - `Create only`
  - `Create or Update Matched`
  - `Stage JSON Only`
- Decide whether preview happens:
  - explicitly via button click
  - automatically on paste/debounce
- Add the two new REST routes in `admin/class-admin-app.php`.
- Define request/response schemas for preview and commit.
- Decide the success handoff behavior:
  - refresh index
  - auto-open the resulting file
  - return WP edit link when available

### Checklist

- [ ] Button label and modal title are finalized
- [ ] Preview route contract is documented
- [ ] Commit route contract is documented
- [ ] Success handoff behavior is finalized

### Recommended decisions

- Prefer explicit `Preview` instead of running preflight on every keystroke.
- Prefer `New From Raw JSON` over `New Post from Raw JSON`.
- Keep destructive full replace out of the raw-intake modal.

## P2. Detection + Preflight Service

Status: `CLOSED`

### Outcome

Add a single backend service that can inspect pasted payloads and tell the UI exactly what DBVC thinks will happen before any write/import occurs.

### Tasks

- Create the raw-intake service class.
- Normalize and decode incoming JSON.
- Detect entity kind:
  - post/CPT
  - taxonomy term
- Validate subtype existence:
  - `post_type_exists()`
  - `taxonomy_exists()`
- Reuse `DBVC_Import_Router::determine_post_filename(...)` and `DBVC_Import_Router::determine_term_filename(...)` for target paths.
- Inspect live entity matches using:
  - UID
  - slug + subtype
- Inspect target sync file collisions.
- Surface post-creation gate warnings based on current DBVC settings.
- Return structured warnings and blocking reasons.

### Sub-steps

- `P2-T1` Shared payload normalization
- `P2-T2` Kind + subtype detection
- `P2-T3` Canonical path resolution
- `P2-T4` Live entity match detection
- `P2-T5` File collision detection
- `P2-T6` Warning/blocking summary assembly

### Checklist

- [ ] Invalid JSON is blocked with clear messaging
- [ ] Unsupported payload shapes are blocked
- [ ] Missing post types or taxonomies are reported cleanly
- [ ] Preview clearly reports `create`, `update`, `stage_only`, or `blocked`

## P3. Commit Flow: Sync Write + Import/Create

Status: `CLOSED`

### Outcome

Allow the previewed raw payload to be staged safely into the sync tree and then committed into WordPress using the smallest viable set of reusable DBVC engines.

### Tasks

- Write the JSON to the canonical sync path.
- Reuse backup/atomic-write patterns already established by Entity Editor.
- For posts:
  - call `DBVC_Sync_Posts::import_post_from_json(...)`
  - preserve DBVC UID/history behavior
  - respect existing new-post creation gates unless an explicit product decision changes that
- For terms:
  - extract or add a public single-term import helper derived from `DBVC_Sync_Taxonomies::import_term_from_file(...)`
  - preserve UID/history behavior
- For matched-update mode:
  - prefer non-destructive import semantics for v1
  - do not run destructive full replace from this modal
- Return the resulting relative path and entity metadata to the UI.
- Log the final action and counts.

### Sub-steps

- `P3-T1` Sync write helper for raw intake
- `P3-T2` Post create/update path
- `P3-T3` Public term create/update helper extraction
- `P3-T4` Commit-result payload + logging

### Checklist

- [ ] `create_only` blocks when a live match already exists
- [ ] `create_or_update_matched` uses non-destructive semantics
- [ ] Posts can be created from valid raw JSON when DBVC creation settings allow it
- [ ] Terms can be created from valid raw JSON through a public helper
- [ ] The final sync file path matches current DBVC naming rules

### Key risk

The term create path currently needs extraction/refactoring before it can be called safely from a new REST workflow.

## P4. Entity Editor UI Wiring

Status: `CLOSED`

### Outcome

Expose the feature through the existing Entity Editor UX with clear state transitions, preflight clarity, and a clean handoff back into the normal file editor.

### Tasks

- Add a new toolbar button in `src/admin-entity-editor/index.js`.
- Add modal state for:
  - open/close
  - raw draft content
  - mode
  - preview result
  - commit busy/error/success
- Add `Preview` and `Commit` actions wired to the new REST endpoints.
- Render preflight details:
  - kind
  - subtype
  - title/name
  - slug
  - target sync file
  - live match
  - warnings
  - blocking reasons
- On success:
  - refresh the index
  - auto-open the created/staged file when enabled
- Reuse current notice and error-display patterns from the editor flow.

### Sub-steps

- `P4-T1` Toolbar button and modal skeleton
- `P4-T2` Preview request/response handling
- `P4-T3` Commit handling and success notices
- `P4-T4` Auto-open resulting file in existing editor

### Checklist

- [ ] Modal is reachable from Entity Editor toolbar
- [ ] Preview state is understandable before commit
- [ ] Blocking errors do not partially write files
- [ ] Success path opens the resulting file in the normal editor flow

## P5. Tests, QA, Docs, Rollout

Status: `CLOSED`

### Outcome

Close the feature with test coverage, operator QA, and doc alignment so the implementation can ship without relying on tribal knowledge.

### Tasks

- Add PHPUnit coverage for:
  - preview route permissions
  - invalid JSON
  - post payload detection
  - term payload detection
  - file collision blocking
  - create-only blocking on existing matches
  - successful post creation
  - successful term creation
- Add or extend manual QA docs for the new modal flow.
- Rebuild the admin assets after UI changes.
- Update:
  - `docs/ROADMAP.md`
  - `docs/ENTITY_EDITOR_USAGE.md`
  - this doc with landed tranche notes
- Record any settings dependencies that affect creation behavior.

### Suggested test file targets

- `tests/phpunit/EntityEditorRawIntakeRoutesTest.php`
- `tests/phpunit/EntityEditorRawIntakeServiceTest.php`

### Manual QA matrix

- Paste valid post JSON for a new post
- Paste valid term JSON for a new term
- Paste valid JSON that matches an existing entity
- Paste malformed JSON
- Paste unsupported JSON (options/media/menu payload)
- Paste JSON for a missing local post type or taxonomy
- Attempt commit as a user without `manage_options`

### Checklist

- [ ] PHPUnit coverage exists for preview and commit routes
- [ ] Manual QA scenarios are documented and run
- [ ] Asset rebuild is complete
- [ ] Docs are aligned with shipped behavior

## P6. Deferred Follow-Ups

Status: `DEFERRED`

Keep these out of the initial raw-intake tranche unless the implementation reveals they are required:

- full replace directly from raw-intake modal
- batch/multi-payload raw intake
- CodeMirror JSON linting/paste ergonomics
- AI-assisted payload repair or schema translation
- richer collision-resolution UI inside the intake modal

## Proposed File Touch Surface

Expected files for the first implementation tranche:

- `src/admin-entity-editor/index.js`
- `build/admin-app.js`
- `build/admin-app.asset.php`
- `admin/class-admin-app.php`
- `includes/class-entity-editor-indexer.php`
- `includes/class-sync-posts.php`
- `includes/class-sync-taxonomies.php`
- `includes/class-import-router.php` (only if shared helpers need light extraction)
- `includes/Dbvc/EntityEditor/RawJsonIntakeService.php`
- `tests/phpunit/EntityEditorRawIntakeRoutesTest.php`
- `tests/phpunit/EntityEditorRawIntakeServiceTest.php`
- `docs/ENTITY_EDITOR_ENHANCEMENTS.md`
- `docs/ENTITY_EDITOR_USAGE.md`

## Final Recommendation

Implement this as a focused Entity Editor enhancement with a new raw-intake service and a small modal-driven UI, not as a separate addon.

That keeps the operator workflow coherent, reuses the strongest existing DBVC patterns, and avoids duplicating permissions, admin navigation, and JSON-import behavior in a second surface.
