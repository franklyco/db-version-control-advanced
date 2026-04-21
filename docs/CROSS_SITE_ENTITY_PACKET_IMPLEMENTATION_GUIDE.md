# Cross-Site Entity Packet Implementation Guide

Last updated: 2026-04-06  
Current phase: `P7`  
Status legend: `OPEN` | `WIP` | `CLOSED` | `DEFERRED`

## Objective

Add a safe DBVC workflow that lets operators select one or many post/CPT or term entities on Site A, package them as a transfer packet, and bring that packet into Site B as a reviewable import that preserves:

- full entity JSON payloads
- taxonomy dependencies
- media references and bundle transport
- entity identity (`vf_object_uid`) and history
- duplicate detection, review gating, resolver decisions, and audit logs

The implementation should feel like "copy selected entities from one site, paste/upload them into another", but the transport should remain DBVC-native and proposal-compatible so the existing review/apply pipeline does the dangerous work.

## Primary UX Recommendation

For v1, the feature should live in the existing Entity Editor surface, not as a new per-row table column and not as a new dedicated submenu page.

- Source-side entry point:
  - add bulk actions to the existing Entity Editor index
  - preferred labels: `Create Transfer Packet` and `Download Packet`
  - optional operator-facing copy language can say "Copy selected", but the real transport should be a packet ZIP
- Destination-side entry point:
  - reuse the existing Proposal Review upload/import flow
  - surface transfer packets as a proposal origin/type, not as a second importer

Reasoning:

- Entity Editor already has bulk selection and bulk download affordances for selected entity rows.
- Proposal ZIP upload, resolver review, new-entity gating, snapshots, and apply logging already exist.
- A media-inclusive clipboard payload is not practical for v1. ZIP-based transport is the safe path.

## Scope

Include in v1:

- source-side selection of one or many Entity Editor rows
- proposal-compatible packet generation for a subset of entities
- dependency expansion for:
  - post taxonomies
  - parent terms of included terms
  - attachment/media references detected in post content and meta
- destination-side upload through the existing proposal intake path
- destination-side review/apply through the existing proposal UI and CLI flow
- preflight warnings for missing post types and taxonomies on the destination site

Do not include in v1:

- direct site-to-site push/pull transport
- true clipboard-only transport for media-inclusive packets
- a second import engine separate from `import_proposal` / `import_backup`
- a new top-level or dedicated submenu unless the workflow later outgrows Entity Editor
- automatic remapping of arbitrary post-to-post ACF relationship fields beyond the selected packet set

## Phase 0 Review Findings

Status: `CLOSED`

### Files reviewed

- `admin/admin-menu.php`
- `admin/class-admin-app.php`
- `admin/class-entity-editor-app.php`
- `includes/class-entity-editor-indexer.php`
- `includes/class-backup-manager.php`
- `includes/class-sync-posts.php`
- `includes/class-sync-taxonomies.php`
- `includes/class-import-router.php`
- `includes/import-scenarios/post.php`
- `includes/import-scenarios/term.php`
- `includes/class-media-sync.php`
- `includes/Dbvc/Media/Resolver.php`
- `includes/Dbvc/Media/BundleManager.php`
- `includes/Dbvc/Media/Reconciler.php`
- `commands/class-wp-cli-commands.php`
- `src/admin-entity-editor/index.js`
- `docs/ROADMAP.md`
- `docs/DBVC_ENGINE_INVENTORY.md`
- `docs/terms.md`
- `docs/legacy-upload-immediate-import-plan.md`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

### Confirmed reuse points

1. Entity Editor already supports bulk row selection and bulk ZIP download of selected JSON files.
2. Proposal ZIP ingestion already exists through `DBVC_Admin_App::import_proposal_from_zip()`.
3. Destination-side review/apply already exists through:
   - `DBVC_Admin_App::get_proposal_entities()`
   - `DBVC_Admin_App::get_proposal_resolver()`
   - `DBVC_Admin_App::apply_proposal()`
   - `DBVC_Sync_Posts::import_backup()`
4. Manifest generation already produces:
   - `manifest.json`
   - `entities.jsonl`
   - `media_index`
   - optional bundled media metadata
5. Attachment/media reuse and download logic already exists through the resolver and bundle/reconcile classes.
6. WP-CLI already supports proposal upload/apply and resolver rule management.

