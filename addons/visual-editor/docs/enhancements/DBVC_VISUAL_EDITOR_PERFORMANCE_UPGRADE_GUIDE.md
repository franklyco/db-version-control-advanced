# DBVC Visual Editor Performance Upgrade Guide

## Goal

Make the Visual Editor feel like a near live Bricks + ACF frontend editor without weakening the current source-owner, render-verification, descriptor, and save-contract model.

This guide is based on a code audit of the current Visual Editor loading path:

- `src/Bootstrap/Addon.php`
- `src/Assets/AssetLoader.php`
- `src/Bricks/HookRegistrar.php`
- `src/Bricks/ElementInstrumentationService.php`
- `src/Bricks/DynamicDataInspector.php`
- `src/Bricks/AcfFieldContextResolver.php`
- `src/Bricks/LoopContextResolver.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Registry/EditableRegistry.php`
- `src/Rest/Controllers/SessionController.php`
- `src/Rest/Controllers/DescriptorController.php`
- `src/Rest/DescriptorPayloadBuilder.php`
- `assets/js/api-client.js`
- `assets/js/overlay-app.js`

## Executive Recommendation

Do not start with a durable DB table or sync-path JSON as the first performance fix.

Start with measurement, request-local memoization, batched descriptor hydration, lighter heartbeat/session refresh, cached marker maps in the browser, and late loading of heavy editor/media assets where possible.

After profiling proves request-time Bricks/ACF classification is the bottleneck, add a materialized inventory layer. That inventory should be an optimization hint, not source of truth. Live render-time instrumentation and descriptor verification must still decide whether a marker is writable for the current page load.

Recommended storage split:

- DB table or option-backed cache for fast runtime inventory lookup.
- Sync-path JSON for reviewable, portable, DBVC-friendly inventory artifacts.
- Transients remain the right storage for request/session descriptor payloads.

## Current Load Path

### 1. Activation and hook registration

`Addon::register()` wires edit mode, admin bar toggle, assets, Bricks hooks, REST routes, journal tables, and a shutdown session persistence hook.

Runtime only continues when:

- the user can use Visual Editor
- the edit-mode cookie is active
- the page context is supported
- the request is not admin/ajax/cron/customizer
- the request is not Bricks Builder or a builder preview style request

`HookRegistrar::maybeRegisterHooks()` registers Bricks hooks only after those guards pass and Bricks is available.

Active hooks:

- `bricks/posts/query_vars`
- `bricks/element/render_attributes`
- `bricks/frontend/render_element`
- `bricks/frontend/render_data`

### 2. Asset bootstrap

`AssetLoader::enqueue()` currently enqueues the overlay CSS, API client, overlay app, WordPress editor assets, and WordPress media assets whenever edit mode is active.

The localized bootstrap includes:

- REST base
- REST nonce
- session id
- session TTL
- keepalive interval
- page context
- current edit link
- toggle URL
- capability flags for WordPress editor/media support
- UI strings

Performance note:

The initial page can load `wp_enqueue_editor()` and `wp_enqueue_media()` even if the user never opens a WYSIWYG, image, or gallery field. This is likely one of the largest frontend payload costs in active edit mode.

### 3. Bricks render-time instrumentation

`ElementInstrumentationService::instrumentAttributes()` runs during Bricks element attribute rendering.

For each candidate element it:

1. remembers element metadata
2. remembers native ACF query object type metadata
3. tries collection/query-loop root inspection
4. falls back to direct dynamic-data inspection
5. classifies the candidate through `ResolverRegistry`
6. creates a session token and descriptor
7. stores the descriptor in `EditableRegistry`
8. stamps lightweight DOM attributes such as `data-dbvc-ve`

The browser gets the marker token, status, scope, source/sync groups, render context, badge label, input hint, and query element id. It does not receive raw save targets in the DOM.

### 4. Bricks query evidence capture

`capturePostQueryVars()` records final Bricks post query summaries by element id.

This supports derived Query Editor collection markers and empty-loop synthetic descriptors when Bricks renders no usable loop root markup.

This branch is heavier than direct text/image/link detection because it can inspect:

- final `post__in` evidence
- dynamic tag evidence
- Query Editor source hints
- inferred post types from result IDs
- empty result handling
- target post type filtering

### 5. Post-render verification

`verifyRenderedElement()` checks already-created descriptors against rendered HTML.

For each descriptor it can:

- resolve the resolver
- read the current backend value
- extract the rendered fragment
- compare rendered projection to resolved source projection
- rebind a row descriptor when exactly one stored row matches the rendered value
- strip unsafe/mismatched markers
- verify gallery attachment identity
- register missing-media or empty-loop fallback markers

This is required for safety. It is also one of the places where repeated backend reads can add page render cost.

### 6. Final render data injection

`finalizeRenderedData()` runs after Bricks frontend render data.

It can inject or repair markers when normal attribute injection did not survive, including:

- element occurrence marker injection
- missing-media parent marker injection
- empty query-loop marker injection after loop comments or query trails
- duplicate gallery marker stripping

This function iterates descriptors and performs string operations against the full rendered content. It is useful, but should remain a fallback path.

### 7. Session persistence

`EditableRegistry::persistRequestSession()` stores the request descriptors in a user-scoped transient at shutdown.

The transient contains:

- session id
- user id
- page context
- full descriptors
- public map
- created timestamp

