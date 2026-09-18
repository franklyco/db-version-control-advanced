# Brand Control Center — Curation exports

This directory holds the artifacts produced by the R3-BX curation admin tool
(**Settings → Visual Editor → BCC Curation** while the kill switch is on).

Files here are **derived, committable artifacts** — do not hand-edit. Re-export
from the admin page to change them.

## Files

- `vertical-approved-controls.json` — machine-readable seed. Shape targets a
  future `VerticalControlProvider::getControls()` return value verbatim.
- `vertical-approved-controls.md` — human-readable review sheet grouped by
  R5 sequencing bucket (which future R5 slice unlocks each field). Use this
  for PR review.

## Schema (JSON)

```json
{
  "schema": "dbvc.ve.curation.v1",
  "exported_at": "2026-08-24T00:00:00Z",
  "source_site": "…",
  "counts":          { "include": N, "defer": N, "ignore": N },
  "unlocks_summary": { "R5.1": N, "R5.2": N, ... },
  "records": [
    {
      "id":            "{options-page}__{field_name_path}",
      "label":         "Human label",
      "field_name":    "field_name_path",
      "field_key":     "field_xxxxxxx",
      "field_type":    "text|image|color_picker|…",
      "owner":         "option",
      "owner_subtype": "options-page-slug",
      "group_title":   "Field group title",
      "ancestor_labels": ["Tab label", "Parent group label"],
      "category":      "Brand|Contact|Content|Design|Layout Elements|Legal|SEO",
      "group":         "Optional sub-bucket",
      "client_priority": "must|should|nice|",
      "notes":         "Free-form note",
      "unlocks_at":    "R5.1 | R5.2 | R5.2+color_picker | R5.3 | R5.4 | repeater-later | later"
    }
  ]
}
```

Only `decision === 'include'` records are emitted. `defer` and `ignore`
records stay in the tool's dedicated option
(`dbvc_visual_editor_curation_decisions`) so a future re-run of the tool
picks them back up.

## Turning the tool off

The curation admin page is kill-switch gated by
`dbvc_visual_editor_curation_tool_enabled` (default off). Turn it off from the
Visual Editor settings page once the export is committed; the JSON/MD
artifacts stay on disk, and the decision state stays in options so the tool
can be re-enabled later without data loss.