### Important gaps and constraints

1. There is no existing "selected entities to proposal packet" builder.
   - Current backup creation is whole-sync oriented.
2. `prepare_post_export()` is private and term export is protected.
   - A subset packet builder cannot safely reuse current export code without extracting a narrow public/helper path.
3. The upload router is not enough for this feature.
   - It stages JSON into sync, but it does not provide proposal review semantics, snapshot capture, or packet-oriented metadata.
4. Term media parity is not actually complete in the current manifest builder.
   - `docs/terms.md` describes term `media_refs` parity, but `DBVC_Backup_Manager::generate_manifest()` currently only collects post media refs and sets empty term `media_refs`.
5. Generic post-to-post relationship remapping is not complete.
   - There is commented/WIP relationship remap code in `DBVC_Sync_Posts`, but it is not a finished dependency engine.

### Phase 0 decision

Build this feature as a proposal-compatible subset packet workflow:

- source side:
  - existing Entity Editor bulk action
  - new subset packet builder
- destination side:
  - existing proposal upload + review + apply path

Do not build a second direct-import engine.

## Recommended Implementation Shape

### Packet format

Use the existing proposal bundle shape as the transfer packet shape.

- Keep `manifest.json` compatible with the current importer.
- Keep `entities.jsonl` for review/diff support.
- Keep `media_index` and optional bundled media.
- Add only additive metadata fields so existing upload/apply paths stay compatible.

Recommended additive manifest fields:

- `origin.type = "entity_transfer"`
- `origin.source_surface = "entity_editor"`
- `origin.generated_from_site = <home_url>`
- `selection.summary`
- `requirements.post_types`
- `requirements.taxonomies`
- `requirements.notes`

This avoids a schema fork and lets current upload/apply code ignore unknown keys.

### Source-side build flow

1. Normalize selected Entity Editor rows into a packet selection.
2. Resolve each selected row to:
   - a live local WP entity when possible
   - a safe JSON file fallback when no local match exists
3. Export fresh JSON into a dedicated packet staging directory.
4. Expand dependencies:
   - selected posts -> referenced taxonomy terms
   - selected terms -> ancestor parents
   - selected posts/terms -> media references for resolver/bundle generation
5. Run manifest generation against the staging directory.
6. Patch the manifest with transfer-specific metadata.
7. ZIP the staging directory and stream/download the packet.

### Destination-side flow

1. Upload the packet through the existing proposal upload route.
2. Let current proposal review surfaces show:
   - new entities
   - collisions
   - resolver conflicts
   - review selections
3. Apply through the existing proposal apply route and importer.

### Storage recommendation

Do not store outbound packets in the same base directory that inbound proposals use.

Reason:

- `get_proposals()` reads staged proposal directories from the current backup/proposal storage path.
- Mixing outbound packet workspaces into that same directory would pollute the review list and confuse operators.

Recommendation:

- use a dedicated outbound packet workspace under uploads/sync, for example:
  - `wp-content/uploads/sync/dbvc-transfer-packets/`
- harden that directory with the same security pattern already used for sync/backup folders
- allow packet ZIP streaming directly after build so the source site does not need long-lived packet storage unless explicitly desired

## Progress Tracker

| Phase | Status | Goal |
|---|---|---|
| `P0` | `CLOSED` | Repo review, integration analysis, and architecture boundary |
| `P1` | `CLOSED` | Packet contract, staging rules, and preflight metadata |
| `P2` | `CLOSED` | Targeted export helpers and packet builder services |
| `P3` | `CLOSED` | Dependency expansion for terms, parents, and media |
| `P4` | `CLOSED` | Entity Editor bulk action and packet generation UX |
| `P5` | `CLOSED` | Destination intake and proposal-review integration |
| `P6` | `DEFERRED` | WP-CLI parity and operational tooling |
| `P7` | `WIP` | Validation, QA, docs, and rollout closure |

Update this table at the end of each landed tranche. Change only the status and add the matching completion notes under the relevant phase.

## Implementation Phases

## P1. Packet Contract + Preflight Metadata

Status: `CLOSED`

### 2026-04-06 tranche notes