Default TTL is 8 hours, filterable from 5 minutes to 48 hours.

`loadSession()` refreshes the transient TTL every time a session is loaded.

### 8. Frontend boot

`overlay-app.js` boot does this:

1. checks `DBVCVisualEditorBootstrap.active`
2. creates toolbar, parked statusbar, panel, badge layer, and shared badge
3. scans `document.querySelectorAll('[data-dbvc-ve]')`
4. requests the session public map without `hydrate=1`
5. recovers query-collection markers from session metadata where needed
6. starts keepalive
7. starts viewport prefetch observation
8. mounts marker classes
9. mounts query-collection container badges
10. starts a MutationObserver for late query-collection badge remounts

The initial session request returns public metadata only by default. Full descriptor payloads hydrate on demand.

### 9. Descriptor loading and prefetch

`loadDescriptorPayload()` fetches one descriptor token through:

`GET /dbvc/v1/visual-editor/session/{session_id}/descriptor/{token}`

The frontend already has:

- in-memory descriptor cache
- in-flight request reuse per token
- 180ms active-marker dwell prefetch
- viewport-aware prefetch using `IntersectionObserver`
- `requestIdleCallback` scheduling where available
- concurrency cap of 2
- cycle budget of 4
- pause during save, reload pending, session expired, hidden tab, and Media Library modal states

Viewport prefetch currently runs only after a marker is previewed or the panel is open. This avoids background hydration before the user engages with the editor.

### 10. Session keepalive

The frontend refreshes the full session public map:

- every 240 seconds by default
- on window focus
- when the tab becomes visible
- before actions if the last refresh is older than 60 seconds

This protects against expired sessions, but it can add an extra request before opening a field after idle time.

## Current Strengths

- Initial REST bootstrap is already public-map-only by default.
- Full descriptors hydrate only on interaction or bounded prefetch.
- Descriptor requests are cached and de-duplicated in the browser.
- Viewport prefetch is bounded and paused during higher-priority work.
- The server never trusts DOM-only field guesses.
- Writable descriptors pass live render verification.
- Public index metadata lets Review Fields group markers without full hydration.
- Query-collection badge recovery uses public metadata rather than eager descriptor payloads.

## Current Performance Risks

### 1. No profiling layer

The code has no dedicated performance counters for:

- Bricks elements inspected
- candidates classified
- ACF context resolutions
- resolver reads during verification
- markers stripped
- session payload size
- descriptor payload size
- cold versus warm panel open latency
- viewport prefetch queue behavior

Without those numbers, a durable inventory table would be speculative.

### 2. Repeated page context and loop context work

`PageContextResolver::resolve()` is called from multiple paths. It is cheap on simple singular pages but can still repeat archive and URL resolution.

`LoopContextResolver::resolve()` can run frequently during classification and ACF context resolution. It calls Bricks loop APIs and decorates active loop ancestry. Loop context changes across rows, so it cannot be cached globally without a key, but it can be memoized by active query id, loop object, loop index, and native query object type.

### 3. Repeated ACF provider and field definition work

`AcfFieldContextResolver` repeatedly:

- locates the Bricks ACF provider
- calls provider `get_tags()`
- parses expressions
- loads ACF field objects
- resolves parent/container field definitions
- reads raw row payloads for repeater/flexible context

There is no obvious request-local memoization for those calls.

### 4. Repeated ACF field inventory scans

Derived query collection classification uses `get_field_objects()` and `get_field()` for current-owner and option fallback matching.

That is valid work, but it can become expensive on pages with multiple derived query loops or repeated collection markers.

### 5. Resolver reads happen during render verification and again on descriptor hydration

Render verification reads the backend source to compare with the rendered projection. Descriptor hydration reads again to populate the editor panel.

That second read is correct for freshness, but the UX can feel slower because the panel waits for the full read. There may be room for an immediate verified-render preview while the fresh read completes.

### 6. Heavy WordPress editor/media assets load eagerly

Active edit mode currently calls `wp_enqueue_editor()` and `wp_enqueue_media()` on every supported page.

This helps WYSIWYG/media fields work immediately, but it penalizes text-only editing sessions and pages where users only inspect fields.

### 7. Browser marker scans are repeated

`findMarkers()` calls `document.querySelectorAll('[data-dbvc-ve]')`.

That is fine for small pages. On marker-heavy pages, repeated scans for counts, locate actions, live sync, badge recovery, and query-collection badges can add browser-side overhead.

### 8. Query-collection badge mounting can do broad DOM work

The query-collection badge path groups markers, finds common ancestors, walks comment nodes for loop-start anchors, and remounts after mutation events.

This is necessary for empty/derived loops, but it should be measured and eventually keyed by marker maps and cached anchors.

### 9. Keepalive refresh returns more data than needed

Session keepalive currently fetches the same public map used for initial boot. A heartbeat endpoint could extend TTL and return minimal state without re-sending all public descriptors.

### 10. One descriptor request per prefetch token adds REST overhead

Viewport prefetch is bounded, but it still makes separate REST requests per token. On pages with many nearby fields, a capped batch descriptor endpoint would reduce request overhead while preserving explicit token lookup.

## Performance Upgrade Plan

### Phase 0. Add profiling before optimization

Goal:

Measure where time is spent before changing storage or behavior.

Server metrics to capture per Visual Editor request:

