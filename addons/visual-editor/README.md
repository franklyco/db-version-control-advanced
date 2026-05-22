# DBVC Visual Editor

DBVC Visual Editor is a DBVC addon that enables authorized editors to activate a frontend overlay on Bricks-rendered pages and edit supported dynamic content in place.

The addon is designed around a server-side descriptor registry and Bricks render-time instrumentation. The frontend should only interact with lightweight markers and authenticated endpoints, never raw guessed meta targets.

## Status

Initial repo-adapted MVP slice implemented.

Current status:
- addon bootstrap is wired into DBVC core loading
- Add-ons screen can enable/disable the runtime
- authorized users can toggle frontend Visual Editor mode from the admin bar
- frontend assets load only in edit mode on supported singular and archive views
- frontend runtime, assets, and Bricks instrumentation are now explicitly blocked inside Bricks Builder edit/main/iframe requests and common builder-style preview query contexts
- supported Bricks direct single-tag text bindings receive lightweight DOM tokens
- exact Bricks dynamic-data args on those single-tag bindings are preserved for resolver context
- mismatched rendered nodes are filtered back out during Bricks element render verification
- top-level Bricks `link` controls with direct single-tag external ACF bindings can now mark and edit the rendered `href` target safely
- image-style Bricks `link = url` controls with a direct single-tag external ACF binding now reuse that same guarded `href` flow
- repeater-style Bricks collection anchors such as `list`, `social-icons`, and custom-link `image-gallery` items can now carry their own guarded `href` markers
- direct Bricks image elements backed by either a single-tag ACF image field or a direct WordPress `{featured_image}` binding can now surface a marker on the wrapper and switch to another existing Media Library attachment through an attachment-ID-first save contract, with local Media Library URL fallback resolution when needed
- direct Bricks `_background.image` controls backed by a single-tag ACF image field can now reuse that same attachment-aware media workflow for rendered background images
- direct Bricks image-gallery elements backed by a single-tag ACF gallery field can now surface an editable marker with thumbnail preview and ordered Media Library gallery replacement
- direct Bricks native ACF `relationship` and `post_object` query roots on the current page owner can now surface a container-level `Edit Connected` marker with a dedicated connected-items panel mode and reload-after-save reconciliation
- direct Bricks ACF repeater row subfields can now be marked and resolved through stable parent-repeater metadata plus Bricks loop index
- current-post and related-post repeater row descendants can now reuse the existing safe field resolvers for text-like, WYSIWYG, choice, link, and image field types
- descriptor state is kept server-side in a filterable transient session registry, now with a longer default lifetime plus client keepalive/focus refresh so an open editor session does not age out after only a few idle minutes
- authenticated REST inspection and save endpoints are available for the MVP allowlist
- ACF object context now resolves through Bricks provider logic for current-post, options, term, and user-backed fields on singular views
- current-post ACF `post_object`, single-target `relationship`, and single-select `taxonomy` fields can now be edited through their rendered permalink when Bricks uses them as direct link targets
- current-post, shared, and safe related-post ACF `image` fields can now be edited when Bricks renders their direct image source
- Bricks ACF `relationship` and `post_object` query loops can now mark safe related-post loop rows and surface that related-post ownership in the modal
- related-post loop saves now require explicit acknowledgement and update the related post shown in the loop rather than the current page post
- direct safe ACF fields rendered from Bricks query loops with a concrete post, term, or user owner can now save against that loop owner instead of being forced into inspect-only mode
- native Bricks ACF query-loop roots are now classified from `query.objectType` metadata so repeater, relationship, and post-object loops can be widened intentionally without introducing DOM-guessing fallbacks
- native Bricks ACF repeater loops are now hardened against shortened parent aliases, duplicate child keys, nested grouped row descendants, repeated-loop seed collapse, and fake related-owner classification from bare numeric loop indices
- native Bricks ACF repeater-in-repeater descendants now canonicalize back to the outer repeater root, preserve explicit nested repeater row ancestry in descriptor paths, and read/write against the actual stored nested row tree instead of flattening to the innermost loop only
- direct Bricks ACF flexible-content descendants can now surface with stable row + layout metadata, and text-like, WYSIWYG, choice, link, image, and gallery flexible subfields can now save through the flexible contract path for current owners, loop-owned related owners, and shared term/user/option owners while other flexible descendants remain inspect-only
- nested ACF group descendants now contribute their ancestry plus leaf selector identity to the live source/sync group hashes so same-named grouped leaf fields do not cross-update each other after save
- nested ACF group ancestry is now preserved in descriptor path metadata so repeater/flexible row descendants can carry explicit group segments instead of flattening every nested source into a loose field name
- the restricted Site Settings `universal_cta_options` global-link group is intentionally kept read-only in Visual Editor and must still be edited from the ACF Site Settings options page
- the overlay panel supports text, textarea, WordPress-backed Visual/Code WYSIWYG editing, single select, checkbox group, structured link inputs, Media Library-backed image selection, and ordered Media Library gallery replacement
- structured choice and link fields now reuse the render-verified visible projection when updating the page in place
- shared option, term, and user-backed fields require explicit acknowledgement before save
- repeated markers for the same resolved field projection now update together on the current page after save
- structured field saves can now refresh other matched projections of that same resolved field on the current page without a reload
- the frontend runtime now uses one shared active badge for hover, focus, and touch selection instead of one detached badge per marker
- initial session bootstrap now stays lightweight by default, while full descriptor payloads load on demand, cache after first lookup, can prefetch after a short hover/focus dwell on the active marker, and can also warm nearby visible uncached markers through a bounded low-priority viewport-aware queue
- shared-badge labels now distinguish lightweight owner types such as `Related Post`, `Shared Term`, and `Shared User` from the session public map without forcing eager descriptor hydration
- panel acknowledgement copy, save-button labels, and locked-state messaging now reuse that same owner-type refinement for shared term, user, option, and post targets
- the panel header now surfaces the actual entity title/name plus frontend/backend editor links when that entity has canonical URLs, and the field block now exposes a compact expandable source-details toggle with the raw dynamic source summary
- readonly and locked panel states now include a dedicated context summary so the notice area can name the exact entity and source field that remain out of save scope
- save responses now carry structured entity/source/save summaries so the open panel and status bar can confirm exactly what was updated instead of falling back to a generic success message
- the frontend status bar now includes a direct editor link for the current page owner by default and switches to the active field owner while a specific marker is open in the panel
- Toolbar 2.0 now provides a bottom-center Visual Editor control surface with status/review, Go To Object navigation, and Shared Globals launchers while reusing the existing panel and descriptor contracts
- the Shared Globals launcher can expose configured option-owned ACF `relationship` / `post_object` fields, defaulting to `settings_globals_default_posts`, with additional option field names managed from the Visual Editor add-on settings area
- descriptor sessions and hydrated payloads now carry a formal Descriptor V2 shape with explicit page, owner, loop, path, and mutation-contract metadata for advanced loop-owned and nested-field planning
- Visual Editor saves now write to dedicated journal tables (`dbvc_ve_change_sets`, `dbvc_ve_change_items`) so future loop-owned and flexible-content mutation paths have durable per-path history and rollback-oriented write scaffolding
- save requests now run through an explicit mutation-contract layer so supported current, shared, repeater-row, and loop-owned save paths are formalized instead of relying only on loose scope checks
- the panel source-details block now surfaces the resolved save-contract label/detail alongside the dynamic source summary
- native Bricks ACF loop provenance now participates in descriptor source/path/mutation metadata so panel summaries and save-contract details can distinguish native repeater, relationship, post-object, and taxonomy origins
- nested native ACF loop ancestry now also carries the parent native loop kind/selector into descriptor signatures, path summaries, and save-contract detail so `relationship -> repeater`, `post_object -> repeater/flexible`, and similar nested native paths stay explicit and less collision-prone
- the editor panel is now closed by default, opens from the active shared badge, closes on outside click, and can be dragged to a different screen position that persists for the current browser session
- empty text-like targets can now surface a pulsing placeholder treatment when the resolved display value is empty, while image targets use a narrow overflow override instead of a broad theme-overriding rule
- supported post type archives and taxonomy archives now resolve first-class page context and can surface render-verified ACF/post-field markers in inspect-only mode while archive save contracts remain pending
- advanced exact-tag ACF sources that are not yet save-capable now surface as inspect-only markers instead of being silently dropped
- generic Bricks query-loop rows with a concrete post owner can now surface inspect-only `post_title`, `post_excerpt`, and direct ACF field descriptors with explicit non-current-owner context
- marker and modal states now distinguish current, shared, related, and inspect-only sources so non-current owner items are visibly flagged before interaction

