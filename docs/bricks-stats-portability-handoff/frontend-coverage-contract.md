# Frontend Coverage Contract For DBVC

## Purpose

Frontend coverage reports which public custom objects appear to lack dedicated Bricks frontend templates on the current site. In DBVC Bricks Portability, this helps answer:

- Will imported content have matching Bricks single/archive templates?
- Which source templates are required for destination coverage?
- Which destination objects still need template creation after import?
- Which gaps are intentionally ignored by the operator?

## Coverage Targets

Vertical currently checks:

| Coverage Type | Object | Match Signal |
| --- | --- | --- |
| `post_type_single` | Public custom post type | Bricks condition `postType` contains CPT slug. |
| `post_type_archive` | Public custom post type with `has_archive` | Bricks condition `archivePostTypes` contains CPT slug. |
| `taxonomy_archive` | Public custom taxonomy attached to custom public CPTs | Bricks taxonomy/terms condition references taxonomy. |

Default exclusions:

- built-in post types and taxonomies
- `attachment`
- `bricks_template`
- `bricks_fonts`
- ACF internal post types
- WordPress template/navigation internals
- non-public or non-publicly-queryable objects

## Coverage Report Shape

```json
{
  "schema": "vf-bricks-frontend-coverage.v1",
  "generated_at": "2026-07-02T12:00:00-04:00",
  "summary": {
    "checked": 38,
    "covered": 13,
    "missing": 25
  },
  "gaps": [
    {
      "coverage_uid": "frontend_coverage:post_type_archive:listing",
      "coverage_type": "post_type_archive",
      "object_kind": "post_type",
      "object_name": "listing",
      "label": "Listings",
      "expected_template": "CPT archive template",
      "route_hint": "https://example.test/listings/",
      "status": "missing_template",
      "severity": "high",
      "matched_templates": [],
      "reason": "no_published_template_condition_for_post_type_archive"
    }
  ],
  "covered": [
    {
      "coverage_uid": "frontend_coverage:post_type_archive:listing",
      "coverage_type": "post_type_archive",
      "object_kind": "post_type",
      "object_name": "listing",
      "label": "Listings",
      "expected_template": "CPT archive template",
      "route_hint": "https://example.test/listings/",
      "status": "covered",
      "severity": "none",
      "matched_templates": [
        {
          "artifact_uid": "template:107607",
          "id": 107607,
          "title": "Listings Archive",
          "status": "active_assigned",
          "edit_url": "https://example.test/wp-admin/post.php?post=107607&action=edit"
        }
      ]
    }
  ]
}
```

## DBVC Portability Mapping

For source site scans:

- covered source targets identify templates DBVC may need to export.
- missing source targets identify source-site gaps that should not be treated as DBVC import failures.
- operator policy can mark gaps as intentional.

For destination site scans:

- missing destination targets identify templates that must be imported or created.
- covered destination targets identify potential conflicts or reusable existing templates.
- DBVC can compare source coverage to destination coverage before import.

Suggested DBVC report fields:

```json
{
  "source_coverage": {},
  "destination_coverage": {},
  "portability_gaps": [
    {
      "coverage_type": "post_type_archive",
      "object_name": "listing",
      "source_status": "covered",
      "destination_status": "missing_template",
      "recommended_action": "import_source_template",
      "source_template_ids": [107607],
      "destination_template_ids": []
    }
  ]
}
```

## Planned Policy Layer

Vertical plans a policy store for operator decisions:

```text
vf_bricks_stats_frontend_coverage_policy
```

DBVC should decide whether equivalent policy belongs in:

- source-site scan snapshot
- destination-site scan snapshot
- DBVC migration session state
- remote workspace state

Policy statuses to consider:

```text
needed
needs_template
not_needed
covered_manually
deferred
```

## QA Expectations

Minimum DBVC QA should prove:

- listing/source CPT archive coverage is detected when `archivePostTypes` includes `listing`.
- missing destination CPT archive coverage is reported before import.
- fallback/shared templates are either detected or explicitly reported as ambiguous.
- ignored/not-needed policy does not hide raw scanner facts.
- no Bricks options or template JSON are modified during coverage scan.
