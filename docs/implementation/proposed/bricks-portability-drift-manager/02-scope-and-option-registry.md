# 02 · Scope and Option Registry

This file turns the raw Bricks options list into a practical portability registry.

## Raw option names observed

User supplied list:

- `bricks_global_settings`
- `bricks_remote_templates`
- `bricks_color_palette`
- `bricks_breakpoints_last_generated`
- `bricks_global_classes`
- `bricks_global_pseudo_classes`
- `bricks_panel_width`
- `bricks_global_classes_changes`
- `bricks_global_classes_locked`
- `bricks_theme_styles`
- `bricks_global_elements`
- `bricks_pinned_elements`
- `bricks_global_classes_categories`
- `bricks_global_variables_categories`
- `bricks_global_variables`
- `bricks_global_classes_timestamp`
- `bricks_global_classes_user`
- `bricks_font_face_rules`
- `bricks_global_classes_trash`
- `bricks_components`
- `bricks_icon_sets`
- `bricks_custom_icons`

## Recommended product-facing settings domains

These are the domains the user should see in the DBVC UI.

1. **Bricks Settings**
2. **Color Palettes**
3. **Global Classes**
4. **Global CSS Variables**
5. **Components**
6. **Pseudo Classes**
7. **Theme Styles**
8. **Breakpoints**
9. **Font Faces** *(optional / advanced)*
10. **Icon Sets / Custom Icons** *(optional / advanced, asset-sensitive)*

## Recommended registry classification

## A) Canonical portable domains — Phase 1

These should be supported first.

### Bricks Settings
- option: `bricks_global_settings`
- notes:
  - top-level Bricks settings object
  - likely includes breakpoint-related data depending on Bricks version
  - requires normalization because some subkeys may be version- or environment-sensitive

### Color Palettes
- option: `bricks_color_palette`
- notes:
  - highly portable
  - likely array/object of palette entries
  - strong Phase 1 candidate

### Global Classes
- option: `bricks_global_classes`
- companion metadata options:
  - `bricks_global_classes_categories`
- non-canonical metadata to ignore during import:
  - `bricks_global_classes_changes`
  - `bricks_global_classes_locked`
  - `bricks_global_classes_timestamp`
  - `bricks_global_classes_user`
  - `bricks_global_classes_trash`
- notes:
  - central portability domain
  - requires object-level matching by name and id
  - categories likely portable if referenced by class objects

### Global CSS Variables
- options:
  - `bricks_global_variables`
  - `bricks_global_variables_categories`
- notes:
  - strong Phase 1 candidate
  - may be referenced by classes, theme styles, components

### Pseudo Classes
- option: `bricks_global_pseudo_classes`
- notes:
  - portable
  - likely dependency target for classes/components/styles

### Theme Styles
- option: `bricks_theme_styles`
- notes:
  - portable but potentially complex nested structures
  - object matching rules needed

### Components
- option: `bricks_components`
- notes:
  - portable, but may reference classes, variables, images, icon sets, or internal element IDs
  - should be supported in Phase 1 with dependency warnings

### Breakpoints
- source:
  - verify exact location in current Bricks version
  - may live partly in `bricks_global_settings`
  - `bricks_breakpoints_last_generated` looks derived, not canonical
- notes:
  - do not treat `bricks_breakpoints_last_generated` as source of truth
  - derive and compare from canonical settings location only

## B) Optional / advanced domains — Phase 2

### Font Faces
- option: `bricks_font_face_rules`
- risk:
  - may reference files or environment-specific URLs
  - portable only if normalization and dependency checks are added

### Icon Sets
- option: `bricks_icon_sets`
- risk:
  - could depend on uploaded assets, font files, or site-specific references

### Custom Icons
- option: `bricks_custom_icons`
- risk:
  - often media-dependent
  - package may need asset manifest and media remap logic

## C) Exclude from portable import — backup only or ignore

These are not primary portability targets.

- `bricks_panel_width`
  - local user/admin UI preference
- `bricks_remote_templates`
  - not part of the requested theme settings portability scope
- `bricks_pinned_elements`
  - personal/editorial UI state
- `bricks_global_elements`
  - verify purpose before including; likely not required for this feature's MVP
- `bricks_breakpoints_last_generated`
  - derived/generated
- `bricks_global_classes_changes`
  - metadata / change tracking
- `bricks_global_classes_locked`
  - operational metadata
- `bricks_global_classes_timestamp`
  - operational metadata
- `bricks_global_classes_user`
  - operational metadata
- `bricks_global_classes_trash`
  - trash state, should not be imported by default

## Recommended DBVC registry structure

Create a single PHP registry for domains, something like:

```php
[
  'global_settings' => [
    'label' => 'Bricks Settings',
    'options' => ['bricks_global_settings'],
    'phase' => 1,
    'portable' => true,
    'matcher' => 'whole_object',
    'normalizer' => 'global_settings',
    'dependencies' => [],
    'risk' => 'medium',
  ],
  'color_palette' => [
    'label' => 'Color Palettes',
    'options' => ['bricks_color_palette'],
    'phase' => 1,
    'portable' => true,
    'matcher' => 'palette_entry',
    'normalizer' => 'palette',
    'dependencies' => [],
    'risk' => 'low',
  ],
  ...
]
```

## Important implementation rule

The UI should show **settings domains**, not raw option names.

Developers work with option names.  
Users work with understandable Bricks objects.