Still out of scope for this slice:
- deeper grouped descendant save verification and structured descendants beyond the new gallery replacement flow
- repeater row insert/remove/reorder and nested repeater/flexible collection mutation
- broader shared, loop-owned, and nested relationship/post-object collection mutation beyond the new direct current-owner query-root collection slice
- archive-wide saves remain unsupported; archive entry points are currently inspect-only and tracked in `docs/enhancements/DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md`
- static non-ACF Bricks internal/taxonomy link settings that mutate builder configuration rather than a resolver-owned content field
- generic non-ACF Bricks post-query loops without a concrete post owner and multi-value related-object editing beyond inspect-only surfacing
- mixed literal-plus-dynamic Bricks text strings
- generic support for all Bricks element types

## Intended install location

This addon now lives inside the DBVC repo at:

`wp-content/plugins/db-version-control-main/addons/visual-editor/`

## Primary goals

- mark supported Bricks-rendered dynamic content with visual editor handles
- resolve the correct entity and field target safely
- present lightweight inline editing UI
- save only through validated resolver pipelines
- log changes and invalidate relevant cache layers
- stay modular and compatible with broader DBVC data handling patterns

## MVP scope

- editor-capable logged-in users only
- singular posts/pages/CPTs only
- text-like Bricks settings, plus top-level and repeater-style Bricks link controls with direct single-tag ACF-backed permalink or URL bindings
- Bricks image elements and `_background.image` controls with direct single-tag ACF image bindings or direct `{featured_image}` post bindings
- Bricks image-gallery elements with direct single-tag ACF gallery bindings
- current singular page context, with explicit Bricks-resolved option/user/term ACF targets where safe
- safe Bricks ACF `relationship` / `post_object` post-loop row support where the loop owner is a concrete related post
- safe direct ACF field support where the loop owner is a concrete queried post, term, or user
- native Bricks ACF query-loop metadata for repeater, relationship, post-object, and taxonomy `query.objectType` roots
- direct native Bricks ACF `relationship` and `post_object` query-root collection editing with ordered add/remove/reorder and reload-after-save reconciliation for current owners, direct repeater-row and flexible-row roots, mixed current-owner `repeater -> flexible` and `flexible -> repeater` roots, grouped current-owner row-owned roots, and concrete loop-owned related-post roots with explicit acknowledgement
- safe Bricks ACF repeater row support where the row index is stable and the owner resolves to the current post or a concrete related post
- writable support for direct Bricks ACF flexible-content text-like, WYSIWYG, choice, link, image, and gallery descendants on current owners, loop-owned related owners, and shared term/user/option owners, with inspect-only surfacing still reserved for the remaining unsupported flexible descendants
- nested-group descendants inside supported repeater/flexible rows now preserve their Bricks ACF group ancestry in the descriptor path and row mutation layer
- inspect-only surfacing for exact single-tag advanced ACF fields and generic concrete-owner query-loop rows that are not yet in the save allowlist
- Bricks instrumentation via render hooks
- overlay side panel with field-type-aware controls
- render-verified marker gating
- explicit shared-scope acknowledgement before save for non-current-entity targets
- REST endpoints for session, descriptor lookup, and save
- authenticated descriptor hydration cache for faster modal opens
- guarded resolver allowlist for:
  - `post_title`
  - `post_excerpt`
  - `featured_image` when Bricks renders it as a direct image or background-image source
  - ACF `text`, `textarea`, `url`, `email`, `number`, `range`
  - ACF `wysiwyg`
  - ACF `checkbox`, `select`, `radio`, `button_group`
  - ACF `link`
  - ACF `image`
  - ACF `post_object`, single-target `relationship`, and single-select `taxonomy` when rendered as direct link targets
  - ACF `gallery` when Bricks renders a direct gallery collection, including stable repeater and flexible row descendants through the same ordered Media Library replacement flow
  - the same safe field types when they are rendered as direct ACF repeater row descendants with stable owner + row identity
  - ACF `text`, `textarea`, `url`, `email`, `number`, `range`, `wysiwyg`, `checkbox`, `select`, `radio`, `button_group`, `link`, `image`, and `gallery` when they are rendered as direct ACF flexible-content descendants with stable row + layout identity on current owners, loop-owned related owners, and shared term/user/option owners
  - related-post `post_title`, `post_excerpt`, and safe direct ACF field bindings inside Bricks ACF `relationship` / `post_object` post loops