- Added additive transfer-packet metadata under `origin`, `selection`, and `requirements`.
- Locked outbound packet staging to a dedicated `uploads/sync/dbvc-transfer-packets/` workspace.
- Kept the packet contract proposal-compatible so destination sites can continue using the current proposal upload/apply flow unchanged.

### Outcome

Define the transfer packet contract before writing build logic so the feature does not turn into ad hoc ZIP assembly.

### Tasks

- Define a transfer-packet manifest extension that remains proposal-compatible.
- Define packet-level `origin` and `requirements` fields.
- Define a stable packet ID format.
- Define staging-root rules separate from inbound proposal storage.
- Define destination preflight warnings for:
  - missing post types
  - missing taxonomies
  - unsupported packet contents
- Decide v1 handling for stale/orphaned Entity Editor JSON rows:
  - preferred: export from live entity when matched
  - fallback: copy JSON file only when no live entity can be resolved

### Checklist

- [x] Transfer packet metadata fields documented.
- [x] Packet ID format documented.
- [x] Separate outbound storage root documented.
- [x] Destination preflight warning contract documented.
- [x] Fallback policy for stale/orphaned JSON rows documented.

### Likely touchpoints

- `docs/CROSS_SITE_ENTITY_PACKET_IMPLEMENTATION_GUIDE.md`
- `admin/class-admin-app.php`
- `admin/class-entity-editor-app.php`
- `src/admin-entity-editor/index.js`

### Risks

- If packet metadata requires a schema fork, destination upload/apply complexity rises immediately.
- Keeping the contract additive is the safer route.

## P2. Targeted Export Helpers + Packet Builder Services

Status: `CLOSED`

### 2026-04-06 tranche notes

- Added `includes/Dbvc/Transfer/EntityPacketBuilder.php` to normalize selection, stage entities, generate a manifest, and build the ZIP packet.
- Added staged-export helpers for posts and terms so packet builds do not need to hijack whole-sync export paths.
- Added manifest post-processing and ZIP assembly inside the packet builder.

### Outcome

Add a narrow service layer that can build a proposal-compatible subset packet without abusing whole-sync export paths.

### Tasks

- Add modular packet-builder classes under a focused namespace, for example:
  - `includes/Dbvc/Transfer/PacketBuilder.php`
  - `includes/Dbvc/Transfer/SelectionResolver.php`
  - `includes/Dbvc/Transfer/StagingWorkspace.php`
- Extract reusable export helpers so selected entities can be exported into an arbitrary staging directory.
  - posts: do not rely on private `prepare_post_export()` remaining inaccessible
  - terms: do not rely on protected `export_term()` from outside its class
- Build a safe selection normalizer from Entity Editor relative paths to:
  - live WP entity
  - safe file fallback
- Add manifest post-processing to stamp transfer metadata after `generate_manifest()`.
- Add ZIP assembly for the packet staging workspace.

### Checklist

- [x] Packet builder classes added.
- [x] Selected post export helper can write to a staging path.
- [x] Selected term export helper can write to a staging path.
- [x] Packet builder can assemble a subset workspace without touching the main sync tree.
- [x] Packet ZIP generation works from the staging workspace.

### Likely touchpoints

- `includes/class-sync-posts.php`
- `includes/class-sync-taxonomies.php`
- `includes/class-backup-manager.php`
- `includes/Dbvc/Transfer/*`

### Risks

- Reusing global path filters to redirect exports into staging will be brittle.
- Prefer explicit helper extraction over temporary global filter hacks.

## P3. Dependency Expansion: Terms, Parents, and Media

Status: `CLOSED`

### 2026-04-06 tranche notes

- Selected post packets now expand taxonomy term dependencies from `tax_input`.
- Selected and dependent terms now pull in parent terms recursively.
- Manifest generation now collects term media refs from term meta and description content.
- Shared media still resolves through the existing manifest media index and bundle pipeline.
- Added likely post-object/relationship reference detection for post meta.
- Those unsupported cross-post references now surface as packet warnings and operator-facing transfer notes.

### Outcome

Make selected packets complete enough that destination review/apply can recreate the intended entity state instead of only shipping bare post JSON.

### Tasks

- For selected posts:
  - expand taxonomy term dependencies from `tax_input`
  - export those term entities into the packet
- For selected terms:
  - recursively include parent terms needed to rebuild hierarchy
