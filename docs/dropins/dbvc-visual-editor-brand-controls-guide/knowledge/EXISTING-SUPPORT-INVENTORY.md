# Existing Visual Editor infrastructure — adoption inventory

**Audience:** any agent planning a next slice (R3-B, R3-C, R3-D, R4, R5.x, R6, or the eventual VerticalControlProvider). Read this before designing a new controller, a new REST route, a new mutation path, a new admin flag, or a new frontend module — most of what a new slice needs already exists in production and is proven by tests.

**When code and this doc disagree, the code wins.** Every claim in here is anchored by a file:line reference so re-verification is cheap.

**Format:** three sections.
1. §1 — **ACF family support matrix**: for each ACF field family, whether a resolver / controller / descriptor path exists today, and what R5 slice it maps to.
2. §2 — **Cross-cutting reusable infrastructure**: the shared plumbing every slice can lean on.
3. §3 — **Per-planned-slice adoption checklist**: for each upcoming slice, what to reuse vs. what to build fresh.
4. §4 — **Known gaps** — what deliberately isn't supported yet, so nobody plans against a phantom.

---

## §1 — ACF family support matrix

Every row here is verified against a live resolver file + the `createFieldController` switch in [overlay-app.js:10904](../../../../addons/visual-editor/assets/js/overlay-app.js#L10904). Owner scope is **post / term / user / option** unless noted — the `AbstractAcfResolver::writeAcfValue()` pathway (with `AcfObjectId` handling at [ResolverRegistry.php:2836](../../../../addons/visual-editor/src/Resolvers/ResolverRegistry.php#L2836)) supports all four owner types uniformly.

| ACF family | Resolver | Frontend controller | `input` key routed via `createFieldController` | Option-owned save? | R5 slice | Status |
|---|---|---|---|---|---|---|
| `text` | `AcfTextResolver` | `createInputController('text', …)` | `text` (default case) | ✓ | R5.1 | **Ready** |
| `textarea` | `AcfTextResolver` | `createTextareaController` | `textarea` | ✓ | R5.1 | **Ready** |
| `url` | `AcfTextResolver` | `createInputController('url', …)` | `url` | ✓ | R5.1 | **Ready** |
| `email` | `AcfTextResolver` | `createInputController('email', …)` | `email` | ✓ | R5.1 | **Ready** |
| `number` | `AcfTextResolver` | `createInputController('number', …)` | `number` | ✓ | R5.1 | **Ready** |
| `range` | `AcfTextResolver` | `createInputController('number', …)` | reuses `number` | ✓ | R5.1 | **Ready** |
| `wysiwyg` | `AcfWysiwygResolver` | `createRichTextController` (auto-picks WP TinyMCE via `wp-editor` when registered, otherwise `createFallbackRichTextController` contenteditable) | `richtext` | ✓ (`wp_kses_post` sanitize, `acf_the_content` display) | R5.2 | **Ready** — sample confirmed: `Universal Prompt (pages)` works |
| `checkbox` | `AcfChoiceResolver` | `createCheckboxGroupController` | `checkbox_group` | ✓ | R5.2 | **Ready** |
| `select` | `AcfChoiceResolver` | `createSelectController` | `select` | ✓ (multi handled via `$field['multiple']` — see [ResolverRegistry.php:838](../../../../addons/visual-editor/src/Resolvers/ResolverRegistry.php#L838)) | R5.2 | **Ready** |
| `radio` | `AcfChoiceResolver` | `createSelectController` (single-select subset) | `select` | ✓ | R5.2 | **Ready** |
| `button_group` | `AcfChoiceResolver` | `createSelectController` (single-select subset) | `select` | ✓ | R5.2 | **Ready** |
| `link` | `AcfLinkResolver` | `createLinkController` (stacked URL + title + target inputs) | `link` | ✓ | R5.2 | **Ready** |
| `image` | `AcfImageResolver` | `createMediaReferenceController` (native `wp.media` single-image frame) | `media_reference` | ✓ | R5.3 | **Ready** — the R2 Media Manager pipeline for post/term-owned images extends to options |
| `gallery` | `AcfGalleryResolver` | `createMediaGalleryReferenceController` (ordered multi-image `wp.media` frame) | `media_gallery_reference` | ✓ | R5.3 | **Ready** |
| `post_object` (single) | `AcfReferenceLinkResolver` | `createReferenceCollectionController` (single-select subset) | `reference_collection` | ✓ (Shared Globals popover already exercises option-owned single post_object via `SharedGlobalFieldsController`) | R5.4 (post_object leaf); **already usable in R3-B** for the Shared Globals compat provider path | **Ready** |
| `post_object` (multi) / `relationship` | `AcfReferenceCollectionResolver` | `createReferenceCollectionController` | `reference_collection` | ✓ (Shared Globals popover already exercises option-owned relationship via `SharedGlobalFieldsController`) | R5.4; **already usable in R3-B** | **Ready** |
| `taxonomy` | `AcfReferenceLinkResolver` supports it (see [AcfReferenceLinkResolver.php:28](../../../../addons/visual-editor/src/Resolvers/AcfReferenceLinkResolver.php#L28) — `['post_object', 'relationship', 'taxonomy']`) | `createReferenceCollectionController` | `reference_collection` | ✓ | R5.4 | **Ready** |
| `group` | — (structural container, not editable) | — | — | N/A | **N/A — skipped by curation walker** (see [FieldCandidateProvider::walkFields](../../../../addons/visual-editor/src/Curation/FieldCandidateProvider.php)) | **N/A** |
| `repeater` | not supported (deliberate) | — | — | — | Future repeater phase | **Not yet** — recommender pre-marks `defer`; no R5 slice serves it |
| `flexible_content` | not supported (deliberate) | — | — | — | Future repeater phase | **Not yet** — pre-marked `defer` |
| `clone` | not supported (deliberate) | — | — | — | Future repeater phase | **Not yet** — pre-marked `defer` |
| `true_false` | *no dedicated resolver;* would fall through to `AcfChoiceResolver` if wired, but not currently in the `supports()` whitelist at [AcfChoiceResolver.php:24](../../../../addons/visual-editor/src/Resolvers/AcfChoiceResolver.php#L24) | *(no direct controller yet)* | — | — | Would fit R5.2 as a natural add | **Small gap** — one-line resolver whitelist addition + a two-state controller (or reuse `checkbox_group` with cardinality 1). Add during R5.2 or as a mini-slice. |
| `color_picker` | *no dedicated resolver* — the recommender maps it to `R5.2+color_picker` because Vertical's `vertical_global_palette` is 19 color_picker slots | *(no direct controller yet)* | — | — | Fold into R5.2 or R5.2b | **Small gap** — new `AcfColorResolver` (sanitize via `sanitize_hex_color`) + a color-picker controller (WordPress's `wp-color-picker` is already available on admin — needs frontend enqueue) |
| `date_picker` / `time_picker` / `date_time_picker` | *no dedicated resolver* — recommender maps to `R5.1` because they're scalar; would need format handling | *(no direct controller yet)* | — | — | R5.1b or later | **Gap** — needs date-format-aware resolver + native `<input type="date">` controller with the ACF display-format contract |
| `google_map` | not supported | — | — | — | Later (specialized) | **Not yet** — pre-marked `defer` by recommender (`FAMILY_UNLOCK_MAP` → `later`) |
| `oembed` | not supported | — | — | — | Later (specialized) | **Not yet** — pre-marked `defer` |
| `file` | not supported | — | — | — | Later (extends R2 Media Manager) | **Not yet** — pre-marked `defer`; would extend media-frame factory to non-image files |
| `font-awesome` (theme-supplied) | not supported | — | — | — | Later (specialized-select) | **Gap** — could reuse `select` controller with a large option list; needs a bounded catalog |
| `user` | not supported yet as a first-class ACF field family | — | — | — | R5.4 (user leaf) | **Not yet** — `summarizeAcfObjectIdForProfile` at [ResolverRegistry.php:2841](../../../../addons/visual-editor/src/Resolvers/ResolverRegistry.php#L2841) already understands `user_*` owner shape for other resolvers, but no dedicated field-object resolver |

**Ancillary controllers already in the switch table** (worth knowing when writing R5.x descriptor factories):
- `composite_text` — used for R2 Media Manager–style composite reads with multiple sub-values.
- `reference_collection_preview` — read-only variant of the reference collection.
- `readonly_preview` — used with `AcfReadonlyResolver` for inspect-only rows.
- `media_gallery_preview` — read-only gallery display.

**Native (non-ACF) resolvers that also exist** — useful for R6 Site Manager or any future non-ACF field surface:
- `PostTitleResolver` — native `post_title` scalar
- `PostExcerptResolver` — native `post_excerpt`
- `PostFeaturedImageResolver` — native `_thumbnail_id` via `set_post_thumbnail`/`delete_post_thumbnail`
- `PostTermsCollectionResolver` — native post↔term assignments
- `TermFieldResolver` — native term `description`

---

## §2 — Cross-cutting reusable infrastructure

Beyond the per-family resolvers/controllers, the following plumbing is production, well-tested, and directly re-usable by any new slice. Do NOT re-invent these.

### §2.1 Descriptor system

- **`EditableDescriptor`** ([src/Registry/EditableDescriptor.php](../../../../addons/visual-editor/src/Registry/EditableDescriptor.php)) — the authoritative "one editable field" value object. Every controller in the switch table consumes it. Providers rebuild it at open time from their own opaque source hint; the browser never gets one direct.
- **`EditableRegistry`** ([src/Registry/EditableRegistry.php](../../../../addons/visual-editor/src/Registry/EditableRegistry.php)) — session-bound descriptor store with opaque public tokens, 8-hour default TTL, keepalive, safe list projection. R3-C's descriptor factory hands its built descriptor to this registry the same way Shared Globals and Media Manager already do.
- **`ResolverRegistry`** ([src/Resolvers/ResolverRegistry.php](../../../../addons/visual-editor/src/Resolvers/ResolverRegistry.php)) — routes any descriptor to the right resolver by owner + field_type. The mapping is already established for every family in §1; a new family only needs a resolver + a `supports()` clause.

### §2.2 Mutation pipeline

Everything mutating goes through **`MutationService`** ([src/Save/MutationService.php](../../../../addons/visual-editor/src/Save/MutationService.php)). It handles:
- Resolver routing
- Validation + sanitization (families own their own via `validate()` / `sanitize()`)
- Journal write via **`ChangeJournalRecorder`** ([src/Journal/ChangeJournalRecorder.php](../../../../addons/visual-editor/src/Journal/ChangeJournalRecorder.php))
- Audit hook `dbvc_visual_editor_audit_event`
- Cache invalidation via **`CacheInvalidator`** ([src/Cache/CacheInvalidator.php](../../../../addons/visual-editor/src/Cache/CacheInvalidator.php))
- Old-value read for reconcile
- Optional expected-old-value precondition (already used by the R2-C `MediaAssignmentService`)

R3-C, R4, R5.x — **all mutations route through here**. No slice needs its own write authority.

### §2.3 REST route conventions

Every existing route matches this shape (see any file in [src/Rest/Controllers/](../../../../addons/visual-editor/src/Rest/Controllers/)):
- Namespace `dbvc/v1`, prefix `/visual-editor/…`
- Nonce enforcement inherited from WP REST auth
- `permission_callback` returns `$this->capabilities->canUseVisualEditor()` — always includes the base capability + logged-in gate ([src/Permissions/CapabilityManager.php:12](../../../../addons/visual-editor/src/Permissions/CapabilityManager.php#L12))
- Handlers additionally check `$this->edit_mode->isRestRequestAuthorized()` for active-mode gating
- `CapabilityManager::canEditDescriptor($descriptor)` re-checks per-descriptor authority at save time
- Response projections carry safe display data only — never `field_key`, `field_name`, `selector`, `path`, `ownerId`, `acf_object_id`, or the internal `source` bag

Copy the `SharedGlobalFieldsController` or `MediaManagerController` skeleton verbatim for any new route.

### §2.4 Bricks Builder exclusion

- **`FrontendRuntimeGuard`** ([src/Context/FrontendRuntimeGuard.php](../../../../addons/visual-editor/src/Context/FrontendRuntimeGuard.php)) — the single source of "should the runtime load here?" Already understands the Bricks Builder request family.
- **`AssetLoader::enqueue`** ([src/Assets/AssetLoader.php:44](../../../../addons/visual-editor/src/Assets/AssetLoader.php#L44)) skips all asset injection inside Bricks Builder.
- Any new frontend module must NOT enqueue outside `AssetLoader`'s already-guarded path.

### §2.5 Feature flag / kill-switch pattern

Every user-facing feature ships default-off with a two-part gate:
- Persistent option (e.g. `dbvc_visual_editor_media_manager_enabled`, `dbvc_visual_editor_curation_tool_enabled`)
- Static helper on `DBVC_Visual_Editor_Addon` (e.g. `is_media_manager_enabled()`, `is_curation_tool_enabled()`)

Wiring:
- Register the option in `ensure_defaults()` + `get_all_settings()` + `save_settings()` + `get_settings_groups()` + `get_field_meta()` — all in [bootstrap.php](../../../../addons/visual-editor/bootstrap.php).
- Bump `SETTINGS_VERSION` when a new option lands (existing tests trigger the version-bump assertion).
- Gate the runtime feature on the helper. When off: no menu item, no route registration, no asset enqueue.

R3-C's kill switch (`dbvc_visual_editor_control_center_enabled`, planned) drops in exactly this shape.

### §2.6 Media frame factory (RK-011)

- **`assets/js/media-frame-factory.js`** — the SOLE `wp.media(...)` construction site for the entire addon. Both the main overlay and the Media Manager go through it.
- Bounded to **one active frame** at a time; opening a second disposes the first.
- Any new slice that opens a media picker (R5.3, R5.3-ish curation, R6) must use this factory — do NOT call `wp.media()` directly.

### §2.7 Persistent Media Index (R2-H, five slices)

Applicable to any future "durable read-side accelerator" pattern:
- Custom table + JSON export mirror at `{sync}/…` (backup-portable)
- Serving/building generation split for atomic rebuilds ([D-056](../tracking/DECISION-LOG.md))
- Read-time per-user capability filtering (index rows are structural, not authoritative)
- Action Scheduler with WP-Cron fallback
- Guarded importer on bootstrap (never overwrites populated tables)

R3 doesn't need this pattern, but R6 (Site Manager) or a Vertical-provider caching layer might.

### §2.8 Frontend UI conventions

- **Toolbar button helper** — `createToolbarButtonMarkup(action, iconName, label, extraClass, isSatellite)` at [overlay-app.js:2720](../../../../addons/visual-editor/assets/js/overlay-app.js#L2720). Reuse for every new toolbar entry.
- **Icon registry** — `renderToolbarIcon(name)` at [overlay-app.js:2700](../../../../addons/visual-editor/assets/js/overlay-app.js#L2700). `sliders` is registered ahead of R3-C.
- **Popover shell** — `.dbvc-ve-statusbar-popover` classes (Shared Globals uses this). R3 does NOT reuse the popover shell (D-061); R6 might.
- **Editor panel shell** — `.dbvc-ve-panel` classes. R3-C's drawer opens rows INTO this same panel; do not fork it.
- **Row-focus continuity** — Media Manager's `body.ownerDocument.activeElement` snapshot pattern. Every list surface that rerenders (drawer, Media Manager, R6 Site Manager) must restore focus to the row that initiated an action.
- **Single polite live region** — one `<div aria-live="polite" aria-atomic="true">` per shell. Do NOT add a second.
- **Reduced motion** — `@media (prefers-reduced-motion: reduce)` disables the disclosure transition in Media Manager; R3-C's drawer slide-in must do the same.
- **BEM scoping** — every UI namespace uses `.dbvc-ve-{component}__{element}--{modifier}`. Never leak unscoped selectors.
- **Style tokens** — every color, font, z-index, spacing comes from `--dbvc-ve-*` tokens in [overlay.css](../../../../addons/visual-editor/assets/css/overlay.css). Adding a new token is a separate PR against overlay.css root.

### §2.9 REST + capability + nonce for admin AJAX

Same pattern as the existing Shared Globals popover and Media Manager REST routes, but for admin AJAX (used by the R3-BX curation page):
- `add_action('wp_ajax_{action}', [$this, 'handler'])`
- `wp_verify_nonce($_POST['nonce'], $action_constant)` at the top of every handler
- `current_user_can('manage_options')` (or narrower) gate
- `wp_send_json(...)` for the response

Copy the R3-BX `CurationPage::verifyAjax()` skeleton verbatim.

### §2.10 Filesystem export pattern

For any slice that emits a committable artifact (R3-BX's curation JSON, R2-H Slice 5's media-index JSON):
- Target path under `wp-content/plugins/db-version-control-main/{module}/{artifact}.{json,md,…}` — inside the plugin so it moves with a git deploy
- `wp_mkdir_p($dir)` before write
- `file_put_contents($path, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n")`
- Return `['ok' => bool, 'message' => string, 'path' => string, …]` for the AJAX/REST caller
- Companion `.md` review sheet where the audience is human (grouped by whatever axis matters — R5 slice, category, priority)

### §2.11 Asset URL + versioning

**Every enqueue must:**
- Anchor `plugins_url('', $absolute_addon_file_path)` on a REAL file inside the addon (e.g. the addon's own `bootstrap.php`). Do NOT compute the plugin root via `dirname(__DIR__, N)` and append the plugin main file — that's brittle (see the R3-BX E-090 post-landing bugfix for the exact failure mode).
- Version via `filemtime($absolute_asset_path)` so the browser cache auto-busts on every edit. Fall back to a static version if `is_readable()` fails.
- Match the pattern already established in [src/Assets/AssetLoader.php:608](../../../../addons/visual-editor/src/Assets/AssetLoader.php#L608).

### §2.12 Change journal + audit + cache

Zero-boilerplate for a slice that goes through `MutationService`:
- Every successful mutation records a `completed` journal item in `{prefix}dbvc_ve_change_items`
- Every failed mutation records a `failed` journal item (no audit fire, no cache invalidation)
- `dbvc_visual_editor_audit_event` fires with `{resolver_name, before, after, user_id}`
- `dbvc_visual_editor_invalidate_cache` fires with the entity ref
- WordPress object cache for the entity is flushed automatically

Any new slice's mutation path gets all of this for free by routing through `MutationService`.

---

## §3 — Per-planned-slice adoption checklist

For each upcoming slice, what to reuse vs. what to build fresh. This is intentionally short — the goal is "don't rederive."

### R3-B — Shared Globals compatibility provider (headless)

**Reuse:**
- `AcfReferenceCollectionResolver` + `AcfReferenceLinkResolver` for the underlying save path (already what `SharedGlobalFieldsController::handle()` uses).
- `CapabilityManager::canEditDescriptor()` — mirror the exact `canManageSharedGlobalOptions` probe from `SharedGlobalFieldsController`.
- Kill-switch pattern (§2.5) — new `dbvc_visual_editor_control_center_enabled` option.
- `ControlRegistry` (R3-A) + `ControlProvider` interface — the registration surface is already there.
- `DBVC_Visual_Editor_Addon::get_shared_global_field_names()` — the configured field-name list is already exposed.

**Build new:**
- One PHP class: `SharedGlobalsControlProvider implements ControlProvider` under `src/Registry/Providers/`.
- Registration line in `Bootstrap/Addon::register()` under the kill-switch gate.

That's it. Bounded to ~2 files + tests + docs. No new resolver, no new controller, no new mutation authority.

### R3-C — Minimal Brand Control Center drawer + REST routes

**Reuse:**
- The full descriptor / mutation / journal pipeline (§2.1, §2.2, §2.3).
- Every family resolver in §1 (they already handle option-owned).
- Every controller in the switch table (§1) — R3-C opens rows into the existing panel.
- Toolbar helper + icon registry (§2.8) — `sliders` icon already registered.
- Kill-switch pattern (§2.5) — same `dbvc_visual_editor_control_center_enabled` gate as R3-B.
- Bricks-Builder exclusion (§2.4) — inherit via `AssetLoader`.
- Row-focus continuity + single live region + reduced-motion patterns (§2.8).
- Client-side filter engine pattern from R3-BX curation (§2.9-ish — proven at ~150 rows).

**Build new:**
- Two REST controllers: `ControlCenterListController` + `ControlCenterOpenController`.
- One frontend module: `brand-control-center-app.js` (analogous to `media-manager-app.js`).
- Drawer CSS scoped under `.dbvc-ve-control-center`.
- New z-index token `--dbvc-ve-z-drawer` = 120015 (declared in overlay.css root).
- Per-provider descriptor factory seam — probably a small `ControlProvider::buildDescriptor($record)` method addition (a natural extension of the R3-A interface, decided when R3-B implementation lands).

Bounded to one composition slice. Everything the drawer opens INTO already exists.

### R3-D — Hardening

**Reuse:**
- All of R3-C's plumbing above.
- Capability + nonce test patterns from `VisualEditorMediaManagerR2E2Test` (proven fail-closed guard set).
- Bricks Builder exclusion tests from the Media Manager suite.

**Build new:**
- Test coverage that mirrors R2-E2's shape (forged/stale/permission-revoked inputs each fail closed).
- Release-notes + rollback runbook (mirrors `MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md`).

### R4 — Expanded drawer (categories, richer UI)

**Reuse:**
- The R3-C drawer shell — this is a UI-only expansion, not a new backend surface.
- Registry + provider contract — R4 reads more, provides no new authority.

**Build new:**
- UI-only: richer categorization, pinning, workspaces, usage indexing — all consume the same registry.
- No new resolvers, no new descriptors, no new mutations.

### R5.1 — Scalar text-like on option pages

**Reuse:**
- `AcfTextResolver` (already handles text/textarea/url/email/number/range on option owners).
- `createTextareaController` / `createInputController` (already routed).
- Descriptor factory pattern established by R3-C.

**Build new:**
- Extend the Vertical provider's descriptor factory to cover the R5.1 families (~one branch per family, all delegating to the same shape).
- One integration test per family confirming option-owned round-trip.

### R5.2 — Choice / link / WYSIWYG on option pages

**Reuse:**
- `AcfChoiceResolver` (checkbox/select/radio/button_group), `AcfLinkResolver` (link), `AcfWysiwygResolver` (wysiwyg).
- Corresponding controllers already routed via `createFieldController`.
- `wp-editor` dependency already registered in `AssetLoader` for the wysiwyg TinyMCE.

**Build new:**
- Vertical provider descriptor branches for these families.
- **Small gap to close in R5.2 or R5.2b:** add `AcfColorResolver` + `wp-color-picker` controller. Vertical's `vertical_global_palette` is 19 `color_picker` slots — the single highest-visual-impact bundle in the entire curation set (see the palette assessment in [releases/R3-…md](../releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md)).
- **Small gap:** add `true_false` to `AcfChoiceResolver::supports()` whitelist or ship a two-state controller.

### R5.3 — Image + gallery on option pages

**Reuse:**
- `AcfImageResolver` + `AcfGalleryResolver`.
- `createMediaReferenceController` + `createMediaGalleryReferenceController`.
- Shared media-frame factory (§2.6) — one active frame across overlay + Media Manager + R5.3.
- R2-C's `MediaAssignmentService` pattern for option-owned image assignment / replacement.

**Build new:**
- Vertical provider descriptor branches for image + gallery.
- One integration test per family.

### R5.4 — Connected / taxonomy on option pages

**Reuse:**
- `AcfReferenceCollectionResolver` (relationship, multi post_object) + `AcfReferenceLinkResolver` (single post_object, taxonomy).
- `createReferenceCollectionController`.
- `SharedGlobalsControlProvider` (R3-B) already validates the pattern end-to-end for option-owned relationship / post_object.

**Build new:**
- Extend the Vertical provider descriptor factory for post_object / relationship / taxonomy leaves that weren't already in the Shared Globals set.

### R6 — Frontend Site Manager Workspace

**Reuse:**
- Native `Post*Resolver` + `TermFieldResolver` families (post_title, post_excerpt, featured image, term description).
- Object-search / navigation via `ObjectSearchController`.
- Drawer shell if the workspace is drawer-adjacent, or a new dedicated shell if it's larger.

**Build new:**
- Deferred — R6 is a workspace, out of R3 scope entirely. This inventory just marks the reusable pieces.

### VerticalControlProvider (eventual, seeded from R3-BX curation JSON)

**Reuse:**
- Every resolver + controller in §1.
- `ControlProvider` interface (R3-A).
- The JSON export shape from `CurationExporter` — the export IS the seed, no translation layer needed.

**Build new:**
- A single class in `vertical/` (theme repo) that loads the JSON and returns `ControlRecord[]` per its `records[]` array. Descriptor factory per family (identical shape to the R3-B `SharedGlobalsControlProvider` for the reference-collection families, plus per-family branches for whichever families the curated set includes).

---

## §4 — Known gaps

Deliberately unsupported today. Do not plan against them without an explicit slice to close the gap.

- **Repeater / flexible_content / clone** — the descriptor system can address a repeater subfield today for the R2 Media Manager's group-scoped writes, but there is no general "edit a repeater as a unit" support and no `createRepeaterController`. Skipping repeater subfields is the R3-BX candidate-provider policy.
- **`color_picker`** — 19-slot Vertical palette waiting on this. No resolver, no controller. Small mini-slice to close.
- **`true_false`** — trivial to add to `AcfChoiceResolver`; not done yet.
- **`date_picker` / `time_picker` / `date_time_picker`** — no format-aware resolver; needs display-format handling per ACF's return format.
- **`google_map` / `oembed` / `file` / `font-awesome`** — no support. All pre-marked `defer` by the R3-BX recommender.
- **`user` as an ACF field family** — no dedicated resolver. User-owner storage IS understood (`user_*` acf_object_id shape); the `user` field TYPE is not.
- **Batch save / bulk-edit / undo** — no `EditableRegistry`-level batch contract for multi-field commit. R2-C's `MediaAssignmentService` is single-field per request; R2-F Slice 4 kept the same contract. Adding batch is an explicit slice, not implicit.
- **Cross-entity `Save Row`** — deferred by D-010; needs an approved general media preflight/outcome/compensation contract.
- **Static Bricks image settings** — out of scope for R3-R6. Bricks-Builder tokens are managed inside Bricks, not through the Visual Editor descriptor system.
- **PNG mockup screenshots for R3** — deferred (SVG wireframes stand as the visual reference). Not a code gap; a docs-workflow gap.

---

## Maintenance

This doc is a snapshot. Update it when:
- A new resolver lands under `src/Resolvers/` → add a row to §1.
- A new controller lands in `overlay-app.js`'s switch table → update the `input` key column in §1.
- A cross-cutting pattern gets promoted to production → add to §2.
- A gap in §4 gets closed → move the row to §1 or §2 as appropriate.

If you're an agent starting a new slice, read §3 first for your slice, then jump to the referenced §1/§2 rows. Do not re-derive from scratch.
