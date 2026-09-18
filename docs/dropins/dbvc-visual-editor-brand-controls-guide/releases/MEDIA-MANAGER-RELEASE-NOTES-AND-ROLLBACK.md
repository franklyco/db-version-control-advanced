# Media Manager — Release Notes & Rollback (R2-E4)

Ship-readiness summary for the Frontend Media Manager (R1 read-only scan/report → R2 direct remediation). Companion to the per-phase release docs in this folder.

## What shipped

**R1 — read-only scan & report.** A default-off, active-mode-only frontend surface that scans published posts/CPTs and public/show-UI terms in bounded chunks for empty supported media fields (native featured image; unconditional top-level and group-nested ACF image/gallery), stores compressed user/blog-bound snapshots, and renders a safe, searchable/filterable/sortable results table with lazy one-row field-status expansion. Opaque, non-authoritative references throughout; no owner ids, field keys/selectors, ACF object ids, paths, or fingerprints exposed.

**R2 — direct remediation.**
- **R2-A** descriptor bridge: exchanges one opaque finding for one fresh, server-authoritative editable descriptor after full snapshot/owner/capability/field/family/empty revalidation.
- **R2-B** native `wp.media` selection: choose an existing image/gallery or upload (upload tab governed by WordPress capability); staged unsaved.
- **R2-C** field-level save: writes through the shared audited mutation pipeline behind an expected-empty precondition; reconciles in place with no table reload.
- **R2-D** verified UX states (presentation only).
- **R2-E1** journal/cache verification; **R2-E2** security/stale/permission hardening; **R2-E3** single-active `wp.media` frame lifecycle + surgical no-reload DOM patch; **R2-E4** feature isolation (this doc).
- **R2-F** entity media inventory & replace: lists populated fields with sanitized previews (Slice 1) and left-aligned lazy thumbnails (Slice 2); an authorized user can **replace** a populated image/gallery through a dedicated endpoint gated by an expected-current-value fingerprint (Slice 3); a field you just assigned is immediately replaceable (Slice 4). Group-nested ACF fields write/read through the root group so values actually persist.
- **R2-G** UX polish: live saved-media thumbnail (no reload) + compact header-hosted status panel.

## Feature gates & isolation (verified)

- **Two flags, default-off.** The Media Manager is active only when the Visual Editor master flag **and** the Media Manager flag are both on (`is_media_manager_enabled()` = `is_enabled()` AND the feature flag). Turning off either closes the entire surface.
- **Capability + authentication.** Every REST route's `permission_callback` (`canAccess`) additionally requires a logged-in user with the base capability (`edit_others_posts`, filterable) — and each mutation re-checks the per-object capability and owner eligibility at write time.
- **Active mode.** The frontend shell/assets load only in active Visual Editor mode and inherit the existing Bricks Builder request isolation.
- `VisualEditorMediaManagerR2E4Test` proves the gate is closed when the Media Manager flag is off, the master flag is off, the user lacks the base capability, or the request is logged out — and open only when all hold.

## Side effects & boundaries

- **Writes:** only the targeted featured-image / ACF image / ACF gallery field reference, through the shared `MutationService` (validation → sanitize → resolver save → **change journal** → audit event → cache invalidation).
- **Never:** deletes attachments; writes arbitrary metadata; performs cross-entity bulk mutation or a same-entity "Save Row" (explicitly out of scope for R2).
- **Storage:** scan snapshots are ephemeral, user/blog-bound **transients**; the change journal uses the shared Visual Editor `{prefix}dbvc_ve_change_items` table. No Media-Manager-specific schema.

## Residual / deferred

- Real-browser + assistive-technology QA on an authenticated runtime (D-049): Media Library open/upload/focus layering, keyboard flows, repeated-open memory/listener profiling, current-page DOM/reload confirmation, real Safari.
- **Persistent Media Index (Phase 1)** — direction chosen; a discovery pass to run after this wrap-up (see `PROPOSAL-PERSISTENT-MEDIA-INDEX.md`).

## Rollback runbook

Rollback is feature-level and non-destructive — no data migration is involved.

1. **Disable the Media Manager flag** (`dbvc_visual_editor_media_manager_enabled` → off). Effect: `canAccess` closes every Media Manager REST route, the scoped shell assets stop enqueuing, and the toolbar entry disappears. The rest of the Visual Editor overlay is unaffected. This is the primary, instant kill switch.
2. **Disable the Visual Editor master flag** (`dbvc_visual_editor_enabled` → off) to remove the Media Manager and the overlay together.
3. **Existing assignments remain valid content.** Any featured image / ACF value written by the Media Manager is ordinary WordPress/ACF content; disabling or removing the feature leaves it intact and correct — nothing is orphaned.
4. **Code revert.** Reverting the add-on code removes the routes/services cleanly; there is **no Media-Manager-specific schema migration** to undo (snapshots are transients; the journal table is shared VE infrastructure and can remain).
5. **Reverting a specific assignment/replacement value.** Each mutation is recorded in the change journal with its old and new value; use the existing Visual Editor journal/recovery capabilities to revert an individual change. The Media Manager does not promise generalized undo.

## Verification snapshot at wrap-up

Full PHP suite 751 tests with the six inherited failures; Media Manager PHPUnit 71+ tests; jsdom 34/34; media-manager lint clean; agent docs 54/418/0. Authenticated real-browser/assistive-technology QA remains the standing residual gate (D-049).
