# Architecture

## Summary

DBVC Visual Editor is a layered addon that instruments Bricks-rendered frontend content during render, produces a request-scoped registry of editable descriptors, and allows authorized users to edit supported content through a resolver-driven save pipeline.

The architecture must prevent fragile DOM guessing and avoid exposing raw save targets directly in the browser.

## System shape

### 1. Activation layer
Determines whether the current request is in visual editor mode.

Responsibilities:
- capability check
- signed toggle / nonce
- enable or disable runtime instrumentation
- enqueue overlay assets only when active

### 2. Context layer
Determines the effective entity context for the request.

Examples:
- current singular post
- Bricks-resolved options/global source
- Bricks-resolved user source
- Bricks-resolved taxonomy term source
- loop item context
- derived/shared content

Current slice supports singular frontend entry points only. Within that page request, the addon can now resolve a narrow safe subset of Bricks ACF object contexts:
- current singular post
- ACF options target
- ACF user target
- ACF taxonomy term target
- concrete queried post, term, and user loop owners when Bricks exposes a stable loop object
- current-post and related-post ACF repeater row descendants when Bricks exposes a stable row index and parent loop owner
- direct ACF flexible-content descendants when Bricks exposes a stable row index, parent flexible field, and layout identity

Writable loop-row ownership remains intentionally narrow:
- direct safe ACF fields on concrete queried post, term, and user owners
- Bricks ACF repeater rows whose owner resolves to the current post or the concrete related post rendered by a parent query loop
- direct flexible-content text-like, WYSIWYG, choice, link, and image subfields whose owner resolves to the current post or the concrete related post rendered by the current loop

Flexible gallery descendants and shared/non-post nested owners remain inspect-only until the flexible mutation contract is broadened beyond the current direct-field slice.

Referenced-object values stored on the current editable entity can now be saved in a narrow link-target path when the underlying ACF field remains current-entity owned.

### 3. Bricks instrumentation layer
Hooks into Bricks render flow and stamps lightweight marker attributes onto supported rendered nodes.

Primary hook:
- `bricks/element/render_attributes`

Fallback hooks:
- `bricks/frontend/render_element`
- `bricks/frontend/render_data`

Outputs:
- marker attribute on the rendered node
- descriptor added to registry

Current candidate rule:
- exact single dynamic-data tag in a text-like Bricks setting
- exact single dynamic-data tag wrapped by one otherwise-empty HTML node inside a text-like Bricks setting
- exact single dynamic-data tag in a top-level Bricks `link` control external URL
- exact single dynamic-data tag in an image-style Bricks `url` link payload when the element link mode resolves through that payload
- exact single dynamic-data tag in a Bricks `image.useDynamicData` control so the wrapper can track the rendered image source safely, currently for direct ACF image fields and direct WordPress `{featured_image}` bindings
- exact single dynamic-data tag in a Bricks `items.useDynamicData` image-gallery control so gallery ownership can be surfaced honestly
- exact single dynamic-data tag in deterministic repeater-style Bricks anchor payloads such as `items[index].link`, `icons[index].link`, or `linkCustom[index].link`
- exact single dynamic-data tag in direct ACF flexible-content descendants when Bricks exposes the parent flexible field and layout key
- for loop-row text/link/media candidates, editable saves currently allow direct safe ACF fields on concrete queried post, term, and user owners plus Bricks ACF repeater rows with stable current-post or related-post row identity
- flexible descendants with stable row + layout identity may surface as editable or inspect-only descriptors depending on field type and owner support
- generic exact-tag loop-row candidates with no concrete owner may still surface as inspect-only descriptors
- no mixed literal text around the tag
- editable markers must still pass render verification against one of the resolver-approved display projections
- inspect-only markers may remain visible even when the rendered text is only a derived projection of a more complex backend value
- editable image markers compare against the rendered `<img src>` attribute on the current Bricks element, not against wrapper text

### 4. Descriptor registry layer
Builds the request-scoped mapping between DOM markers and safe server-side edit descriptors.

A descriptor should answer:
- what rendered node is this
- where did its value come from
- what entity owns it
- what page is surfacing it
- what loop context produced it
- what nested path or row identity it carries
- what mutation contract it expects
- what field type is it
- which resolver can save it
- is it editable, read-only, derived, or unsupported

### 5. Resolver layer
Maps descriptors to backend mutation behavior.

Resolver responsibilities:
- confirm support
- read current value
- resolve the correct visible display projection for verification and in-place UI updates
- validate submitted value
- sanitize value
- save using correct API
- return normalized response

