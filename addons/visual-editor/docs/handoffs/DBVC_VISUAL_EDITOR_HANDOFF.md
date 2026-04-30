# DBVC Visual Editor Handoff

## What this package is

This package is a starting implementation scaffold plus architecture handoff for a new DBVC addon.

It is not a finished feature.

## High-confidence direction

Build the first slice around render-time Bricks instrumentation, not DOM guessing.

Use:
- `bricks/element/render_attributes` as the primary instrumentation hook
- a request-scoped descriptor registry
- a resolver-driven save path
- REST endpoints for descriptor detail and save
- a minimal overlay UI

## Recommended implementation sequence

### 1. Confirm DBVC addon conventions
Adapt bootstrap and service wiring to the real DBVC plugin.

### 2. Implement edit mode activation
- admin bar or floating trigger
- nonce-protected toggle
- request flag / session / query var
- conditional asset loading

### 3. Implement Bricks hook registrar
- register hooks only in visual edit mode
- inspect element settings and dynamic content
- create descriptor tokens
- add marker attributes

### 4. Implement descriptor registry
- generate token
- persist request-scoped descriptor data
- expose bootstrap payload

### 5. Implement first resolver slice
- post title
- one ACF text field path

### 6. Implement REST controllers
- session/bootstrap
- descriptor detail
- save

### 7. Implement save pipeline
- verify capability
- verify token/descriptor
- validate/sanitize
- save
- audit
- invalidate

### 8. Implement overlay UI
- marker discovery
- click-to-edit
- save feedback

### 9. Validate on a real Bricks singular CPT
Prefer a page or service post using direct ACF-backed heading/text output.

## Anti-patterns to avoid

- raw field key payloads in DOM
- arbitrary meta writes
- blanket support for every dynamic tag
- repeaters in MVP
- overusing `bricks/frontend/render_data` as primary mapping logic
- pretending unsupported content is editable

## Success condition

At least one narrow, real, safe, end-to-end edit path works cleanly.
