# 04 · Export Package Spec

## Package goal

A portable zip file that is:

- inspectable
- versioned
- checksum-protected
- selective by domain
- future-proof enough for new Bricks domains

## Recommended zip layout

```text
dbvc-bricks-package-YYYYMMDD-HHMMSS.zip
  manifest.json
  checksums.json
  site.json
  domains/
    bricks-settings.json
    color-palettes.json
    global-classes.json
    global-variables.json
    pseudo-classes.json
    theme-styles.json
    components.json
    breakpoints.json
  raw-options/
    bricks_global_settings.json
    bricks_color_palette.json
    bricks_global_classes.json
    bricks_global_classes_categories.json
    bricks_global_variables.json
    bricks_global_variables_categories.json
    bricks_global_pseudo_classes.json
    bricks_theme_styles.json
    bricks_components.json
```

## Why include both domain files and raw option files

### Domain files
Used by the compare/apply engine because they are already normalized and shaped.

### Raw option files
Useful for:
- debugging
- emergency recovery
- future parsers
- verifying normalization results

## `manifest.json` recommendation

```json
{
  "package_version": 1,
  "generator": {
    "plugin": "DBVC",
    "addon": "Bricks",
    "feature": "Portability & Drift Manager",
    "version": "0.1.0"
  },
  "created_at_gmt": "2026-04-22T16:30:00Z",
  "source_site": {
    "home_url": "https://site-a.example",
    "blog_id": 1,
    "wp_version": "6.x",
    "php_version": "8.2",
    "dbvc_version": "x.y.z",
    "bricks_version": "x.y.z",
    "theme": "Bricks"
  },
  "selected_domains": [
    "global_settings",
    "color_palette",
    "global_classes",
    "global_variables",
    "pseudo_classes",
    "theme_styles",
    "components",
    "breakpoints"
  ],
  "compatibility": {
    "min_dbvc_version": "x.y.z",
    "min_bricks_version": "x.y.z"
  }
}
```

## `site.json` recommendation

Include source-site context only for review and diagnostics.

```json
{
  "site_name": "Site A",
  "home_url": "https://site-a.example",
  "export_user_id": 1,
  "export_user_label": "Admin",
  "notes": "",
  "environment": "production"
}
```

## `checksums.json` recommendation

Checksums per file after writing JSON content.

```json
{
  "domains/bricks-settings.json": "sha256:...",
  "domains/global-classes.json": "sha256:...",
  "raw-options/bricks_global_classes.json": "sha256:..."
}
```

## Domain file structure recommendation

Each domain file should have a common envelope.

```json
{
  "domain": "global_classes",
  "label": "Global Classes",
  "exported_at_gmt": "2026-04-22T16:30:00Z",
  "source_option_names": [
    "bricks_global_classes",
    "bricks_global_classes_categories"
  ],
  "normalization_version": 1,
  "objects": [],
  "meta": {
    "count": 0
  }
}
```

## Object payload guidance

Each object record inside a domain should ideally carry:

- `source_key`
- `match_keys`
- `display_name`
- `raw`
- `normalized`
- `fingerprint`
- `references`
- `warnings`

Example:

```json
{
  "source_key": "class:alt__btn",
  "display_name": "alt__btn",
  "match_keys": {
    "id": "tiwsio",
    "name": "alt__btn"
  },
  "fingerprint": "sha256:...",
  "references": {
    "variables": [
      "--fco-cta-btn-secondary-color",
      "--fco-cta-btn-secondary-bg",
      "--gap-m",
      "--gap-s",
      "--radius-btn"
    ]
  },
  "warnings": [],
  "raw": { "...": "..." },
  "normalized": { "...": "..." }
}
```

## Package versioning rule

Add **both**:

- package version
- normalization version

This avoids breaking old packages when object shaping logic changes.

## Lightweight rule

Do not include unrelated DBVC entities or unrelated Bricks data in the package.
The zip should stay narrow and understandable.
