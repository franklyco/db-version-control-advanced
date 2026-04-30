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
- inspect-only thumbnail preview for top-level ACF gallery fields rendered by Bricks gallery controls
- inspect-only thumbnail preview for stable ACF repeater-row gallery descendants
- inspect-only thumbnail preview for stable direct ACF flexible-content row descendants

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
- Do not add multi-step resolvers until durable change journaling exists.
- Related/query-loop ownership must be resolved before any nested resolver is allowed to save.
