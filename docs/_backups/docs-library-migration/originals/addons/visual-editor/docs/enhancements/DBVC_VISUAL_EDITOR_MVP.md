# DBVC Visual Editor MVP

## Objective

Deliver the smallest real working slice of the visual editor while preserving safe architecture.

## MVP boundaries

### In scope
- logged-in editors only
- singular posts/pages/CPTs only
- Bricks frontend only
- current entity context only
- Bricks heading/basic text/button-text style elements where source mapping is explicit
- post title + direct ACF text-like field support
- direct post excerpt support
- REST-based descriptor lookup and save
- audit hook point
- cache invalidation hook point
- overlay marker UI

### Out of scope
- repeaters
- flexible content
- query loops
- options/global editing
- taxonomy editing
- relationship fields
- media editing
- rich text mutation across arbitrary HTML
- batch save queues
- revision UI

## MVP acceptance criteria

1. User with editor capability can enable visual edit mode from the frontend.
2. Page reloads in visual edit mode.
3. Supported rendered nodes display editor markers.
4. Clicking a marker opens an in-page editor panel with safe descriptor details.
5. User can edit and save a supported field without a full-page reload.
6. Saved value persists correctly to the owning entity.
7. Nodes whose rendered text does not match the resolved current-entity source do not masquerade as editable.
8. Save path logs the mutation and triggers invalidation hooks.
9. Feature remains inactive for non-editors and when edit mode is off.