- total Bricks attributes inspected
- direct candidates found
- collection candidates found
- classifications attempted
- descriptors registered
- descriptors verified
- descriptors stripped by unsupported resolver
- descriptors stripped by rendered mismatch
- synthetic empty-loop descriptors registered
- missing-media descriptors registered
- `PageContextResolver::resolve()` calls and total time
- `LoopContextResolver::resolve()` calls and total time
- `AcfFieldContextResolver::resolve()` calls and total time
- ACF `get_field_object()` call count
- ACF `get_field_objects()` call count
- ACF `get_field()` call count for classification and verification
- `DescriptorPayloadBuilder::build()` count and time
- public map byte size
- full descriptor session byte size
- final descriptor count by status, scope, input, and render context

Frontend metrics to capture:

- DOMContentLoaded to Visual Editor mount complete
- marker scan count and duration
- session public-map request duration and payload size
- first hover to badge visible
- first click to panel ready, cold
- click to panel ready, cached
- descriptor request count
- descriptor payload sizes
- viewport prefetch queued, fetched, skipped, failed
- field index render duration and row count
- query-collection badge mount duration and badge count
- Media Library open latency
- save request duration
- save to DOM patch duration

Recommended implementation shape:

- Add a request-local `PerformanceProfiler` service under `src/Performance/`.
- Gate it behind a setting/filter, for example `dbvc_visual_editor_performance_profile_enabled`.
- Emit summaries to PHP error log only in debug mode or expose an authenticated REST diagnostics endpoint.
- Add frontend `performance.mark()` / `performance.measure()` calls in `overlay-app.js`.
- Keep metrics value-only. Do not log field values, raw descriptor payloads, nonces, or save targets.

Current baseline implementation:

- `src/Performance/PerformanceProfiler.php` is now wired through the Visual Editor bootstrap and remains disabled by default.
- Enable it with the `dbvc_visual_editor_performance_profile_enabled` filter.
- PHP error-log output is separately controlled by `dbvc_visual_editor_performance_profile_log`, defaulting to on only after profiling itself is enabled.
- Event capture is capped by `dbvc_visual_editor_performance_profile_max_events`, default `50`, hard-capped at `200`.
- The summary action `dbvc_visual_editor_performance_profile_summary` receives the sanitized summary and profiler instance for custom capture.
- Initial server metrics cover page/loop/ACF context timing, resolver selection and classification, Bricks attribute instrumentation, Bricks render verification, final render-data marker repair, descriptor add/remove counts, session persistence count/size, and REST descriptor payload build timing.
- Session persistence metrics now split descriptor filtering, public-map export, descriptor export, descriptor compression, transient writes, profiler JSON byte counting, public-map byte size, compressed descriptor-blob byte size, and active descriptor compression level.
- Frontend `performance.mark()` / `performance.measure()` coverage now exists in `assets/js/overlay-app.js` for overlay boot, marker scans, public-map request/sync, recovered query markers, marker mounting, query badge mounting, viewport prefetch setup/pump, descriptor requests, panel open, panel render, and save requests.
- Browser timing entries use the `dbvc.ve.` prefix. Inspect them from DevTools or console with `performance.getEntriesByType('measure').filter((entry) => entry.name.startsWith('dbvc.ve.'))`.
- ACF function-level counters were added for the derived Bricks Query Editor collection hotspot: field-object reads/cache hits, field-candidate build/cache hits, stored-value reads/cache hits, current-owner match loops, option-fallback match loops, readonly fallback classification build, and current-owner seed-context matching.
- Browser-side payload-size reporting remains follow-up work.

Local profiler findings from the first live pass:

