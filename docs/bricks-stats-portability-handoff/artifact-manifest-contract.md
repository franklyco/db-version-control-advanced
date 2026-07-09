# Bricks Artifact Manifest Contract For DBVC

## Top-Level Manifest Shape

Vertical Bricks Stats writes a full manifest with this shape:

```json
{
  "schema": "vf-bricks-stats-manifest.v1",
  "manifest_id": "bricks-stats-20260702-120000-abcdef",
  "generated_at": "2026-07-02T12:00:00-04:00",
  "site": {},
  "scan": {},
  "counts": {},
  "artifacts": [],
  "references": [],
  "dependency_ledger": [],
  "query_loops": [],
  "frontend_coverage": {},
  "unresolved_references": [],
  "scan_log": [],
  "files": []
}
```

DBVC should treat this as a source pattern, not a required schema name. Recommended DBVC schema names:

```text
dbvc-bricks-site-inventory.v1
dbvc-bricks-reference-graph.v1
dbvc-bricks-dependency-ledger.v1
dbvc-bricks-portability-gap-report.v1
```

## Artifact Record Shape

```json
{
  "artifact_uid": "template:107607",
  "domain": "template",
  "bricks_type": "bricks_template",
  "artifact_id": 107607,
  "name": "Listings Archive",
  "slug": "listings-archive",
  "post_status": "publish",
  "type": "archive",
  "template_type": "archive",
  "template_preview_type": "archive-cpt",
  "taxonomies": {
    "template_bundle": [],
    "template_tag": []
  },
  "usage": {
    "primary": "archive_template",
    "contexts": [
      "archivePostTypes:listing"
    ],
    "conditions": []
  },
  "status": "active_assigned",
  "status_reasons": [],
  "references": {
    "count": 0,
    "sample": []
  },
  "metrics": {},
  "modified_at": "",
  "source": {}
}
```

## Domains Currently Scanned

| Domain | Source | DBVC Portability Use |
| --- | --- | --- |
| `template` | `bricks_template` posts and Bricks template meta | Primary portable layout objects. |
| `global_class` | `bricks_global_classes` option and template references | Required style dependency graph. |
| `variable` | `bricks_global_variables` and references | Token dependency graph. |
| `color_palette` / `color_token` | `bricks_color_palette` | Brand/token portability checks. |
| `component` | `bricks_components` option | Reusable element dependencies. |
| `global_element` | `bricks_global_elements` option | Reusable Bricks global element dependencies. |
| `custom_font` | `bricks_fonts` posts and `bricks_font_faces` meta | Font family and file readiness checks. |
| `query_loop` | Bricks element settings with `hasLoop` or `query` | Dynamic content dependencies. |
| `dynamic_tag` | `{...}` style dynamic data references in Bricks payloads | Field/context binding dependencies. |
| `setting` | Bricks global settings/theme styles/breakpoints/etc. | Site-level environment dependencies. |
| `custom_table` | Bricks support tables | Presence/count/schema diagnostics. |

## Reference Record Shape

```json
{
  "reference_uid": "template:107607:element:abc:class:xyz",
  "source_artifact_uid": "template:107607",
  "target_artifact_uid": "global_class:xyz",
  "reference_type": "global_class_usage",
  "location": {
    "post_id": 107607,
    "meta_key": "_bricks_page_content_2",
    "element_id": "abc",
    "element_name": "section",
    "setting_path": "settings._cssGlobalClasses"
  },
  "confidence": "high"
}
```

DBVC should build its import bundles from this graph:

- start with selected templates
- include referenced global classes
- include referenced variables and palette tokens
- include referenced components/global elements
- inspect custom fonts and uploads
- report unresolved references before import
- compare destination-site conflicts by artifact identifiers and names

## Dependency Ledger Extension

DBVC should not rely on raw references alone for imports. A separate dependency ledger should promote transfer-critical references into records with include/remap policy:

```json
{
  "dependency_uid": "template:940:element:tpiukz:template:4775",
  "source_artifact_uid": "template:940",
  "target_artifact_uid": "template:4775",
  "dependency_domain": "template",
  "dependency_type": "bricks_template_element_template",
  "location": {
    "post_id": 940,
    "meta_key": "_bricks_page_content_2",
    "element_id": "tpiukz",
    "element_name": "template",
    "setting_path": "settings.template"
  },
  "source_target_id": 4775,
  "include_in_transfer": true,
  "remap_required": true,
  "remap_strategy": "source_id_to_destination_id",
  "destination_target_id": null,
  "status": "unresolved",
  "confidence": "high"
}
```

Use `dependency-ledger-contract.md` for the full transfer and remap contract.

Individual template exports may also carry a compact `dbvc_portability.dependency_hints` snapshot. DBVC can use that snapshot when a single-template JSON is moved without a separate manifest file, but it should treat the hints as warnings and mapping prompts rather than authoritative transfer closure.

## Status Vocabulary

Recommended shared status vocabulary:

```text
active_assigned
active_published
inactive
old_inactive
draft
needs_review
orphaned_reference
unknown
```

DBVC can add portability-specific statuses:

```text
portable_ready
portable_with_warnings
blocked_missing_dependency
conflict_destination_exists
requires_mapping
```

## Scanner Sources To Replicate In DBVC

Templates:

```text
wp_posts.post_type = bricks_template
_bricks_page_content_2
_bricks_template_settings
_bricks_template_type
template_bundle
template_tag
```

Options:

```text
bricks_active_templates
bricks_color_palette
bricks_components
bricks_custom_breakpoints
bricks_custom_icons
bricks_font_face_rules
bricks_font_favorites
bricks_global_classes
bricks_global_classes_categories
bricks_global_classes_trash
bricks_global_elements
bricks_global_settings
bricks_global_variables
bricks_global_variables_categories
bricks_icon_sets
bricks_structure_width
bricks_theme_styles
theme_mods_bricks*
```

Custom tables:

```text
wp_bricks%
wp_brickssync%
wp_brxc%
```

DBVC should avoid direct mutation during scan. Any DBVC import/apply flow should be separate from inventory scan and should use preview-first writes.
