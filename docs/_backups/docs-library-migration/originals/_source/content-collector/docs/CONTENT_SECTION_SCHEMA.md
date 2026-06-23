# Crawl Artifact Content Section Schema

Note:
- This document describes the current source plugin artifact shape.
- DBVC addon migration planning and enforcement should follow:
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`

This document defines the grouped content section schema written to each crawl artifact JSON.

## Why

Raw arrays of headings/text are useful but lose structure. The `sections` model preserves page flow and heading hierarchy so downstream review/export can keep contextual groupings.

## Location

Each page artifact:

`uploads/contentcollector/{domain}/{path}/{slug}.json`

## JSON Shape

```json
{
  "content": {
    "headings": [],
    "text_blocks": [],
    "images": [],
    "section_schema_version": "1.0",
    "section_count": 4,
    "sections": [
      {
        "id": "section-1",
        "order": 1,
        "parent_id": null,
        "level": 1,
        "heading_tag": "h1",
        "heading": "Web Design Services",
        "is_intro": false,
        "text_blocks": [
          "Intro paragraph..."
        ],
        "links": [
          {
            "type": "link",
            "text": "Learn more",
            "url": "https://example.com/services/web-design/",
            "is_cta": true
          }
        ],
        "ctas": [
          {
            "type": "link",
            "text": "Learn more",
            "url": "https://example.com/services/web-design/"
          }
        ],
        "images": [
          {
            "source_url": "https://example.com/wp-content/uploads/header.jpg",
            "local_filename": "header.jpg",
            "alt": "Header"
          }
        ]
      },
      {
        "id": "section-2",
        "order": 2,
        "parent_id": "section-1",
        "level": 2,
        "heading_tag": "h2",
        "heading": "Services",
        "is_intro": false,
        "text_blocks": [],
        "links": [],
        "ctas": [],
        "images": []
      }
    ]
  }
}
```

## Extraction Rules (v1)

1. Section boundaries are created at `h1`-`h6`.
2. Section parent/child relationships are inferred from heading levels.
3. Text content (`p`, `li`) is grouped into the active section.
4. Links (`a`) and buttons (`button`) are captured as actions in the active section.
5. CTA classification is heuristic (buttons, `btn`/`cta` classes, or common CTA text).
6. Images are attached to the active section using downloaded image mappings.
7. Content encountered before the first heading is placed in an intro section (`level: 0`, `is_intro: true`).

## Backward Compatibility

- Existing keys (`headings`, `text_blocks`, `images`) remain unchanged.
- New consumers should prefer `content.sections` when structural grouping is needed.