- `/gallery/` / page `86`: 8 markers, 8 descriptors, 35 KB session payload, `registry.persist_session` around 6-21 ms, full descriptor hydration around 1 ms, and render-data repair under 1 ms. Small pages are not currently blocked by descriptor hydration.
- `/our-process/` / page `24732`: 333 markers, 366 descriptors, 2.5 MB session payload, full descriptor hydration around 63-65 ms, session persistence around 213-260 ms, and final render-data repair around 1.1 seconds on the full page chunk.
- `/vertical/dentists/` / post `23690`: 264 markers, 261 descriptors, 15 query-collection markers, 2.45 MB session payload, full descriptor hydration around 41 ms, session persistence around 213 ms, final render-data repair around 645 ms, and query-collection classification/native-readonly fallback around 426-560 ms across repeated collection roots.
- The first safe optimization added an anchor prefilter inside `finalizeRenderedData()` so descriptors are skipped when the current render-data chunk has no possible element, empty-loop, query-trail, or missing-media anchor. This preserved marker counts and reduced partial chunk repair work sharply, for example `/our-process/` descriptor chunk `246` dropped from about 95 ms to about 10 ms and chunk `362` dropped from about 443 ms to about 101 ms.
- The second safe optimization caches Bricks opening-tag matches per render-data chunk while preserving original descriptor order and clearing the cache whenever fallback injection or duplicate-gallery cleanup mutates HTML. This preserved marker parity and reduced `/our-process/` full-page repair from about 1.1 seconds to about 762 ms, reduced its descriptor `362` chunk to about 32 ms, and reduced `/vertical/dentists/` full-page repair from about 645 ms to about 107 ms.
- The first request-local memoization pass caches decorated active loop contexts by current Bricks loop state, caches native ACF query field definitions, and caches derived Query Editor collection field objects/candidates/values/post-type lookups. This preserved marker parity, reduced `/our-process/` active loop-context time from about 248 ms to about 99 ms, reduced `/vertical/dentists/` active loop-context time from about 518 ms to about 103 ms, and reduced `/vertical/dentists/` derived query-collection inspection from about 347 ms to about 3 ms after cache warmup. A follow-up profile split showed the remaining cold readonly branch was an all-options ACF field inventory read, not the option match loop itself.
- The first session-persistence pass stores full descriptor arrays as gzip-compressed JSON while keeping the public map plain, and public session reads now skip descriptor decoding. This preserved descriptor hydration parity, reduced `/our-process/` stored session payload from about 2.5 MB to about 534 KB, reduced `/vertical/dentists/` from about 2.45 MB to about 450 KB, and cut persistence time on those fixtures to roughly 85-100 ms. Session TTL refresh writes are now throttled by `dbvc_visual_editor_session_refresh_interval` instead of rewriting the transient on every load.
- The public-map compaction pass keeps the browser-facing map readable but recursively omits empty public fields. On `/our-process/`, the measured public map dropped from about 375.8 KB to 241.7 KB, and the overall compressed session payload dropped from the earlier roughly 534 KB checkpoint to about 399.7 KB. The descriptor compression level is now filterable through `dbvc_visual_editor_session_descriptor_compression_level`; gzip level 4 remains the default because level 1 saved little total persistence time while inflating the descriptor blob.
- The readonly derived-query follow-up splits the remaining cold branch into measurable profiler spans and adds a direct option-hint fast path before scanning every ACF option field. Direct hinted option fields must resolve as `relationship` or `post_object` and the resolved field name must match the hint; grouped/nested hints that need ancestry metadata still fall back to the existing full candidate scan. On `/vertical/dentists/`, marker parity held at 264 markers / 261 descriptors / 15 query markers while `resolver.derived_query.readonly_branch` dropped from about 176 ms to about 2.3 ms, `resolver.derived_query.option_fallback_match` dropped from about 176 ms to about 2.1 ms, and `resolver.acf_field_objects_read{object:option}` disappeared from the profile.
- The frontend timing follow-up is local-only observability. It does not send metrics to WordPress yet, but it gives browser QA a concrete timing surface for initial overlay boot, cold panel open, warm panel open, descriptor prefetch, and save latency.
- A more aggressive token-present skip was rejected because it reduced `/our-process/` marker count from 333 to 329, proving repeated-token/occurrence handling still needs the existing repair path.

Minimal local enablement example:

```php
add_filter('dbvc_visual_editor_performance_profile_enabled', '__return_true');
```

Acceptance:

- One representative page can produce a timing summary without changing Visual Editor behavior.
- The summary identifies the top three contributors for page-load overhead and panel-open overhead.

### Phase 1. Request-local memoization

Goal:

Reduce repeated server work inside a single Visual Editor page render without changing descriptor contracts.

Recommended caches:

- `PageContextResolver`: cache `resolve()` per request.
- `AcfFieldContextResolver`: cache Bricks ACF provider object and `get_tags()` result.
- `AcfFieldContextResolver`: cache parsed expressions by expression string.
- `AcfFieldContextResolver`: cache `get_field_object($field_name, $acf_object_id, false, false)` by field name and ACF object id.
- `AcfFieldContextResolver`: cache parent/container field definitions by selector/key.
- `AcfFieldContextResolver`: cache raw ACF rows by object id and parent selector when resolving repeater/flexible context.
- `ResolverRegistry`: cache `get_field_objects($entity_id, false, true)` by owner object id.
- `ResolverRegistry`: cache option fallback `get_field_objects('option', false, true)`.
- `ResolverRegistry`: cache `get_field($selector, $object_id, false)` during derived query matching.
- `LoopContextResolver`: cache decorated loop context by active query id, loop object type/id, loop index, query element id, runtime object type, and remembered native object type.
- `ElementInstrumentationService`: cache post type inference for repeated result IDs.
- `ElementInstrumentationService`: keep the render-data repair path on indexed/cached opening-tag matches, with cache invalidation whenever fallback injection changes the current HTML chunk.

Current implementation note:

- `LoopContextResolver` now caches decorated loop contexts by current Bricks loop state and clears that cache when remembered native ACF query object types change.
- `NativeAcfQueryResolver` now caches native ACF query field definitions and resolved query metadata by selector/object id.
- `ResolverRegistry` now caches ACF field objects, derived Query Editor relationship/post_object candidate lists, candidate source values, and repeated post-type lookups for the current request.
- `EditableRegistry` now stores session descriptors in a compressed descriptor blob when zlib is available, leaves `public_map` directly readable for initial frontend boot, and throttles transient TTL refresh writes.

Guardrails:

- Do not cache across requests in this phase.
- Do not cache source values across a save request.
- Do not cache loop context without a key that includes row identity.
- Do not replace render verification with cached classification.

Acceptance:

- Descriptor count and statuses are unchanged on representative pages.
- Rendered marker tokens may differ per session as they already do, but source/sync grouping must remain stable within the page.
- Profiling shows fewer duplicate ACF/Bricks calls.

### Phase 2. Reduce REST round trips

Goal:

Make panel opens and viewport warmup faster under realistic network latency.

Add a capped batch descriptor endpoint:

`POST /dbvc/v1/visual-editor/session/{session_id}/descriptors`

