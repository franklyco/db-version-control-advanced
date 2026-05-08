# Resolver Registry

## Purpose

Resolvers translate a safe descriptor into read and write behavior.

## Why registry-based

The visual editor should never save through generic arbitrary meta writes. Each supported target type needs explicit behavior.

## Minimum resolver interface

Each resolver should answer:
- `supports(descriptor): bool`
- `get_value(descriptor): mixed`
- `validate(descriptor, value): result`
- `sanitize(descriptor, value): mixed`
- `save(descriptor, value): result`

## Initial resolver set

### `post_title`
Supports:
- direct post title mutation

### `post_excerpt`
Supports:
- direct post excerpt mutation

### `post_featured_image`
Supports:
- direct WordPress `{featured_image}` bindings when Bricks is rendering the element image source
- direct WordPress `{featured_image}` bindings when Bricks is rendering a `_background.image` background image
- switching to another existing local Media Library attachment by attachment ID
- resolving a pasted local Media Library image URL as a fallback when no attachment ID is supplied

### `acf_text`
Supports:
- ACF text field
- ACF textarea field when configured
- the same field types when they resolve from a stable ACF repeater row descendant
- the same field types when they resolve from a direct ACF flexible-content row descendant with stable row + layout identity

### `acf_image`
Supports:
- direct ACF image fields when Bricks is rendering the element image source
- direct ACF image fields when Bricks is rendering a `_background.image` background image
- switching to another existing local Media Library attachment by attachment ID
- resolving a pasted local Media Library image URL as a fallback when no attachment ID is supplied
- the same image path when the source value lives inside a stable ACF repeater row
- the same image path when the source value lives inside a direct ACF flexible-content row descendant with a current/related post owner

### `acf_gallery`
Supports:
- ordered Media Library gallery replacement for top-level ACF gallery fields rendered by Bricks gallery controls
- the same ordered gallery replacement path for stable ACF repeater-row gallery descendants
- the same ordered gallery replacement path for stable direct ACF flexible-content row descendants
- page reload after save so Bricks can rebuild gallery markup cleanly instead of relying on brittle partial DOM patching

### `acf_reference_collection`
Supports:
- direct current-owner native Bricks ACF `relationship` query roots
- direct current-owner native Bricks ACF `post_object` query roots
- ordered add/remove/reorder of connected posts through one collection save
- reload-after-save reconciliation so Bricks can rebuild loop markup from the updated collection

### `unsupported`
Fallback classifier for:
- unsupported
- derived
- unknown
- locked

## Future resolvers

- `term_meta`
- `options_field`
- `acf_flexible_subfield_scalar`
- `acf_flexible_subfield_structured`
- `acf_relationship_collection`
- `acf_post_object_embedded`
- `acf_clone_path`
- `derived_readonly`

## Advanced resolver rules

- Reuse the existing safe ACF field resolvers for stable repeater row descendants instead of adding a parallel repeater resolver tree.
- Direct flexible descendants can reuse the existing safe ACF resolvers for inspect/read behavior once row + layout identity is stable.
- Current/related post flexible descendants can now reuse the existing choice, link, and image resolvers for writable direct-field mutation when the row + layout path is stable.
- Do not add flexible write contracts or relationship collection resolvers until nested path identity is stable.
- Keep the first connected-items collection editor limited to direct current-owner query roots before widening to repeater/flexible row owners, shared owners, or loop-owned collection mutation.
- Do not add multi-step resolvers until durable change journaling exists.
- Related/query-loop ownership must be resolved before any nested resolver is allowed to save.

For the current patch-to-function map around native ACF loop hardening, grouped row traversal, and verification-time row rebinding, see [NATIVE_ACF_LOOP_HARDENING_MAP.md](./NATIVE_ACF_LOOP_HARDENING_MAP.md).
