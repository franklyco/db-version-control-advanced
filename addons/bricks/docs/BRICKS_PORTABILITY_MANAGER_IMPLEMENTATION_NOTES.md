# Bricks Portability Manager Implementation Notes

## MVP module location

- Runtime: `addons/bricks/portability/`
- Admin surface: dedicated Bricks-related submenu page `DBVC > ↳ Settings Portability`
- Page sections: internal `Workspace` and `History & Rollback` subtabs
- Review UX: collapsed domain summary at the top of the workbench plus modal row diff viewer on row click
- Review table: includes `Approved Action` visibility/filtering separate from the per-row action control
- Review table: includes warning-state filtering, a `Manual Decisions` filter option, and `Hide No Drift rows` enabled by default
- Review table: renders in client-side pages with a configurable rows-per-page selector to keep large review sessions responsive
- Review stats: live totals above the table for incoming package rows, current site rows, visible rows, and approved-action counts
- Review persistence: decisions can be saved back onto a review session as a draft and reloaded later
- Review session state: current-site compare freshness is surfaced as `Fresh` or `Stale`, sessions can be refreshed against the live site, and rollback state is surfaced after restore
- REST namespace: `dbvc/v1/bricks/portability/*`

## Supported MVP domains

- `settings` -> `bricks_global_settings`
- `color_palette` -> `bricks_color_palette`
- `global_classes` -> `bricks_global_classes`
- `global_variables` -> `bricks_global_variables`
- `pseudo_classes` -> `bricks_global_pseudo_classes`
- `theme_styles` -> `bricks_theme_styles`
- `components` -> `bricks_components`
- `breakpoints` -> `bricks_breakpoints` when present

## Related metadata included in domain review

- `bricks_global_classes_categories`
- `bricks_global_variables_categories`
- `bricks_breakpoints_last_generated` is classified as backup-only/derived, not canonical

## Bricks 2.3.x font/icon discovery (2026-06-24)

- Current official Bricks release checked: `2.3.8` (2026-06-23). LocalWP Bricks install inspected at `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/themes/bricks`: `2.3.7`.
- Local Bricks constants:
  - `BRICKS_DB_CUSTOM_FONTS` -> `bricks_fonts`
  - `BRICKS_DB_CUSTOM_FONT_FACES` -> `bricks_font_faces`
  - `BRICKS_DB_CUSTOM_FONT_FACE_RULES` -> `bricks_font_face_rules`
  - `BRICKS_DB_ICON_SETS` -> `bricks_icon_sets`
  - `BRICKS_DB_CUSTOM_ICONS` -> `bricks_custom_icons`
  - `BRICKS_DB_DISABLED_ICON_SETS` -> `bricks_disabled_icon_sets`
- Font Manager storage:
  - custom font families are `bricks_fonts` posts;
  - font variants live in `bricks_font_faces` post meta;
  - each variant stores attachment IDs by format (`woff2`, `woff`, `ttf`) and can use a newer subset array shape with `unicode-range`;
  - `bricks_font_face_rules` is generated CSS and should be treated as derived/regenerable;
  - Bricks exposes custom font choices as `custom_font_{post_id}`, so cross-site import must remap font IDs in selected settings domains.
- Icon Manager storage:
  - custom icon set list is option `bricks_icon_sets`;
  - custom icon records are option `bricks_custom_icons`, with observed fields `id`, `name`, `url`, `setId`, and `attachment_id`;
  - disabled icon set state is option `bricks_disabled_icon_sets`;
  - custom icons depend on SVG media attachments and source URLs.
- Live local evidence:
  - 8 `bricks_fonts` posts;
  - 1 custom icon set;
  - 4 custom SVG icon records;
  - `bricks_font_face_rules` present as a 5615-byte generated CSS string;
  - font/SVG attachments exist under uploads.
- Implementation consequence: fonts and icons are Phase 20 media-backed portability domains. They must not be implemented as raw option-only domains.

## Current option classification decisions

- Canonical portable: the options mapped by the domain registry above
- Related metadata: categories options for classes and variables
- Backup-only: `bricks_global_classes_locked`, `bricks_global_classes_changes`, `bricks_global_classes_timestamp`, `bricks_global_classes_user`, `bricks_global_classes_trash`, `bricks_breakpoints_last_generated`
- Ignore for MVP: `bricks_remote_templates`, `bricks_panel_width`, `bricks_pinned_elements`
- Planned Phase 20 media-backed domains: custom fonts (`bricks_fonts` posts + `bricks_font_faces` post meta + referenced font attachments) and icon collections (`bricks_icon_sets`, `bricks_custom_icons`, `bricks_disabled_icon_sets` + referenced SVG attachments)
- Backup-only/derived for Phase 20: `bricks_font_face_rules`
- Still needs live verification before portability support: `bricks_global_elements`