Payload:

```json
{
  "tokens": ["ve_abc123", "ve_def456"]
}
```

Rules:

- Require active Visual Editor mode and normal Visual Editor capability.
- Sanitize tokens.
- Cap token count, for example 10 per request.
- Reuse `DescriptorPayloadBuilder::buildMany()`.
- Return only found descriptors keyed by token.
- Return missing tokens separately without leaking unauthorized session data.

Frontend use:

- Keep explicit active marker opens as highest priority.
- Let viewport prefetch collect a small token batch during idle time.
- Reuse the existing descriptor cache and `descriptorRequests` map.
- If an active token is already inside a batch in flight, let explicit open reuse that promise.
- Normalize cached hydration payloads so full-session, batch, and single-token descriptor payloads all satisfy the same cached `ok + descriptor` panel-open path.

Also add a minimal heartbeat endpoint:

`POST /dbvc/v1/visual-editor/session/{session_id}/touch`

Rules:

- Extend transient TTL.
- Return `ok`, `sessionId`, `ttl`, and `serverTime`.
- Do not return public map or descriptor hydrations.

Frontend use:

- Use `touch` for interval, focus, and visibility refresh.
- Avoid a full session refresh before descriptor open unless the session is missing.
- Let descriptor and save endpoints extend TTL through normal session load.

Acceptance:

- Initial page boot still performs one session public-map request and no eager descriptor hydration.
- Idle keepalive no longer downloads the public map.
- Cold panel open after idle does not require an extra full session request first.
- Viewport prefetch request count drops on marker-heavy pages.

Current implementation note:

- `SessionController` now exposes `POST /visual-editor/session/{session_id}/touch`, returning only session status, TTL, and server time.
- `DescriptorController` now exposes `POST /visual-editor/session/{session_id}/descriptors`, with a default cap of 10 tokens via `dbvc_visual_editor_descriptor_batch_limit`, hard-capped at 25.
- `api-client.js` exposes `touchSession()` and `getDescriptors()`.
- `overlay-app.js` now uses touch-only refreshes for keepalive, focus/visibility return, and stale pre-action checks when a session is already present.
- Viewport prefetch now dispatches up to four tokens per batch request, while explicit hover/open descriptor loads still use the existing single-token path and reuse any matching in-flight batch promise.

### Phase 3. Browser marker map and badge work reduction

Goal:

Reduce repeated DOM scans and layout work on marker-heavy pages.

Recommended frontend changes:

- Maintain `state.markerNodes` and `state.markersByToken`.
- Build the map after initial session recovery and marker mounting.
- Update the map from the existing MutationObserver instead of repeatedly calling `querySelectorAll`.
- Replace `findMarkerByToken()` with token map lookup.
- Replace repeated `getMarkerCount()` scans with cached count unless a marker mutation invalidates it.
- Cache loop comment anchors by query element id.
- Cache query-collection badge grouping until marker set changes.
- Throttle query-collection badge remounts with one animation frame plus a minimum timer.
- Add a `ResizeObserver` for badge targets if live testing shows scroll/resize reflow is still noisy.
- Add field-index virtualization only after row count proves it is needed.

Guardrails:

- Do not miss markers injected after initial boot.
- Do not let stale nodes stay in maps after DOM removal.
- Preserve touch, keyboard, and outside-click behavior.

Acceptance:

- Marker-heavy pages show lower field-index and badge-layout time.
- Locate/Open still work for normal, recovered, empty-loop, and query-collection markers.

### Phase 4. Heavy asset deferral

Goal:

Avoid loading WordPress editor/media assets on pages where they are not needed.

Current problem:

`AssetLoader::enqueue()` calls `wp_enqueue_editor()` and `wp_enqueue_media()` before the final descriptor inventory is known.

Possible approaches:

1. Late footer enqueue based on request registry
   - Keep light overlay assets in `wp_enqueue_scripts`.
   - Add a late `wp_footer` hook after Bricks has rendered and descriptors have been registered.
   - Inspect registered descriptors for `wysiwyg`, `media_reference`, `gallery`, and collection inputs.
   - Enqueue/print heavy assets only when those inputs exist.
   - Risk: WordPress media/editor script ordering can be fragile if the overlay already executed.

2. Split overlay feature modules
   - Keep the base overlay independent from `wp-editor` and `media-editor`.
   - Load media/editor support only when `window.wp.media` or `window.wp.editor` is available.
   - If not available, show a safe fallback or prompt reload with media support.
   - Risk: true dynamic loading of WordPress media templates after page load is not trivial.

3. Use materialized inventory as a prediction hint
   - If a page inventory says media/WYSIWYG descriptors are likely, enqueue heavy assets.
   - If not, skip them.
   - Runtime descriptors still decide actual editability.
   - Risk: stale inventory might omit assets for a newly added media field, requiring fallback reload.

Recommended first slice:

- Profile the cost first.
- Split overlay code paths so text/choice/link fields do not require editor/media globals.
- Keep media/editor eager loading until a safe late-load path is proven.
- Then add predictive heavy-asset enqueue from the materialized inventory.

Acceptance:

- Text-only pages load without media/editor payload when the prediction says no media/editor fields exist.
- Media, gallery, image, and WYSIWYG fields still open correctly when assets are loaded.
- If assets are missing, the panel fails visibly and safely, not silently.

### Phase 5. Materialized Visual Editor inventory

