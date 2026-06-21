# Post-Field Masking Expansion Plan

## Goal
Let administrators opt-in to masking root post fields (`post_date`, `post_modified`, etc.) using the same pipeline that currently handles meta keys. This touches settings, REST, importer/exporter, and the React UI.

---

## 1. Settings & Storage
- **Task 1.1 – UI control**: in `admin/admin-page.php` (Masking tab), add a multi-select or checkbox list titled “Post fields to mask” seeded with known keys (post_date, post_modified, post_excerpt, post_parent, post_author, vf_object_uid, etc.).
- **Task 1.2 – Option storage**: save selections to a new option, e.g. `dbvc_mask_post_fields` (array of sanitized keys).
- **Task 1.3 – Validation**: server-side sanitize the list (allow only whitelisted keys) before persisting.

## 2. REST & Masking Collector
- **Task 2.1 – Surface post field config**: in `DBVC_Admin_App::get_mask_meta_patterns()`, return the post-field list alongside meta patterns, e.g. `['keys'=>..., 'subkeys'=>..., 'post_fields'=>['post_date', ...]]`.
- **Task 2.2 – Collect post-field diffs**: extend `collect_masking_fields()` to inspect each entity’s root keys. For any key present in the configured list:
  - Build a pseudo-path like `post.post_date`.
  - Derive a label (e.g. “Post Date”) and diff values (current vs proposed).
  - Append to the same `fields` array the meta masking endpoint returns.
- **Task 2.3 – Path matching**: update `mask_path_matches_patterns()` (and any helper) so a path starting with `post.` is allowed when it matches the configured post field list.
- **Task 2.4 – Apply handler**: in `apply_proposal_masking()`, allow `meta_path` entries that start with `post.`. For `ignore`, record a `keep` decision with that path. For `auto_accept`, record `accept` and store suppression metadata keyed off a new namespace (e.g. `post_fields`). For overrides, either reject (if overriding root fields isn’t supported) or store replacement values in a new bucket so the importer can swap them later.
- **Task 2.5 – Suppression storage**: extend `store_mask_suppression()` / `store_mask_override()` to include post-field paths without clobbering existing meta entries (maybe namespace by `scope` => `meta` vs `post`).

## 3. Importer / Exporter Changes
- **Task 3.1 – Export metadata**: ensure manifests contain both the proposed and current values for the whitelisted post fields so diff labels can show them (may already exist for most keys).
- **Task 3.2 – Import apply**: in `DBVC_Sync_Posts::import_post_from_json()` when processing the `mask_directives`, read the post-field suppression/override buckets and:
  - Skip writing a masked field when `ignore` decisions exist.
  - Force-set the JSON value when an override exists.
- **Task 3.3 – Cleanup**: when decisions clear or proposals are deleted, purge post-field suppression/override entries just like meta entries.

## 4. React Admin Updates
- **Task 4.1 – Tooltip/docs**: update `docs/meta-masking.md` to mention post-field masking.
- **Task 4.2 – UI labels**: ensure the masking list shows the new pseudo-path labels (“Post Date”, etc.). No extra component work should be needed if the REST payload already contains `label`/`section`.
- **Task 4.3 – Settings panel**: mirror the new checkbox control in the React-config screen if we ever port the settings UI (optional).

## 5. Tests & QA
- **Task 5.1 – REST tests**: add PHPUnit coverage (similar to `MaskingEndpointsTest`) that selects a post field via the new option, asserts `/masking` returns `post.*` entries, and verifies `/masking/apply` records decisions for those paths.
- **Task 5.2 – Import tests**: add a test in `class-sync-posts` verifying suppressed/override post fields actually skip/replace values during apply.
- **Task 5.3 – Manual QA**: document a replay script (create a proposal with custom post dates, enable post-date masking, run the bulk action, reopen to confirm it stays resolved, import to confirm values follow the override).

---

## Implementation Notes
- Reuse existing decision storage to avoid new tables; just ensure keys like `post.post_date` don’t collide with meta paths.
- Coordinate naming between REST and importer; prefixing with `post.` keeps it obvious which scope a path belongs to.
- When in doubt, follow the current meta-masking behavior so reviewers get identical affordances (bulk action, undo, revert).
