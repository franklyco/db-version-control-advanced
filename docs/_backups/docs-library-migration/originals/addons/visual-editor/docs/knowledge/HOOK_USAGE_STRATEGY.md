# Hook Usage Strategy

## Primary hook

### `bricks/element/render_attributes`
Use this as the primary instrumentation hook to add marker attributes to rendered elements.

Why:
- element-level granularity
- direct access to element object
- attribute injection is additive and targeted
- easier to keep HTML modifications narrow

## Secondary fallback

### `bricks/frontend/render_element`
Use only when element-level attribute injection is not sufficient and a late-stage per-element HTML modification is required.

Examples:
- wrapper comments in debug mode
- fallback token injection for unsupported element shapes
- controlled diagnostics
- render-time verification that a marked node still matches the resolved current-entity source value before the marker is exposed to the browser

## Area-level fallback

### `bricks/frontend/render_data`
Use only for narrow page-area post-processing, diagnostics, or carefully reviewed fallback behavior.

Do not make this the primary discovery or mapping strategy.

## Related considerations

- `bricks/element/set_root_attributes` may be useful in narrow cases for root attributes, but this addon should default to `bricks/element/render_attributes` for instrumentation.
- Any dynamic tag parsing must be cautious and localized. Avoid broad content replacements that could interfere with other dynamic data behavior.

## Runtime gating

Register these hooks only when:
- current request is frontend
- visual editor mode is active
- user has appropriate capability