Goal:

Use durable precomputed metadata to avoid repeated expensive discovery work and improve asset/prefetch decisions.

This should happen only after Phases 0-4 prove request-time classification remains the bottleneck.

Inventory must not store:

- request session tokens
- nonces
- current editable values
- raw mutable save payloads
- proof that a descriptor is writable for all future requests

Inventory may store:

- page/template identity
- Bricks template ids and hashes
- Bricks element ids, names, labels, parent ids
- static exact dynamic tag candidates
- possible render contexts
- possible ACF field names/selectors
- possible native query roots
- possible Query Editor source hints
- option/global field inventory for configured Shared Globals
- ACF field group metadata hashes
- expected input type hints
- expected heavy asset hints
- invalidation signatures
- last live-validation result counts

Two inventory builders are useful.

#### Static builder

Reads DBVC synced Bricks template JSON and entity JSON from the sync path.

Strengths:

- fast
- can run offline
- reviewable in Git/sync artifacts
- can identify candidate dynamic tags, query roots, and template hashes

Limits:

- cannot prove runtime loop owner
- cannot prove repeater row index
- cannot prove current rendered value
- cannot prove final Query Editor IDs
- cannot make a descriptor writable by itself

#### Live render builder

Requests or renders a page in an inventory-only Visual Editor mode that registers descriptors but does not enqueue overlay UI.

Strengths:

- closest to real runtime
- captures final Bricks query evidence
- can count markers and expensive branches
- can update runtime inventory with validated signatures

Limits:

- more expensive
- requires an authorized context
- must avoid side effects
- still cannot replace the current page-load descriptor session because tokens are request-scoped

Recommended hybrid:

1. Static builder creates candidate inventory and asset hints.
2. Live render builder validates hot pages and stores measured descriptor counts.
3. Normal page load uses inventory only to skip impossible work, choose heavy assets, and prioritize prefetch.
4. Live render instrumentation remains the authority for actual descriptors.

## Inventory Storage Options

### DB table

Best for runtime lookup.

Possible table:

`wp_dbvc_ve_page_inventory`

Columns:

- `id`
- `page_key`
- `page_type`
- `object_type`
- `object_id`
- `archive_key`
- `template_hash`
- `acf_schema_hash`
- `content_hash`
- `inventory_version`
- `status`
- `marker_count`
- `candidate_count`
- `has_media`
- `has_wysiwyg`
- `has_collections`
- `payload_json`
- `generated_at`
- `validated_at`
- `invalidated_at`

Optional second table:

`wp_dbvc_ve_inventory_sources`

Use it only if querying by owner/source is needed. Otherwise keep source details inside JSON.

Pros:

- fast keyed lookup
- easy invalidation status
- good for admin dashboard and toolbar warmup status
- avoids reading large JSON files on frontend requests

Cons:

- schema lifecycle
- migrations
- stale cache risk
- less reviewable than sync-path files

### Sync-path JSON

Best for audit, portability, and DBVC alignment.

Possible paths:

- `{sync_path}/visual-editor/inventory/index.v1.json`
- `{sync_path}/visual-editor/inventory/pages/{page_key}.ve-inventory.v1.json`
- `{sync_path}/visual-editor/inventory/templates/{template_id}.ve-template-inventory.v1.json`

Pros:

- reviewable
- portable
- aligns with DBVC artifact patterns
- useful for comparing sites or template changes

Cons:

- slower for per-request frontend lookup
- needs file locking
- can become large
- not ideal as the only runtime cache

### Recommended storage split

Use the DB table for runtime hints and the sync path for exported inventory artifacts.

The DB row can include a pointer/checksum for the sync artifact. The sync artifact can include the full explainable candidate graph.

## Invalidation Rules

Invalidate inventory when any of these change:

- Bricks template post saved
- Bricks template JSON imported by DBVC
- current page/CPT post saved
- relevant related post saved
- relevant term edited
- relevant ACF option value updated
- ACF field group saved
- ACF local JSON changed
- Visual Editor settings/exclusions changed
- Shared Globals configured field list changed
- Bricks version changes
- active theme or child-theme sync path changes
- DBVC import applies an entity bundle

Invalidation should mark stale first. Rebuild can happen later.

Never use stale inventory to make a descriptor writable. At most use it to:

- decide whether to enqueue heavy assets
- decide where to focus prescan work
- seed likely candidate paths for faster live classification
- show admin diagnostics

## Manual and Scheduled Rebuilds

Recommended controls:

- Visual Editor settings button: `Rebuild performance inventory`
- Toolbar action for current page: `Warm current page`
- WP-CLI command: `wp dbvc ve-inventory rebuild`
- Scheduled low-priority rebuild for stale hot pages
- Per-page rebuild after DBVC import or Bricks template save

Recommended schedule:

- immediate stale marking on source changes
- deferred rebuild in small batches
- cap total pages per run
- skip pages not recently edited or visited unless manually requested

## How Inventory Can Speed Current Runtime

Safe uses:

- Skip `DynamicDataInspector` branches that static inventory proves impossible for a template element.
- Avoid Query Editor source-hint parsing when the element has no query inventory.
- Predict whether WordPress media/editor assets are needed.
- Seed element metadata/parent labels without rebuilding ancestor labels repeatedly.
- Prioritize viewport prefetch for editable/current-owner fields over inspect-only fields.
- Show Review Fields quickly with stable grouping labels.
- Preload configured Shared Globals inventory without scanning current page fallbacks.