## Important implementation boundaries

- Compare is normalization-first, not raw JSON compare.
- Review UI is table/workbench based with bulk actions plus row overrides.
- CSS variable dependency warnings distinguish:
  - missing on current site but supplied by the incoming package
  - missing on both current site and incoming package
  - possibly external or outside the selected Bricks portability domains
- Category dependency warnings now also distinguish when related class/variable categories are missing on the current site but present in the incoming package, and the review modal can mark the related metadata row for incoming apply.
- MVP does not do destructive delete-sync of target-only objects.
- Apply rebuilds updated option payloads in memory and writes once per affected option.
- Apply always snapshots affected options first and rollback restores the pre-apply snapshot.
- Breakpoints now pass through a dedicated storage-shape verifier before export and before apply. Unrecognized breakpoint payloads are blocked instead of being blindly transported.

## Current implementation checklist

1. Best add-on folder location: `addons/bricks/portability/` is the active runtime location and should remain the only implementation path for this feature.
2. Existing Bricks add-on architecture to reuse: `DBVC_Bricks_Addon` bootstrap/settings/menu gates, existing `manage_options` capability checks, existing idempotency helper, existing DBVC jobs/activity hooks, and existing add-on docs/tracker conventions.
3. Admin screen placement: dedicated Bricks-related submenu page `DBVC > ↳ Settings Portability`, not another tab in the main Bricks add-on page.
4. Existing service/module conventions: focused static service classes for registry, normalizer, package service, diff engine, apply service, backup service, storage, REST controller, and page renderer.
5. Existing logging/history/backup patterns: use DBVC jobs/activity when available, JSON session/export/backup records under DBVC storage, and pre-apply option snapshots through `DBVC_Bricks_Portability_Backup_Service`.
6. Existing REST/ajax patterns: REST namespace `dbvc/v1/bricks/portability/*`, nonce-protected admin fetches, idempotency keys on mutating requests, and capability-aware route callbacks.
7. Recommended file/class breakdown: keep current service split; add new narrow verifier/normalizer helpers only when behavior cannot fit existing classes cleanly.
8. Naming conflicts or architectural risks: avoid reusing legacy package/apply concepts from `bricks-packages.php` for settings portability; keep this governed package/session model separate.
9. Custom DB tables for MVP: not needed. Existing DBVC storage plus jobs/activity is sufficient for export packages, review sessions, backups, rollback records, and recent history.
10. Canonical Bricks option names: MVP registry treats `bricks_global_settings`, `bricks_color_palette`, `bricks_global_classes`, `bricks_global_variables`, `bricks_global_pseudo_classes`, `bricks_theme_styles`, `bricks_components`, and verified `bricks_breakpoints` as portable; class/variable categories are related metadata; generated/locked/change/user/trash markers are backup-only or ignored.
11. Font/icon extension boundary: `custom_fonts` and `icon_collections` are intentionally deferred to Phase 20 because they require DB entities, media files, attachment ID remapping, SVG/font validation, and rollback semantics beyond the option-only MVP.

## Next phased hardening plan

- `BPDM-HARDEN-01`: tighten uploaded package validation so every extracted JSON payload must be explicitly checksummed and allowed by the package contract.
- `BPDM-HARDEN-02`: add regression coverage for unchecksummed package payload rejection and unexpected package file rejection.
- `BPDM-HARDEN-03`: run targeted PHPUnit and syntax validation before expanding into Phase 2 domains.
- `P20`: implement media-backed Bricks custom font and icon collection portability as planned in `BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`.

## Current assumptions worth re-checking against live Bricks installs

- Breakpoints are wired to `bricks_breakpoints` if that option exists locally. This remains the biggest live-storage verification point.
- `bricks_components` is treated as the canonical portable component domain for MVP.
- `bricks_global_elements` is treated as legacy storage. Newer Bricks versions can convert legacy global elements, but DBVC portability does not write that legacy option directly in MVP.
- Icon/font domains are excluded from the current MVP because they require media transfer, checksum validation, attachment creation/reuse, and ID remapping. Phase 20 now owns that work.
