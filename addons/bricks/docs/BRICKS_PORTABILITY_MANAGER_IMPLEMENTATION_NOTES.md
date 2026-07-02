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

## Supported entity-backed domains

- `bricks_templates` -> `bricks_template` posts with `_bricks_template_type`, `_bricks_template_settings`, Bricks area meta (`_bricks_page_header_2`, `_bricks_page_content_2`, `_bricks_page_footer_2`), and `template_tag`/`template_bundle` terms

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
- Phase 20 media-backed domains: custom fonts (`bricks_fonts` posts + `bricks_font_faces` post meta + referenced font attachments) and icon collections (`bricks_icon_sets`, `bricks_custom_icons`, `bricks_disabled_icon_sets` + referenced SVG attachments). Current implementation exports/imports these domains into review rows with checksummed package media and supports add-only apply for new incoming font/icon objects with attachment creation/reuse, font ID remapping in selected option payloads, generated font CSS clearing, and rollback cleanup for created posts/attachments. Replacement, delete-sync, and richer collision handling remain disabled.
- Phase 21 entity-backed domain: Bricks templates (`bricks_template` posts + template type/settings meta + Bricks area meta + template tag/bundle terms). Current implementation exports/imports this domain into review rows and supports add/replace apply with entity-state rollback for created/replaced templates.
- Phase 22 template dependency hardening: embedded media IDs, arbitrary post/entity IDs, nested template IDs, slug collision safety, mixed rollback hardening, and live builder/frontend evidence. Initial implementation now uses typed dependency descriptors for attachment-backed image/gallery/background/video/template-settings media, nested template IDs, and Bricks template preview post/term refs, packages template-embedded media into the checksummed media manifest, validates imported descriptor shape/path/package-media/entity metadata, rewrites known area-meta and template-settings media ID/URL plus nested template ID payload paths during apply, remaps `templatePreviewPostId`/`templatePreviewTerm` by DBVC UID then exact post type/taxonomy slug, preserves unresolved preview refs, blocks unselected/cyclic nested-template dependency applies, and records template-created attachments in media rollback state. Remaining work must keep using deterministic remap policy rather than guessing every numeric `id` value in Bricks element payloads.
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
10. Canonical Bricks option names and entity records: MVP registry treats `bricks_global_settings`, `bricks_color_palette`, `bricks_global_classes`, `bricks_global_variables`, `bricks_global_pseudo_classes`, `bricks_theme_styles`, `bricks_components`, and verified `bricks_breakpoints` as portable option domains; `bricks_templates` is a portable entity-backed domain; class/variable categories are related metadata; generated/locked/change/user/trash markers are backup-only or ignored.
11. Font/icon extension boundary: `custom_fonts` and `icon_collections` are Phase 20 media-backed domains. Export/import review and add-only apply for new incoming objects are implemented; replacement/merge apply remains blocked until conflict policy, live evidence, and broader validation coverage are complete.
12. Template extension boundary: `bricks_templates` is Phase 21 entity-backed portability. Export/import review and add/replace apply are implemented for template posts/meta/taxonomies.
13. Template dependency boundary: Phase 22 owns embedded media hydration, nested-template remapping, deterministic post/entity reference policy, slug collision hardening, mixed rollback coverage, and live builder/frontend evidence. Do not silently rewrite unknown numeric IDs; unresolved references must be review-visible and either preserved or blocked by severity policy.

## Next phased hardening plan