Unsafe uses:

- Writing based on inventory alone.
- Trusting stored row indexes from a prior request.
- Trusting stored final query IDs from a prior request.
- Reusing request tokens.
- Skipping render verification for writable descriptors.
- Treating sync-path JSON as current field value truth.

## Near Live Editing UX Recommendations

### Panel open

- Use cached descriptor payload immediately when available.
- For cold opens, show a lightweight panel shell from public metadata while descriptor hydration runs.
- Avoid pre-refreshing the full session before opening a descriptor when the descriptor endpoint can extend TTL.
- Batch viewport prefetch so the next likely panel open is warm.

### Save

- Keep no-reload DOM patching for scalar, image, background-image, and gallery paths where current code already supports it.
- Keep query collections reload-deferred or reload-after-save unless Bricks loop reconciliation becomes provably safe.
- After a save, update cached descriptor payloads and public map entries in place.
- Use the journal for durable change state, not a performance cache.

### Review Fields

- Keep public-map grouping.
- Add virtualization only when profiling shows large row counts.
- Hydrate visible expanded rows in small batches after the index opens.
- Preserve scroll and expanded section state, which the current implementation already does.

### Shared Globals

- Keep configured global inventory separate from current-page fallback descriptors.
- Cache configured option field metadata server-side.
- Do not scan arbitrary option fields on every frontend page.

## Implementation Slices

### Slice A. Performance profiler

Status: first server-side baseline implemented; frontend marks and live browser timing still pending.

Files likely involved:

- `src/Performance/PerformanceProfiler.php`
- `src/Bootstrap/Addon.php`
- `src/Context/PageContextResolver.php`
- `src/Bricks/ElementInstrumentationService.php`
- `src/Bricks/AcfFieldContextResolver.php`
- `src/Bricks/LoopContextResolver.php`
- `src/Bricks/HookRegistrar.php`
- `src/Registry/EditableRegistry.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Rest/Routes.php`
- `src/Rest/DescriptorPayloadBuilder.php`
- `assets/js/overlay-app.js`
- `docs/qa/TEST_LOG.md`

Validation:

- `php -l` for touched PHP files
- browser smoke on one normal page and one marker-heavy page
- confirm profiling disabled by default

### Slice B. Request-local caches

Files likely involved:

- `src/Context/PageContextResolver.php`
- `src/Bricks/AcfFieldContextResolver.php`
- `src/Bricks/LoopContextResolver.php`
- `src/Resolvers/ResolverRegistry.php`

Validation:

- compare descriptor counts before/after on known pages
- save smoke for current, shared, related, repeater/flexible, image, gallery, and query collection paths touched by the cache
- confirm no cross-row owner/path leakage

### Slice C. Batch hydration and heartbeat

Files likely involved:

- `src/Rest/Routes.php`
- `src/Rest/Controllers/SessionController.php`
- new `src/Rest/Controllers/DescriptorBatchController.php`
- new `src/Rest/Controllers/SessionTouchController.php`
- `assets/js/api-client.js`
- `assets/js/overlay-app.js`

Validation:

- initial bootstrap remains public-map-only
- cold panel open works
- cached panel open works
- viewport prefetch batches are capped
- session expiry message still appears when the transient is gone

### Slice D. Browser marker map

Files likely involved:

- `assets/js/overlay-app.js`
- `assets/css/overlay.css` only if badge behavior changes visually

Validation:

- marker surfacing
- badge labels/source classification
- field index Locate/Open
- query-collection container badges
- empty-loop recovery
- media modal outside-click behavior

### Slice E. Heavy asset strategy

Files likely involved:

- `src/Assets/AssetLoader.php`
- `assets/js/overlay-app.js`
- possibly new lightweight feature loaders

Validation:

- text-only page without media/editor payload
- WYSIWYG panel load
- Media Library image save
- gallery panel load and save
- fallback behavior when assets are missing

### Slice F. Materialized inventory

Files likely involved:

- `src/Performance/InventoryStore.php`
- `src/Performance/InventoryBuilder.php`
- `src/Performance/StaticTemplateInventoryBuilder.php`
- `src/Performance/LiveRenderInventoryBuilder.php`
- `src/Admin/SettingsPage.php`
- `src/Rest/Routes.php`
- `src/Rest/Controllers/PerformanceInventoryController.php`
- `commands/` only if adding WP-CLI
- DB migration/bootstrap wiring
- sync-path artifact writer

Validation:

- inventory rebuild does not mutate content
- stale inventory never grants write access
- page render still verifies descriptors live
- DB row and JSON artifact hashes match
- invalidation triggers mark stale on post/template/ACF/settings changes

## Decision Matrix

| Option | Use Now | Why |
| --- | --- | --- |
| Request-local memoization | Yes | Low risk and targets repeated ACF/Bricks work. |
| Batch descriptor hydration | Yes | Reduces REST overhead without changing source truth. |
| Minimal session touch endpoint | Yes | Keeps sessions alive without re-downloading public maps. |
| Browser marker maps | Yes | Reduces repeated DOM scans on marker-heavy pages. |
| Heavy asset deferral | Maybe | High value but WordPress media/editor loading needs careful QA. |
| DB inventory table | Later | Useful only after profiling proves classification is the bottleneck. |
| Sync-path inventory JSON | Later | Good artifact, not the fastest runtime lookup. |
| Cache request tokens in DB | No | Tokens are request/session scoped and stale too easily. |
| Skip render verification from inventory | No | Breaks source-owner safety. |