### 6. Save pipeline
Receives authenticated save requests and routes them through:
- capability checks
- descriptor verification
- mutation-contract verification
- shared-scope acknowledgement for non-current-entity writes
- validation
- sanitization
- mutation
- durable change-set journaling
- audit log
- cache invalidation

### 7. Overlay UI
Frontend UI that:
- highlights editable nodes
- opens popovers or side panels
- fetches descriptor details when needed
- reuses cached descriptor payloads when available
- submits saves
- updates UI state

Current runtime refinement:
- one shared active badge for hover/focus/touch selection instead of one badge per marker
- public-map-only session bootstrap by default
- on-demand descriptor hydration with in-memory request reuse and short-dwell active-marker prefetch

## Request flow

1. Authorized editor enables visual edit mode
2. Page reloads with editor mode active
3. DBVC registers Bricks instrumentation hooks
4. Supported elements receive `data-dbvc-ve="<token>"`
5. The marker can represent either visible text or a verified rendered attribute such as `href`, but still carries only a lightweight token plus non-sensitive render context metadata
6. A narrow render-element verification pass removes markers whose visible output does not match the resolved backend source projection
7. Registry stores the remaining descriptor for each token, with editable markers fully render-verified and inspect-only markers explicitly flagged as non-saveable
8. Overlay JS reads tokens from the DOM
9. Overlay JS renders one shared active badge in a dedicated fixed overlay layer so theme/container overflow rules cannot clip the control UI
10. Initial session bootstrap returns the authenticated public descriptor map for the current page by default
11. On interaction, JS fetches full descriptor payloads on demand, reuses the in-memory cache when available, and can prefetch the active marker after a short hover/focus dwell
12. Save request posts token + value + nonce
13. Non-current-entity targets such as shared options fields or related-post loop owners require explicit acknowledgement before the request is accepted
14. Backend resolves descriptor and saves via resolver
15. Backend records the save in the Visual Editor journal tables for durable per-path history
16. Audit and cache invalidation run
17. UI updates the marked node in place without reloading the page after a successful save
18. Matching markers for the same resolved field projection are synced together on the current page
19. Structured field saves can also fan out to other matched projections of the same resolved field on the current page by using a source-level group plus resolver display candidates

Planned runtime refinement:
20. Tune dwell timing and prefetch policy after profiling on representative pages
21. Refine mobile and tablet touch-selection UX if real-device testing shows ambiguity around first-tap vs second-tap behavior

## Non-goals for MVP

- arbitrary field discovery from final HTML alone
- universal support for all Bricks elements
- structured flexible-content row mutation and repeater row insert/remove/reorder
- generic non-ACF or multi-owner query loop editing beyond the current related-post slice
- media replacement and multi-value relationship editing
- static Bricks internal/taxonomy builder-link settings outside resolver-owned content fields
- gallery collection writes and media upload/replacement workflows
- shared/global editing without explicit resolver support and warnings

## Folder roles

- `src/Bootstrap/` runtime registration
- `src/Bricks/` Bricks hooks, element inspection, instrumentation
- `src/Context/` request and entity context
- `src/Registry/` descriptor objects and stores
- `src/Journal/` durable change-set and change-item journaling
- `src/Resolvers/` save/read behavior per content type
- `src/Rest/` API routes and controllers
- `src/Save/` validation, sanitization, mutation orchestration
- `src/Audit/` change logs and revision notes
- `src/Cache/` cache invalidation handoff points
- `src/Assets/` script and style enqueueing
- `src/AdminBar/` activation UI entry points

## Architectural guardrails

- Descriptor token, not raw field target, in DOM
- Resolver registry required for save
- Unsupported content must remain unsupported
- Each new supported field type needs a documented resolver
- Structured fields can expose only a matched display projection to the live DOM update path
- Repeater row descriptors must carry parent field + row identity so source and sync groups stay row-scoped
- Descriptor V2 metadata should keep page, owner, loop, path, and mutation-contract identity explicit rather than inferring them from loose source strings later
- Save eligibility for advanced sources should be determined from explicit mutation contracts such as `direct_field`, `repeater_row`, `shared_field`, `loop_owned_field`, and `loop_owned_repeater_row`, not from scope checks alone
- Do not add durable DB tables just to cache request-time tokens or descriptor sessions
- If a materialized inventory cache is ever introduced later, treat it as an optimization hint rather than source of truth
- If DBVC already has a content mutation or audit service, integrate with that rather than duplicating it