## Core design principles

- server-side registry is the source of truth
- DOM attributes contain lookup handles, not full mutable payloads
- resolver registry decides what is editable
- unsupported and derived content must be surfaced honestly
- media-field support must stay attachment-aware and never guess backend ownership from final HTML
- shared/global scopes need explicit warnings
- related-post loop scopes need explicit warnings and acknowledgement
- auditability and validation are first-class concerns
- advanced nested writes should build on the journal + descriptor-contract layer rather than inventing new save targeting rules inside the browser

## Suggested first Codex task order

1. Review the docs in `/docs/`
2. Confirm DBVC addon bootstrap expectations in the real plugin
3. Add deeper Bricks object-context coverage only where Bricks can resolve a safe backend owner
4. Expand the resolver allowlist carefully and keep each field type isolated
5. Improve the inline inspector/editor UI without moving authority into the browser
6. Add loop/global scope handling only with explicit resolver support
7. Add broader validation fixtures and runtime smoke checks

## Key docs

- `ARCHITECTURE.md`
- `docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`
- `docs/knowledge/HOOK_USAGE_STRATEGY.md`
- `docs/knowledge/DATA_CONTRACTS.md`
- `docs/knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_MVP.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_REPEATER_IMPLEMENTATION_PLAN.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md`
