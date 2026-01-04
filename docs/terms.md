# Term & Taxonomy Entity Handling

## Goals
- Treat taxonomy terms (including `termmeta`) as first-class entities alongside posts/CPTs.
- Allow reviewers to Accept/Keep individual term fields/meta exactly like post fields.
- Ensure cross-environment parity: new terms are created only when reviewers approve them, and existing terms update only when selections exist.
- Provide reviewer shortcuts (e.g., “Review New Posts” + “Accept All New Entities”) so large batches can be approved without repetitive clicks.

## Current State
- Legacy exports already serialize term JSON (taxonomy, slug, termmeta), but the React workflow ignores those files.
- Only post entities flow through the diff/apply pipeline; termmeta changes or new terms never reach Site B.
- Media resolver works per entity, so term ACF fields referencing media can piggyback once terms become entities.

## Proposed End-to-End Flow

### Export
- For every term, emit an entity entry in `entities.jsonl`:
  ```json
  {
    "entity_type": "term",
    "vf_object_uid": "uuid",
    "taxonomy": "service_area",
    "slug": "akron",
    "name": "Akron",
    "description": "...",
    "parent": 0,
    "termmeta": { "field_xyz": "..." },
    "modified_gmt": "2025-11-01T12:00:00Z"
  }
  ```
- Include a term history block (mirroring `dbvc_post_history`) for provenance.
- Capture parent term UID so parent relationships can be remapped on import.

### REST & UI
- Surface term entities alongside posts in `GET /proposals/{id}/entities`.
  - Add columns for taxonomy, slug, term name, and a badge for new terms (`is_new_entity` flag).
  - Show Accept/Keep toggles for term fields/meta, identical to post meta handling.
  - Use the same “Accept new entity” gating if no local term exists.
- Diff drawer:
  - Group termmeta under its own section (“Term Meta”).
  - Reuse the media resolver previews for term ACF fields referencing attachments.

### Import
- Extend `DBVC_Sync_Posts::import_backup()` or add `DBVC_Sync_Terms`:
  1. Resolve local term via UID → `(taxonomy, slug)` fallback.
  2. For existing terms:
     - Apply only fields/meta marked Accept.
     - Skip terms with no Accept selections (same behavior we just added for posts).
  3. For new terms:
     - Require `accept_new` decision before calling `wp_insert_term`.
     - After creation, store the UID in `wp_dbvc_entities` (similar to posts).
  4. Update `termmeta` via `update_term_meta` respecting Accept/Keep.
- Logging:
  - Reuse `DBVC_Sync_Logger::log_import('Entity applied', …)` for terms (entity type + taxonomy + slug).
  - When a term is skipped (declined or no selections), log a single entry.

### Recommendations & Edge Cases
- **Parent remap**: store parent UID in the entity payload and resolve it during import so term hierarchies stay intact.
- **Conflicts**: if a slug exists locally but maps to a different UID, flag it like we do for posts (viewer must resolve).
- **Filters**: consider adding a “Type” filter in the UI so reviewers can focus on posts or terms.
- **CLI parity**: once term entities exist, extend upcoming CLI commands (`dbvc proposals apply`, etc.) to handle `--type=term` or similar selectors.
- **Performance**: large taxonomies can add hundreds of entities; virtualization already helps, but we should monitor and add search/filter shortcuts as needed.

## Implementation Checklist
1. Update export pipeline to include term entities in `entities.jsonl`.
2. Extend REST `GET /proposals/{id}/entities` to include terms, identity metadata, and resolver previews if needed.
3. Update React UI:
   - Column definitions for term fields.
   - Drawer sections for termmeta, Accept/Keep controls, “Accept new term” button.
4. Import changes:
   - Add term import helper (create/update, termmeta sync).
   - Integrate into `import_backup()` with the same decision gating as posts.
   - Update logging to cover terms.
5. Documentation & QA:
   - Document the new entity type in handoff.md / user docs.
   - Test scenarios: new term creation, termmeta diffs, parent term remap, media references in term ACF fields.
   - Update reviewer guides to note the **Accept All New Entities** control for bulk approvals.

Once this is complete, term/taxonomy objects will behave exactly like posts in the review/apply workflow, giving us true cross-environment parity.
