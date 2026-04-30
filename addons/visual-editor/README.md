# DBVC Visual Editor

DBVC Visual Editor is a DBVC addon that enables authorized editors to activate a frontend overlay on Bricks-rendered pages and edit supported dynamic content in place.

The addon is designed around a server-side descriptor registry and Bricks render-time instrumentation. The frontend should only interact with lightweight markers and authenticated endpoints, never raw guessed meta targets.

## Status

Initial repo-adapted MVP slice implemented.

Current status:
- addon bootstrap is wired into DBVC core loading
- Add-ons screen can enable/disable the runtime
- authorized users can toggle frontend Visual Editor mode from the admin bar
- frontend assets load only in edit mode on supported singular views
- supported Bricks direct single-tag text bindings receive lightweight DOM tokens
- exact Bricks dynamic-data args on those single-tag bindings are preserved for resolver context
- mismatched rendered nodes are filtered back out during Bricks element render verification
- top-level Bricks `link` controls with direct single-tag external ACF bindings can now mark and edit the rendered `href` target safely
- image-style Bricks `link = url` controls with a direct single-tag external ACF binding now reuse that same guarded `href` flow
- repeater-style Bricks collection anchors such as `list`, `social-icons`, and custom-link `image-gallery` items can now carry their own guarded `href` markers
- direct Bricks image elements backed by either a single-tag ACF image field or a direct WordPress `{featured_image}` binding can now surface a marker on the wrapper and switch to another existing Media Library attachment through an attachment-ID-first save contract, with local Media Library URL fallback resolution when needed
- direct Bricks `_background.image` controls backed by a single-tag ACF image field can now reuse that same attachment-aware media workflow for rendered background images
- direct Bricks image-gallery elements backed by a single-tag ACF gallery field can now surface a visual inspect-only marker with thumbnail preview
- direct Bricks ACF repeater row subfields can now be marked and resolved through stable parent-repeater metadata plus Bricks loop index
- current-post and related-post repeater row descendants can now reuse the existing safe field resolvers for text-like, WYSIWYG, choice, link, and image field types
- descriptor state is kept server-side in a short-lived session registry
- authenticated REST inspection and save endpoints are available for the MVP allowlist
- ACF object context now resolves through Bricks provider logic for current-post, options, term, and user-backed fields on singular views
- current-post ACF `post_object`, single-target `relationship`, and single-select `taxonomy` fields can now be edited through their rendered permalink when Bricks uses them as direct link targets
- current-post, shared, and safe related-post ACF `image` fields can now be edited when Bricks renders their direct image source
- Bricks ACF `relationship` and `post_object` query loops can now mark safe related-post loop rows and surface that related-post ownership in the modal
- related-post loop saves now require explicit acknowledgement and update the related post shown in the loop rather than the current page post
- direct safe ACF fields rendered from Bricks query loops with a concrete post, term, or user owner can now save against that loop owner instead of being forced into inspect-only mode
- direct Bricks ACF flexible-content descendants can now surface with stable row + layout metadata, and current/related post text-like, WYSIWYG, choice, link, and image flexible subfields can now save through the new flexible contract path while other flexible descendants remain inspect-only
- the restricted Site Settings `universal_cta_options` global-link group is intentionally kept read-only in Visual Editor and must still be edited from the ACF Site Settings options page
- the overlay panel supports text, textarea, WordPress-backed Visual/Code WYSIWYG editing, single select, checkbox group, structured link inputs, and Media Library-backed image selection
- structured choice and link fields now reuse the render-verified visible projection when updating the page in place
- shared option, term, and user-backed fields require explicit acknowledgement before save
- repeated markers for the same resolved field projection now update together on the current page after save
- structured field saves can now refresh other matched projections of that same resolved field on the current page without a reload
- the frontend runtime now uses one shared active badge for hover, focus, and touch selection instead of one detached badge per marker
- initial session bootstrap now stays lightweight by default, while full descriptor payloads load on demand, cache after first lookup, and can prefetch after a short hover/focus dwell on the active marker
- shared-badge labels now distinguish lightweight owner types such as `Related Post`, `Shared Term`, and `Shared User` from the session public map without forcing eager descriptor hydration
- panel acknowledgement copy, save-button labels, and locked-state messaging now reuse that same owner-type refinement for shared term, user, option, and post targets
- the panel header now surfaces the actual entity title/name plus frontend/backend editor links when that entity has canonical URLs, and the field block now exposes a compact expandable source-details toggle with the raw dynamic source summary
- readonly and locked panel states now include a dedicated context summary so the notice area can name the exact entity and source field that remain out of save scope
- save responses now carry structured entity/source/save summaries so the open panel and status bar can confirm exactly what was updated instead of falling back to a generic success message
- descriptor sessions and hydrated payloads now carry a formal Descriptor V2 shape with explicit page, owner, loop, path, and mutation-contract metadata for advanced loop-owned and nested-field planning
- Visual Editor saves now write to dedicated journal tables (`dbvc_ve_change_sets`, `dbvc_ve_change_items`) so future loop-owned and flexible-content mutation paths have durable per-path history and rollback-oriented write scaffolding
- save requests now run through an explicit mutation-contract layer so supported current, shared, repeater-row, and loop-owned save paths are formalized instead of relying only on loose scope checks
- the panel source-details block now surfaces the resolved save-contract label/detail alongside the dynamic source summary
- empty text-like targets can now surface a pulsing placeholder treatment when the resolved display value is empty, while image targets use a narrow overflow override instead of a broad theme-overriding rule
- advanced exact-tag ACF sources that are not yet save-capable now surface as inspect-only markers instead of being silently dropped
- generic Bricks query-loop rows with a concrete post owner can now surface inspect-only `post_title`, `post_excerpt`, and direct ACF field descriptors with explicit non-current-owner context
- marker and modal states now distinguish current, shared, related, and inspect-only sources so non-current owner items are visibly flagged before interaction

Still out of scope for this slice:
- flexible gallery and other still-structured inspect-only descendants, plus non-post/shared flexible row mutation
- repeater row insert/remove/reorder and nested repeater/flexible collection mutation
- archive-wide editing flows and non-singular entry points
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
- safe Bricks ACF repeater row support where the row index is stable and the owner resolves to the current post or a concrete related post
- narrow writable support for direct Bricks ACF flexible-content text-like, WYSIWYG, choice, link, and image descendants on current/related post owners, with inspect-only surfacing for other stable flexible descendants
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
  - ACF `gallery` as inspect-only visual surfacing
  - the same safe field types when they are rendered as direct ACF repeater row descendants with stable owner + row identity
  - ACF `text`, `textarea`, `url`, `email`, `number`, `range`, `wysiwyg`, `checkbox`, `select`, `radio`, `button_group`, `link`, and `image` when they are rendered as direct ACF flexible-content descendants with stable current/related post row + layout identity
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
- `docs/enhancements/DBVC_VISUAL_EDITOR_MVP.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md`
- `docs/enhancements/DBVC_VISUAL_EDITOR_REPEATER_IMPLEMENTATION_PLAN.md`