- Ensure manifest generation captures post media refs as it does today.
- Add term media ref collection during manifest generation so term-side media parity is real, not only documented.
- Define v1 behavior for cross-post relationship/meta dependencies:
  - detect likely post-object/relationship references
  - warn in the packet preview when referenced posts are not included
  - do not auto-remap arbitrary post references in v1 unless explicitly selected

### Checklist

- [x] Post-selected packets include dependent taxonomy terms.
- [x] Parent terms are included recursively when needed.
- [x] Term media refs are collected into the manifest/media index.
- [x] Unsupported post-object dependencies are detected and surfaced as warnings.
- [x] Shared media across multiple selected entities does not duplicate bundle entries.

### Likely touchpoints

- `includes/class-backup-manager.php`
- `includes/class-sync-posts.php`
- `includes/class-sync-taxonomies.php`
- `includes/Dbvc/Media/*`

### Risks

- The current codebase already has a doc/code mismatch around term `media_refs`; close that before calling packet parity complete.
- Relationship-field remapping should stay outside v1 unless it is intentionally narrowed to selected packet members only.

## P4. Entity Editor Bulk Action + Packet Generation UX

Status: `CLOSED`

### 2026-04-06 tranche notes

- Added a new bulk `Create transfer packet` action to the Entity Editor selection toolbar.
- Added a nonce-protected `admin-post` endpoint that builds and streams the packet ZIP.
- Left the existing `Download selected` JSON ZIP path intact.
- Added a transfer preview endpoint that reuses packet staging/manifest analysis without generating the final ZIP.
- Added a source-side preview modal that shows counts, requirements, media bundle status, and unsupported-reference warnings before download.

### Outcome

Expose packet generation where operators already select entities: the Entity Editor index.

### Tasks

- Add a new bulk action next to the existing bulk download button.
- Add a preview drawer/modal before build that shows:
  - selected entity count
  - detected post types and taxonomies
  - dependency expansion summary
  - warnings for unsupported references
  - whether bundled media will be included
- Add a packet build action that submits through a download-friendly endpoint.
  - likely `admin-post` is the lowest-risk delivery path because the final output is a ZIP download
- Keep the existing bulk JSON download action unchanged.
- Keep per-row actions uncluttered; do not add a new table column for transfer controls.

### Checklist

- [x] Entity Editor has a new bulk packet action.
- [x] Preview step shows counts and warnings before build.
- [x] Packet download path is nonce-protected and capability-checked.
- [x] Existing bulk JSON download remains intact.
- [x] Table layout remains uncluttered.

### Likely touchpoints

- `src/admin-entity-editor/index.js`
- `admin/class-entity-editor-app.php`
- `includes/class-entity-editor-indexer.php`
- `build/admin-entity-editor.js`

### Risks

- Generating the packet directly inside the table without preview will hide important warnings and encourage unsafe assumptions.

## P5. Destination Intake + Proposal Review Integration

Status: `WIP`

### 2026-04-06 tranche notes

- Packet upload continues to flow through the existing proposal importer with no schema fork.
- Transfer-specific metadata now lands in the manifest so destination proposal review can identify packet origin and requirements.
- Proposal list and detail payloads now expose transfer `origin`, `selection`, `requirements`, and derived `preflight` warnings.
- The existing proposal review UI now shows transfer origin, packet summary, destination warnings, and packet notes before apply.

### Outcome

Let destination sites treat transfer packets as first-class proposal uploads instead of inventing a second review experience.

### Tasks

- Keep packet ZIP upload flowing through `upload_proposal()` / `import_proposal_from_zip()`.
- Add transfer-origin metadata to proposal summary payloads so UI/CLI can identify packet type.
- Extend proposal list/entity payloads to surface packet requirements and preflight warnings.
- Reuse current new-entity gating for safe inserts.
- If needed, add a scoped convenience action for "accept all new entities in this transfer packet" without bypassing review entirely.

### Checklist

- [x] Transfer packets ingest through the existing upload route.
- [x] Proposal list shows packet origin/type.
- [x] Missing post types/taxonomies are visible before apply.
- [x] Existing apply path stays unchanged underneath.
- [x] New-entity gating still protects destination inserts.

### Likely touchpoints

- `admin/class-admin-app.php`
- `src/admin-app/index.js`
- `build/admin-app.js`
- `README.md`

