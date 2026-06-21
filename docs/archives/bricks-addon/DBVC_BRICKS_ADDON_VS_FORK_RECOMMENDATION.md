# DBVC Bricks Add-on vs Fork Recommendation

Date: 2026-02-12  
Scope: Architecture decision support only (no implementation)

## 1) Fit Assessment Against Bricks Requirements

Source requirements: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/DBVC_BRICKS_ADDON_HANDOFF.md`.

| Bricks Requirement | Reusable in DBVC now | Gap Type | Notes |
|---|---|---|---|
| Golden package publish | Yes (partial) | Low/Medium | Reuse `DBVC_Backup_Manager` + `Dbvc\\Official\\Collections`; add Bricks-specific package metadata/version channeling. |
| Drift detection | Yes (partial) | Low/Medium | Reuse snapshot/hash/diff primitives; add Bricks artifact adapter + status model. |
| Governance policies | Partial | Medium | No first-class policy engine; add add-on settings + evaluator layer. |
| Proposal pipeline | Yes (strong baseline) | Low/Medium | Existing proposal upload/review/apply REST/UI flows are robust; needs Bricks-specific artifacts and mothership semantics. |
| Apply + rollback safety | Yes | Low | Existing backup/apply/import flows plus manifests and snapshot history. |
| Entity-based handling for `bricks_template` | Yes | Low | Reuse `vf_object_uid` + Entity registry (`dbvc_entities`). |
| Options-based Bricks globals | Yes (transport) | Low/Medium | Reuse options export/import, but add scoped extraction and canonicalization for Bricks keys. |
| DBVC Configure Add-ons UI location | No explicit add-on framework | Medium | Extend existing Configure tab/subtab pattern in `admin/admin-page.php`. |
| Mothership API for package/proposals | Partial | Medium | REST scaffolding pattern exists; new Bricks namespace/routes needed. |

### Summary
- Most engine capabilities exist and are reusable.
- Largest missing piece is a formal add-on registration/config surface and Bricks-specific adapter logic.
- Missing pieces are additive, not foundational rewrites.

## 2) Option A: Implement as DBVC Add-on Module

### Pros
- Reuses mature import/export/manifest/apply/Entity/history engines.
- Reuses existing security gates, admin capability model, and logging infrastructure.
- Keeps one operational plugin and avoids dual maintenance.
- Faster path to MVP with lower reimplementation risk.

### Cons
- Current plugin has monolithic classes (`class-sync-posts.php`, `admin/admin-page.php`, `admin/class-admin-app.php`), so careful modular boundaries are required.
- Add-on registration framework is not yet explicit.

### Risk profile
- Medium overall, but mostly coupling risk rather than missing-engine risk.

## 3) Option B: Fork “DBVC-Bricks” Minimal Plugin

## 3.1 KEEP list (minimum viable extracted core)

Required to preserve Bricks system behavior without re-inventing core engines:
- Bootstrap and constants:
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/db-version-control.php` (adapted)
- Engine/core:
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-posts.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-taxonomies.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-backup-manager.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-snapshot-manager.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-database.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-import-router.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/import-scenarios/*.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/functions.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/hooks.php`
- Media subsystem if proposal parity is needed:
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-media-sync.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/Media/*.php`
- Review/apply REST + UI host:
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-admin-app.php`
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/build/*` (or equivalent rebuilt assets)

## 3.2 DROP list (likely unnecessary for Bricks-focused fork)

- ACF options groups subsystem (if not used):
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-options-groups.php`
- Legacy/classic docs and fixtures not needed at runtime.
- WP-CLI command surface (optional to defer):
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/commands/class-wp-cli-commands.php`
- Some non-Bricks UI sections in `admin/admin-page.php` (but removing them cleanly requires significant surgery).

### Fork reality check
Even a “minimal” fork still pulls a large portion of DBVC because core behaviors are tightly coupled. Forking does not actually produce a small codebase immediately.

## 3.3 Suggested structure if forking anyway

```text
dbvc-bricks/
  dbvc-bricks.php
  includes/
    engine/   (extracted sync/backup/snapshot/db classes)
    media/    (optional)
    bricks/   (artifact adapter, policies, proposal endpoints)
  admin/
    class-bricks-admin-app.php
    class-bricks-settings.php
  build/
  languages/
```

### Namespace/prefix plan
- Avoid collisions with existing DBVC installations by renaming:
  - PHP classes prefix from `DBVC_` to `DBVCB_` (or namespaced `DbvcBricks\\...`).
  - Options/tables from `dbvc_*` to `dbvcb_*`.
  - REST namespace from `dbvc/v1` to `dbvc-bricks/v1`.

### Licensing/header updates
- Preserve GPL-2.0+ compatibility notices in forked files.
- Update plugin headers in fork bootstrap.
- Keep attribution/comments where required by license and original source headers.

### Migration notes (fork vs DBVC)
- Settings:
  - Map/copy relevant `dbvc_*` options to `dbvcb_*` on first run.
- Tables:
  - Either create new `dbvcb_*` tables or migrate selected rows from `dbvc_*`.
- Files:
  - Define separate upload roots to avoid clobbering DBVC proposal/backup stores.

## 4) Recommendation

## Recommended: Option A (DBVC Add-on)

### Rationale
- Maintainability:
  - Better than fork because core engine fixes/security patches remain shared.
- Release cadence:
  - Faster to ship MVP; avoids parallel release coordination and migration overhead.
- Security posture:
  - Reuses existing auth/capability/nonce patterns and tested import/apply pathways.
- Estimated complexity:
  - Add-on path is medium complexity (mostly additive).
  - Fork path is high complexity due to extraction, renaming, migration, and long-term divergence.
- Long-term extensibility:
  - Add-on architecture can support future artifact domains beyond Bricks without duplicate engines.

### Caveat
- Proceed with strict module boundaries to limit coupling risk:
  - keep Bricks logic in `addons/bricks/*`
  - define adapter interfaces to core engines
  - minimize direct edits in monolithic core classes where possible.

## 5) Practical Go/No-Go

- Go with Add-on if:
  - you accept incremental refactoring and adapter layering within current DBVC architecture.
- Consider Fork only if:
  - organizational policy requires independent release/security lifecycle from DBVC core.

Current technical evidence favors Add-on.
## Implementation Guardrails (Added)

To keep Bricks Add-on implementation on-rails, use:
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_FIELD_MATRIX.md`
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_PLAN.md`

These now include:
- concrete settings field matrix (keys, defaults, validators),
- phased checklist with sub-tasks,
- required API/apply/proposal safety constraints,
- explicit missing-item inventory before implementation starts.
