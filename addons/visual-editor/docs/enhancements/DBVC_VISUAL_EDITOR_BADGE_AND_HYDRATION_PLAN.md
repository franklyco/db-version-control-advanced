# DBVC Visual Editor Badge and Hydration Plan

## Goal

Refine the frontend Visual Editor runtime so it feels more inline and responsive without weakening the current server-driven descriptor model.

This plan covers:
- moving away from one detached badge element per marker
- reducing eager descriptor hydration on page load
- preserving scope-aware UI labels and color treatments
- clarifying where DB tables do and do not make sense for performance

## Current Reality

The runtime now does the following when Visual Editor mode is active:
- scans every rendered `[data-dbvc-ve]` marker
- fetches the authenticated session public map without full descriptor hydration by default
- renders one shared badge controller in the detached overlay layer
- positions that badge only for the currently hovered, focused, or selected marker
- fetches full descriptor payloads on demand and caches them after first lookup

This resolves the biggest issues from the earlier eager model:
- fewer DOM nodes
- less badge-position work
- lighter initial REST payloads
- less hidden-container badge leakage than a whole page of always-positioned detached badges

Remaining follow-up areas are now narrower:
- dwell timing and prefetch policy tuning
- real-device touch-selection polish
- profiling before any deeper caching decisions

## Design Decisions

### 1. Keep render-time instrumentation and transient request sessions

Do not change the source of truth:
- Bricks render hooks still stamp lightweight markers
- descriptor truth still lives server-side in the request/session registry
- DOM still carries only the token plus non-sensitive render metadata

This is still the correct model for safety.

### 2. Replace per-marker badges with a shared active badge

Recommended near-term UI model:
- keep dashed outlines on all eligible markers
- render one shared badge controller in the overlay layer
- show and position that badge only for the currently hovered, focused, or selected marker
- reuse the same badge node for:
  - `Edit`
  - `Shared`
  - `Related Post`
  - `Related Term`
  - `Inspect`

This preserves the current differentiated labels and scope meanings while removing the need to manage a badge node for every marker.

### 3. Preserve scope colors and wording

Do not flatten the existing source/scope signals.

The shared badge must still reflect the current descriptor state:
- current entity editable: teal treatment, `Edit`
- shared/options/global target: amber treatment, `Shared`
- related non-current owner: coral/red treatment, `Related Post` or `Related Term`
- inspect-only/derived/locked: slate treatment, `Inspect`

The same scope color system should remain aligned across:
- dashed outline color
- badge background/accent
- panel state chip / notice accent

### 4. Stop hydrating every descriptor during initial bootstrap

Current bootstrap should be treated as heavier than necessary.

Recommended next step:
- session bootstrap returns the public descriptor map only by default
- full descriptor payloads load on interaction
- optional delayed prefetch can run on hover/focus for the active marker only

This keeps the initial page payload smaller while preserving the same secure lookup flow.

### 5. Use hover/focus/touch state, not broad visibility heuristics

The shared badge should not try to maintain independent visibility for every hidden or revealed container.

Preferred behavior:
- `pointerenter` on a visible marker activates the shared badge
- `focusin` activates the shared badge for keyboard users
- first tap on touch devices selects the marker and reveals the badge/panel affordance
- second tap on the same touch-selected marker can open the editor without navigating away
- `pointerleave`, `blur`, `escape`, or panel-close clears the shared badge unless the marker is actively selected

This is simpler and more robust than trying to infer hidden-container state for a whole page of detached controls.

### 6. Do not add DB tables for runtime token caching now

Do not add a database table just to cache:
- request session payloads
- descriptor tokens
- rendered marker-to-field maps for the current page load

Those are request/runtime concerns and become stale too easily when:
- Bricks templates change
- ACF field definitions change
- query loop owners change
- content updates alter the render projection

### 7. Reserve DB tables for durable change history first

If new Visual Editor tables are added, they should still be for:
- change sets
- change items
- rollback metadata

That remains the highest-value durable storage addition.

### 8. Only consider a materialized mapping cache after profiling

If profiling later proves that request-time classification/instrumentation is the real bottleneck, a separate optional materialized inventory layer can be considered.

That would be:
- explicitly rebuildable from admin/settings or a toolbar action
- keyed by page/template/signature hashes
- treated as an optimization hint, not source of truth
- invalidated aggressively when builder/config/content inputs change

This is a later optimization path, not the next step.

## Recommended Implementation Slices

### Slice 1. Shared badge controller

Status:
- implemented

Implement:
- one shared badge node
- active-marker state manager
- hover/focus/touch activation
- scope-aware label + color switching
- selected-marker persistence while the side panel is open

Do not change descriptor generation in this slice.

### Slice 2. Lazy session bootstrap

Status:
- implemented

Change the session bootstrap contract so initial page load returns:
- session id
- page context
- public map
- marker counts / summary if useful

Do not return full hydrated descriptors by default.

### Slice 3. On-demand descriptor loading

Status:
- implemented with click-time lookup, in-memory cache reuse, and short-dwell hover/focus prefetch for the active marker

Use:
- descriptor fetch on badge click / marker activation
- in-memory cache after first fetch
- optional dwell-based hover prefetch for the active marker only

This keeps the UI responsive without hydrating everything up front.

### Slice 4. Active-marker interaction rules

Status:
- implemented at baseline hover/focus/touch-selection level
- real-device polish is still pending

Formalize:
- hover behavior
- keyboard focus behavior
- touch selection behavior
- active panel vs passive hover behavior
- how shared/related/inspect-only states are announced visually

### Slice 5. Runtime profiling and measurement

Before discussing durable runtime caches, measure:
- number of markers on representative pages
- session bootstrap payload size
- descriptor hydration cost
- time spent in frontend badge work
- time spent in Bricks classification/instrumentation

Add lightweight debug timings or logs only if needed.

## API / Contract Changes

### Session bootstrap

Recommended default response shape:
- `sessionId`
- `pageContext`
- `descriptors` public map
- optional summary counts

Recommended optional modes:
- `hydrate=1` only for debugging, QA, or explicit warmup
- default interactive mode should be non-hydrated

### Descriptor lookup

Descriptor detail endpoint remains the source for:
- owner metadata
- editable/read-only status
- current value
- UI input contract
- warning/acknowledgement requirements

No raw save targets should move into the DOM.

## Acceptance Criteria for the Refactor

The next runtime slice is successful when:
- dashed borders still show for all supported markers
- only one badge element exists at a time in normal interaction flow
- badge labels and colors still reflect current/shared/related/inspect states
- hidden megamenus/offcanvas content no longer leaks detached badges while hidden
- descriptor fetches happen on demand instead of hydrating the whole page by default
- the active marker can prefetch descriptor payloads after a short hover/focus dwell without duplicating in-flight requests
- no new persistent runtime cache table is required

## Explicit Non-Goals

This plan does not by itself add:
- new field resolvers
- new query-loop ownership coverage
- new repeater/flexible mutation paths
- persistent runtime token-cache tables
- builder-wide precomputation jobs

Those are separate follow-on concerns.
