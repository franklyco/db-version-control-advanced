# RK-011 — Shared `wp.media` factory consolidation

Bounded refactor plan. Consolidates the four `wp.media(...)` construction sites in the Visual Editor addon onto a single shared factory so the Overlay and the Media Manager stop drifting on frame configuration, lifecycle discipline, and prefetch behavior. **This is a refactor, not a product release** — it changes no REST surface, no mutation contract, and no user-visible flow other than the fixed lifecycle in Slice 2.

## Confirmed duplication surface

Four `wp.media(...)` construction sites across the two frontend scripts, with meaningful drift between the two files:

| Concern | `assets/js/overlay-app.js` | `assets/js/media-manager-app.js` |
|---|---|---|
| Feature detect | `supportsWpMedia()` at [overlay-app.js:189](../../../../addons/visual-editor/assets/js/overlay-app.js) | duplicate at `media-manager-app.js:801` |
| Image frame | `overlay-app.js:6942` — memoized in closure across opens | inside `openAssignFrame` at `media-manager-app.js:3440`, single-active-frame |
| Gallery frame | `overlay-app.js:7328` — freshly constructed every open, never disposed | same `openAssignFrame`, `isGallery` branch |
| Lifecycle discipline | image cached, gallery leaks (a fresh frame per open, listeners retained) | `disposeActiveFrame()` (R2-E3 RK-011 remediation) tears down the prior frame on open/collapse/group-switch/list-reload/close |
| Prefetch hook | `bindMediaFramePrefetchState()` at `overlay-app.js:929` — overlay-only | absent |
| Selection normalization | `mapMediaSelectionToGalleryItem` returns a full descriptor entry | `normalizeAttachmentSelection` returns `{id,url,alt,title}` — different domain models by design |
| Modal-layering guard | `mediaModalIsOpen()` in each file — different DOM contexts (overlay panel Escape vs Media Manager dialog Escape) |

The overlay's image-frame closure and gallery-no-dispose paths pre-date the R2-E3 remediation and re-introduce exactly the leak that remediation addressed — just on the overlay side. Slice 2 closes that.

## Slices

### Slice 1 — extract the factory (behavior-neutral)

New file `addons/visual-editor/assets/js/media-frame-factory.js` enqueued as a shared dependency of both scripts (mirrors the `api-client.js` wiring pattern already used). Exports one function on `window.DBVCVisualEditorMediaFrame`:

```
createMediaFrame({
  mode: 'single' | 'multiple',
  title: string,
  buttonText: string,
  previousFrame?: object,
})
  → { frame, dispose }
```

Both files replace their `window.wp.media({...})` blocks with this call. Selection handling stays in each caller (the domain models differ). `previousFrame` is disposed before the new one is minted, so the caller can pass its current frame reference and adopt the single-active-frame pattern the Media Manager already enforces.

**In scope**: config synthesis, disposal helper (idempotent `detach`/`remove`/`dispose` guarded for jsdom mocks), feature-detect null-frame fallback.

**Out of scope**: selection normalization, prefetch, modal-layering guards.

**Success gates**:
- jsdom coverage for the factory: single mode + multiple mode config synthesis; disposal is idempotent; missing `window.wp.media` returns `{frame: null, dispose: noop}`.
- Existing jsdom media-manager suite (38) unchanged.
- Media Manager PHP subset (115) unchanged.
- Media Manager and overlay behavior unchanged in Playwright.

### Slice 2 — overlay adopts single-active-frame discipline

Overlay's image frame stops being memoized in the closure; overlay's gallery frame gets disposed on close / re-open via the factory. Ported cleanly because Slice 1 already isolated construction. Uses the same `disposeActiveFrame` pattern the Media Manager already enforces (R2-E3, RK-011 remediation).

**In scope**: change the two overlay call sites to pass `previousFrame` and to null out the reference on close.

**Success gates**:
- New overlay jsdom cases mirroring the R2-E3 tests: repeated overlay opens keep at most one live frame; closing the overlay panel disposes it.
- Existing overlay behavior unaffected in Playwright.
- No REST/mutation change.

### Slice 3 — prefetch parity (CLOSED: not applicable, 2026-08-19)

**Outcome**: no code change. Closer inspection of `bindMediaFramePrefetchState` proved the premise wrong.

The overlay's `bindMediaFramePrefetchState` is not a generic prefetch hook — it mutates overlay-internal state (`state.mediaModalOpen`, used at `overlay-app.js:332` to pause the overlay's own descriptor prefetch while a media modal is up) and drives the overlay's descriptor-viewport prefetcher (`clearViewportPrefetchSchedule` / `scheduleViewportPrefetch`, which prefetches descriptor payloads for visible field markers on the page). The Media Manager has no descriptor-marker prefetcher — it has its own scan/list lifecycle — so moving the hook into the shared factory would either break the Media Manager (it'd try to touch overlay-only state) or be a no-op there (defeating the point).

The "prefetch parity" premise was based on a misread of what the hook actually does. The correct scope for the shared factory ends at frame configuration + disposal (Slice 1) and single-active-frame discipline (Slice 2). The overlay's descriptor prefetch stays overlay-owned.

**D-049 real-browser QA** — deferred, not run in this session. Environment constraints: no already-authenticated session on `dbvc-codexchanges.local`, and the recorded process constraints prohibit logging in, entering credentials, or changing LocalWP state to satisfy a test. If a future authenticated session becomes available, the residual gate to prove is: repeated Media Manager assign/replace opens produce no accumulating listeners over long sessions (Slice 2's jsdom coverage proves the discipline; real-browser memory profiling proves it end-to-end).

### RK-011 status

**Fully mitigated by Slices 1 + 2.** No further work planned. Real-browser memory/listener profiling over long sessions remains the residual D-049 gate, unchanged from the R2-E3 close-out.

## Explicitly out of scope

- Merging the two selection-normalization functions — they have different domain models by design.
- Consolidating `supportsWpMedia()` — already just a bootstrap-flag check; deduping the two callers is a cosmetic change that can be folded into Slice 1 if trivial.
- Consolidating `mediaModalIsOpen` — lives in different DOM contexts (overlay panel Escape guard vs Media Manager dialog Escape guard). Merging risks changing focus/Escape behavior.
- Any change to the shared audited mutation pipeline, journal, cache invalidation, or descriptor bridge.

## Sequencing (as-shipped)

Slice 1 landed 2026-08-19 (behavior-neutral extraction). Slice 2 landed 2026-08-19 (overlay adopts single-active-frame discipline). Slice 3 was investigated and closed as **not applicable** — the "shared prefetch hook" premise did not survive reading the actual code (see Slice 3 section above). RK-011 is fully mitigated by Slices 1 + 2.

## Tracking

- Risk register: `tracking/RISK-REGISTER.md` — RK-011 already has two entries (R2-B mitigation, R2-E3 mitigation). This work closes the shared-factory obligation from the R2-B row.
- Evidence log: `tracking/EVIDENCE-LOG.md` — one entry per shipped slice.
- Changelog: `addons/visual-editor/CHANGELOG.md` — plain-language user-facing entry when a slice ships.
- Canonical handoff: `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md` — remove RK-011 from the "next bounded tasks" list once all landed slices close it.