### Risks

- Creating a second destination importer would duplicate review state, resolver state, and audit logic.
- This phase should keep one review path.

## P6. WP-CLI Parity + Operational Tooling

Status: `DEFERRED`

### 2026-04-06 product decision

- WP-CLI parity is intentionally out of scope for this feature unless the existing proposal CLI workflow is affected.
- Keep the packet feature admin-first for v1 and revisit CLI only if operational needs justify it later.

### Outcome

Give operators and automation a supported terminal path for packet generation and intake.

### Tasks

- Add a new CLI surface for source-side packet creation, for example:
  - `wp dbvc packets create ...`
- Support selecting by:
  - relative sync paths
  - entity UIDs
  - post/term IDs where safe
- Allow writing the generated ZIP to a caller-specified filesystem path.
- Reuse the existing proposal upload/apply CLI commands on the destination side.
- Add packet inspection output if needed for support/debugging.

### Checklist

- [ ] Source-side packet creation command exists.
- [ ] Command supports at least one stable selector type.
- [ ] Command can write the packet ZIP to a user-supplied path.
- [ ] Destination workflow remains `wp dbvc proposals upload` + `apply`.
- [ ] CLI output includes dependency and warning summary.

### Likely touchpoints

- `commands/class-wp-cli-commands.php`
- `includes/Dbvc/Transfer/*`

### Risks

- Do not overload `wp dbvc export` or `wp dbvc proposals upload` with packet-build semantics.
- Keep packet generation as a separate CLI concern.

## P7. Validation, QA, Docs, and Rollout Closure

Status: `WIP`

### 2026-04-06 tranche notes

- PHP lint passed for the updated proposal admin/controller files and transfer builder.
- Asset rebuild passed for the admin proposal review UI and Entity Editor bundles.
- Manual runtime packet/apply smoke remains blocked by the pre-existing LocalWP CLI bootstrap fatal unrelated to the transfer-packet codepath.
- Preview analysis now runs through the same packet-selection logic as the final build, with media bundle generation intentionally skipped for the preview pass.
- Proposal ZIP intake now validates manifest payload paths, payload JSON readability, and referenced media-bundle assets before registering a proposal.
- Added PHPUnit coverage for transfer preview warning detection and transfer-packet upload validation failures.

### Outcome

Close the feature with targeted automated coverage, explicit manual QA, and docs that reflect actual behavior.

### Tasks

- Add PHPUnit coverage for:
  - packet selection normalization
  - subset staging export
  - dependency expansion
  - additive manifest metadata
  - transfer packet upload compatibility
  - destination preflight warnings
  - term media ref manifest coverage
- Add manual QA script for:
  - single post with featured image + ACF image/file/gallery meta
  - two posts sharing terms and media
  - CPT packet with parent/child terms
  - destination missing CPT/taxonomy warning
  - collision review on destination
  - apply success path
  - CLI packet create + upload + apply
- Update user docs and roadmap references after the first landed tranche.

### Checklist

- [x] PHPUnit coverage added for packet builder and intake compatibility.
- [ ] Manual QA checklist added.
- [ ] Term media parity validated, not just documented.
- [ ] User-facing docs updated.
- [ ] Progress tracker statuses updated as phases close.

### Validation note

Cross-site destructive QA should stay inside approved LocalWP boundaries. If live browser/runtime validation would require a second LocalWP site outside the current approved boundary, get explicit user approval before executing it.

## Recommended Phase Order for Implementation

Build in this order:

1. `P1` packet contract and storage boundary
2. `P2` packet builder and targeted export helpers
3. `P3` dependency expansion and term media parity
4. `P4` Entity Editor bulk UX
5. `P5` destination review integration and preflight warnings
6. `P6` CLI parity
7. `P7` validation and closure

This order keeps the risky work in backend contract and dependency handling first, then adds UI once the packet shape is stable.

## Completion Notes Template

When a phase closes, append a short note under that phase using this structure:

- `Completion note:` one short paragraph on what landed
- `Files touched:` flat list of paths
- `Validation:` flat list of tests or manual checks run
- `Open risks:` only remaining risks that still matter

This guide should remain the single source of truth for status until the feature is either shipped or deliberately split into a tracker plus archive.