## Recommended First Sprint

1. Add profiler instrumentation behind a disabled-by-default flag.
2. Capture baseline numbers on:
   - `/vertical/websites-for-contractors/`
   - `/our-process/`
   - a page with connected-items query loops
   - a taxonomy archive with term fields
3. Add request-local caches for page context, ACF provider tags, field objects, field inventories, and loop context.
4. Add a minimal session touch endpoint.
5. Add a batch descriptor endpoint and wire viewport prefetch through it.
6. Re-profile the same pages.
7. Decide whether heavy asset deferral or materialized inventory is the next real bottleneck.

## Current Server-Side Progress

- Disabled-by-default server profiling is implemented, including final render-data repair substep timings for anchor checks, exact existing-marker skips, cached injection, missing-media fallback, marker-token checks, gallery duplicate cleanup, opening-tag regex scans, and cached offset shifting.
- Request-local resolver/loop/query memoization and compressed descriptor session storage are implemented.
- Public session maps are compacted before persistence, and session persistence profiling now exposes the cost split between map export, descriptor compression, and the transient write.
- Phase 2 REST round-trip reduction is started: a minimal touch endpoint avoids re-downloading the public marker map for idle/focus/pre-action session refresh, and a capped batch descriptor endpoint lets viewport prefetch hydrate nearby markers in small groups.
- Direct option-field hints avoid the cold all-option-field scan in the narrow derived Query Editor option-fallback branch.
- Final render-data repair now uses an exact current-opening-tag marker precheck and a fast single-tag lookup before heavier full-page regex repair. A broad page-level token-present skip was rejected because repeated Bricks occurrences can legitimately need the same descriptor token surfaced later in the page.
- Missing-media parent-anchor repair now uses the fast single-tag lookup path and no longer performs full-page opening-tag regex fallback in that branch.

Latest read-only probe checkpoints:

- `php /private/tmp/dbvc_ve_perf_probe.php 86`: 8 markers / 8 descriptors, final repair under 1 ms, 9.3 KB session payload, 4.5 KB public map, and 4.3 KB descriptor blob.
- `php /private/tmp/dbvc_ve_perf_probe.php 24732`: 333 markers / 366 descriptors / 7 query-collection markers, full final repair about 207 ms, 399.8 KB session payload, 241.7 KB public map, 155.0 KB descriptor blob, and `set_transient` about 30 ms on the latest run.
- `php /private/tmp/dbvc_ve_perf_probe.php 23690`: 264 markers / 261 descriptors / 15 query-collection markers, full final repair about 56 ms, 374.1 KB session payload, 233.9 KB public map, 137.7 KB descriptor blob, and `set_transient` about 46 ms.

Remaining measured server-side costs:

- Session persistence still varies around 70-90 ms on marker-heavy pages after public-map compaction. The transient write is now the largest measured substep at roughly 30-50 ms, with public-map export and descriptor compression making up most of the remaining cost.
- Descriptor payload hydration remains roughly 40-60 ms when all descriptors are hydrated in one probe; user-facing cost is lower when lazy hydration and bounded viewport prefetch avoid full-page hydration.
- Active loop-context/instrumentation work remains visible on marker-heavy pages. On `/our-process/`, active loop context is still roughly 90-110 ms and final render repair is still roughly 207-218 ms, so these should be targeted before adding persistent inventory.
- The touch and batch descriptor endpoints still need authenticated browser timing confirmation to verify lower keepalive payloads and fewer viewport-prefetch network requests.
- Browser-authenticated frontend timing confirmation remains pending because the in-app browser smoke did not load an authenticated Visual Editor session.

## Success Targets

Targets should be confirmed from profiling, but these are reasonable working goals:

- Initial Visual Editor extra server work under 250ms on normal pages.
- Initial Visual Editor extra server work under 600ms on marker-heavy pages.
- Initial frontend mount under 100ms after DOM ready on normal pages.
- One initial session request, zero descriptor requests until hover/open/idle warmup.
- Cold descriptor panel open under 200ms on local/staging.
- Warm descriptor panel open under 30ms.
- Keepalive payload under 1KB.
- Viewport prefetch never exceeds configured batch/concurrency caps.
- No save contract changes caused by performance layers.

## Open Questions

- Which live pages have the highest marker count today?
- Is the largest user-visible delay page load, first panel open, Media Library open, save, or reload-after-save?
- How much of active edit-mode payload is caused by WordPress media/editor assets?
- Are marker-heavy pages dominated by direct ACF fields, derived Query Editor collections, native ACF loops, or gallery/media descriptors?
- Should inventory rebuilds prioritize recently visited pages, recently edited pages, or all public content?
- Should sync-path inventory be committed as normal DBVC artifacts or treated as generated diagnostics?

## Non-Negotiables

- Runtime descriptors remain server-authoritative.
- DOM markers stay lightweight.
- Persistent inventory never stores request tokens or nonces.
- Persistent inventory never makes a descriptor writable by itself.
- Render verification remains required for writable descriptors.
- Shared, related, loop-owned, archive, and inspect-only source treatment must not be flattened for speed.
- Performance caches must fail closed. Stale or missing cache means live classification, not guessed writes.
