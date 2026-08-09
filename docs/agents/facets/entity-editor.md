# DBVC Entity Editor Facet

Load this facet for direct JSON Entity Editor work or the separately gated in-page Visual Editor add-on. Record-level authority is in [`manifest.json`](../manifest.json).

## Primary Records

| Record | Boundary |
|---|---|
| `entity_editor.core.inspect` | Index, read, and download supported JSON entities |
| `entity_editor.core.write` | Save JSON, partial import, full replace, and index rebuild |
| `addon.visual_editor.runtime` | In-page session, descriptor, search, save, audit, and change-journal workflow |

## Supported Scope

The current Entity Editor supports post and term JSON. It excludes media/attachments, menus/nav items, and options.

- `Save JSON` validates, backs up, and atomically replaces the sync file without updating WordPress.
- Partial import updates JSON-present data and does not delete missing metadata.
- Full replace requires typed `REPLACE`, deletes non-protected metadata missing from JSON, and creates a pre-replace snapshot.
- Save/import requires a valid lock token; takeover is a separate operator decision.

## Safety Questions

- Is this file-only save, partial import, or full replacement?
- Which metadata keys are protected by the current filters?
- Is the lock current and owned by this operator?
- Has the target entity been resolved by UID/history before slug fallback?
- Is a preflight deletion count needed? The current full-replace UI reports counts after the operation.
- Does the task require direct sync-file editing or the session-scoped in-page Visual Editor?

## Load Next

- Concise behavior authority: [`docs/reference/entity-editor-usage.md`](../../reference/entity-editor-usage.md)
- REST/admin host: [`admin/class-entity-editor-app.php`](../../../admin/class-entity-editor-app.php)
- Index and write engine: [`includes/class-entity-editor-indexer.php`](../../../includes/class-entity-editor-indexer.php)
- Endpoint tests: [`tests/phpunit/EntityEditorEndpointsTest.php`](../../../tests/phpunit/EntityEditorEndpointsTest.php)
- Identity implications: [Identity, Storage, And Observability](identity-storage-and-observability.md)
- Visual Editor authority: [`addons/visual-editor/docs/README.md`](../../../addons/visual-editor/docs/README.md)