- `BPDM-HARDEN-01`: tighten uploaded package validation so every extracted JSON payload must be explicitly checksummed and allowed by the package contract.
- `BPDM-HARDEN-02`: add regression coverage for unchecksummed package payload rejection and unexpected package file rejection.
- `BPDM-HARDEN-03`: run targeted PHPUnit and syntax validation before expanding into Phase 2 domains.
- `P20`: finish media-backed Bricks custom font and icon collection replacement/collision handling, export-side media rejection proof, partial-failure rollback hardening, admin preflight/receipt UI, and live two-site evidence as planned in `BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`.
- `P21`: keep the entity-backed `bricks_templates` add/replace baseline stable and close any narrow registry/template+font test gaps.
- `P22`: continue template reference hydration and dependency safety. Initial image/gallery/background/video/template-settings media hydration, nested template remapping/blockers, preview post/term UID/slug remapping with unresolved preservation, malformed descriptor import validation, stale collision guard coverage, and rollback are implemented; remaining work includes broader Bricks source-control coverage, query/post picker/dynamic-data refs, dependency map receipts, repeated idempotency fixtures, admin review receipts, and live two-site evidence as planned in `BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`.
- `P23`: extend deterministic reference handling to recognized Bricks query controls, post/term pickers, archive/author refs where deterministic, and dynamic-data tokens. Keep this backend-led: no per-reference user decisions, safe remaps apply automatically, optional unresolved refs preserve source values. First implementation slices cover deterministic `settings.query.post__in`, `post__not_in`, `tax_query`, and `tax_query_not` scalar/array refs, built-in `link.postId`/`link.term` controls, plus Bricks core `site_login:<post_id>`/`site_logout:<post_id>` dynamic post-token refs using the existing `post_or_term` descriptor contract. CSV query strings, dynamic query strings, missing source query terms, and unmapped built-in link post/term values are preserve-only with review warnings; query-editor PHP, user/author refs, broader picker controls, and dynamic-data token shapes outside the safe login/logout post redirect subset remain open.
- `P24`: add low-friction reference summaries and apply receipts. Keep the current workspace and row modal; prefer compact `Needs attention` summaries and History/Rollback receipts over a new reference-management interface. Current slices add row-level `template_reference_summary` counts to the existing review payload, display one compact `Template refs` line in the row modal summary, and store compact apply receipts on apply results, session approval, backup records, recent backup records, rollback results, and rollback session views. Detailed source-to-target maps are stored as backend support/debug data for template posts, template media, nested templates, post/term refs, query/link refs, and dynamic-data refs, while the UI remains count-first. Remaining work includes domain/session rollups, richer path/action/reason details, browser-level modal QA, and live two-site evidence.
- `P25`: harden idempotency, dependency backup state, mixed partial-failure rollback, stale session handling, and malformed package rejection without adding required controls.
- `P26`: run the live LocalWP source-to-target drill and release gate using the same default export/import/apply/rollback workflow an operator would use.

## Current assumptions worth re-checking against live Bricks installs

- Breakpoints are wired to `bricks_breakpoints` if that option exists locally. This remains the biggest live-storage verification point.
- `bricks_components` is treated as the canonical portable component domain for MVP.
- `bricks_global_elements` is treated as legacy storage. Newer Bricks versions can convert legacy global elements, but DBVC portability does not write that legacy option directly in MVP.
- Icon/font domains now support media transfer into export/import review packages plus add-only apply for new incoming objects. Attachment creation/reuse, custom font ID remapping across selected settings/theme/component payloads, missing media, checksum mismatch, invalid refs, filterable size limits, SVG scriptable-content rejection, and rollback cleanup have automated coverage; replacement/collision paths and live two-site verification still need hardening.
- Bricks template domain now supports entity transfer into export/import review packages plus add/replace apply and rollback for template posts, Bricks template meta, template terms, typed image/gallery/background/video area-meta media hydration, template-settings media hydration, nested template ID remapping, nested dependency blockers, preview post/term UID/slug remapping, first-pass query post/term UID remapping with unresolved preservation, built-in link post/term remapping, skipped query-shape warnings, first-pass dynamic post-token remapping, and import-time dependency descriptor validation. Phase 22/23 still need broader embedded media/query/dynamic-data coverage, slug collision UX/receipts, mixed rollback hardening, and live two-site verification using typed dependency descriptors and deterministic remap rules.
