# DBVC Visual Editor Repo Plan

## Discovery Checklist

- Addon folder location: `addons/visual-editor/`
- Core loading pattern: manual `require_once` entries in `db-version-control.php`, then explicit `::bootstrap()` calls
- Add-on runtime gating pattern: server-rendered `DBVC -> Configure -> Add-ons` controls with `get_all_settings()`, `get_settings_groups()`, `get_field_meta()`, `save_settings()`, and runtime refresh hooks
- Admin entry point for MVP: frontend admin-bar toggle only; no separate submenu workspace yet
- REST pattern to reuse: `register_rest_route()` under core namespace shape `dbvc/v1/...` with dedicated permission callbacks and no-cache headers where needed
- Capability baseline: core admin addons use `manage_options`, but visual editing must allow logged-in editors and still enforce per-entity `edit_post`
- Asset loading pattern: register/enqueue only on matching page/request, localize bootstrap data, keep operational UI minimal
- Logging/audit pattern: reuse `DBVC_Database::log_activity()` and optional `DBVC_Sync_Logger::log()`
- Cache invalidation pattern: explicit post-cache cleanup plus addon-specific action hook; no bespoke cache store for MVP
- Reusable entity/ACF context: prefer direct ACF runtime field resolution (`get_field_object`, `update_field`) and keep Content Migration field-context services optional for later phases
- Existing Bricks touchpoint to target: render-time element settings inspection with `bricks/element/render_attributes`; avoid broad HTML guessing
- MVP storage decision: no custom DB tables; use short-lived transients for descriptor-session persistence between frontend render and authenticated REST calls

## Implementation Slice

1. Add repo-adapted bootstrap facade `DBVC_Visual_Editor_Addon` and wire it into `db-version-control.php`.
2. Replace scaffold path/slugs/docs references with `visual-editor` naming and `Dbvc\\VisualEditor\\...` namespaces.
3. Add Add-ons screen controls for Visual Editor enablement and runtime refresh.
4. Implement nonce-backed frontend edit-mode toggle with session cookie state for authorized users only.
5. Enqueue frontend assets only when addon is enabled and edit mode is active on supported singular frontend requests.
6. Register Bricks instrumentation only in edit mode; support only direct text-like dynamic sources that resolve cleanly to post title/excerpt or simple ACF text-like fields.
7. Persist per-request descriptor registries into transient-backed sessions keyed to user + session id; expose only lightweight DOM tokens.
8. Add authenticated REST endpoints for session bootstrap, descriptor inspection, and guarded save.
9. Route saves through resolver allowlists, `edit_post` checks, audit logging, and post-cache invalidation hooks.
10. Update addon docs to reflect the repo adaptation, current MVP status, and next unsupported scopes.

## Advanced Slice Revision

### Immediate next planning targets

1. Inspect-only marker support for repeater, flexible-content, relationship-collection, and unsupported loop-derived nodes.
2. Explicit non-current-owner badges for related/query-loop items in the overlay and modal.
3. Descriptor V2 metadata for owner/page/path/loop identity.
4. Path-aware resolver expansion for nested ACF fields.
5. Durable write journaling and rollback support before any multi-step nested mutation.

### UI direction

- Preserve the existing border/outline color differentiator by source scope.
- Add a badge for any node whose owner entity is not the current page post.
- Reuse the same scope color across marker outline, badge accent, and modal scope chip.

### Storage decision revision

- Keep transient sessions for request-scoped marker lookup and modal hydration.
- Do not add a descriptor-session table.
- Introduce dedicated Visual Editor journal tables before writable repeater/flexible/relationship-collection support:
  - `wp_dbvc_ve_change_sets`
  - `wp_dbvc_ve_change_items`
- Link those records to existing DBVC snapshot/history primitives where practical instead of inventing a second rollback vocabulary.

### Why a new table becomes justified

Simple scalar current-entity writes can still live on the current transient + audit path.

Advanced nested writes need durable operational state for:
- per-path rollback
- partial failure visibility
- multi-step mutation grouping
- future review/apply history

That is materially different from ephemeral marker sessions, so it should not be stored only in transients or the generic activity log.

## Risks To Watch

- `wp_enqueue_scripts` runs before Bricks render, so descriptor summaries cannot be localized until after registry persistence is designed correctly.
- Frontend editor state must not rely on raw query args alone; toggle requests need nonce validation and cleanup redirects.
- Descriptor lookup must survive the page render request; in-memory-only registry objects are insufficient for REST follow-up calls.
- Mixed literal + dynamic Bricks text values should stay unsupported in MVP to avoid ambiguous saves.
