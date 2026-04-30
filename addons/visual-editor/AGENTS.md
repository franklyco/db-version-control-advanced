# AGENTS.md
**Project:** DBVC Visual Editor Addon  
**Updated:** 2026-04-27  
**Mode:** Modular, implementation-ready, documentation-first, low-drift execution

This file is the canonical operating guide for work inside this addon.

Read this file first. Then read:
1. `README.md`
2. `ARCHITECTURE.md`
3. `docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`
4. `docs/knowledge/HOOK_USAGE_STRATEGY.md`
5. `docs/knowledge/DATA_CONTRACTS.md`

## Mission

Build a DBVC addon that overlays Bricks-rendered frontend pages with editable markers for supported ACF and WordPress-backed dynamic content, allowing authorized users to inspect, edit, validate, and save structured content safely.

This addon is not a page builder. It is a content-source-aware visual editing layer.

## Product boundaries

The addon must separate these concerns:

- runtime activation and permissions
- Bricks render instrumentation
- descriptor registry generation
- resolver-driven field targeting
- save validation and mutation
- audit and cache invalidation
- overlay UI and interaction states

Do not collapse these layers into one generic utility or monolith class.

## Architecture rules

### 1) Server-side descriptor registry is the source of truth
The browser must not guess the backend field target from raw HTML.

Use Bricks render hooks to stamp lightweight DOM markers such as:
- `data-dbvc-ve="ve_ab12cd"`

The full edit payload must be stored server-side or in a request-scoped registry object localized to the page.

Do not place raw field keys, entity IDs, or mutable save targets directly into public DOM attributes unless there is a specific reviewed reason.

### 2) Hooks are additive, not invasive
Prefer additive instrumentation over risky string replacement.

Primary Bricks integration strategy:
- `bricks/element/render_attributes` for element-level attribute injection
- `bricks/frontend/render_element` only as a later-stage fallback or wrapper point
- `bricks/frontend/render_data` only for area-level post-processing, diagnostics, or narrow fallback use

### 3) Resolver registry is required
Saving must flow through explicit resolvers. Do not let the client submit arbitrary meta keys.

Minimum initial resolver set:
- post title
- post excerpt
- ACF text
- ACF textarea
- basic URL/button text fields where direct mapping is explicit

Everything else must resolve to:
- supported
- read-only
- unsupported
- derived
- locked

### 4) Keep MVP narrow
MVP target:
- logged-in users with editor capability
- singular posts/pages/CPTs
- Bricks text-like elements with direct dynamic data mappings
- current entity editing only
- audit logging
- cache invalidation hook points
- safe save pipeline

Do not start with:
- repeaters
- flexible content editing
- global options editing without warnings
- arbitrary query loop editing
- media replacement
- rich WYSIWYG mutation across all contexts

### 5) Query loops and globals are phase 2+
Anything involving loop-item context, taxonomy context, reusable templates, options pages, or shared/global fields must be treated explicitly and documented before implementation.

### 6) No silent destructive behavior
Never overwrite content without:
- capability check
- nonce/session validation
- descriptor verification
- field-type-aware sanitization
- audit log entry

## Repo rules

### Modular by default
Prefer small, focused classes and docs over large all-in-one files.

### One file, one role
Keep boundaries clear:
- `Bricks/` for Bricks integration
- `Registry/` for descriptor lifecycle
- `Resolvers/` for save targeting
- `Rest/` for endpoints
- `Save/` for validation/mutation
- `Audit/` for change logs
- `Context/` for request/entity context
- `Assets/` for frontend UI enqueueing

### Preserve docs while implementing
If architecture changes materially, update:
- `ARCHITECTURE.md`
- relevant docs in `/docs/knowledge/`
- `docs/path-migration-map.md` if paths move
- `CHANGELOG.md`

## Validation discipline

Do not rerun a full smoke pass after every small edit.

Use risk-based validation:
1. syntax / local sanity
2. targeted validation for touched module
3. broader smoke check at a coherent checkpoint
4. full validation only when merge-ready or after structural/shared changes

At handoff, report:
- validation level used
- checks run
- checks intentionally not run
- residual risk

## Branching

Default branch is `main`.

For non-trivial work, prefer a short-lived branch such as:
- `codex/visual-editor-mvp`
- `task/descriptor-registry`
- `fix/rest-save-validation`

## Definition of done

A task is done when:
- the objective is complete
- the code respects the layered architecture
- docs are updated where needed
- validation matches risk
- residual risk is stated clearly
