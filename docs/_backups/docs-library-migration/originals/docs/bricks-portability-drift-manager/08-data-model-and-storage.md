# 08 · Data Model and Storage

The feature needs a durable record of import/export/apply history.

## Recommended persistence strategy

Use a lightweight custom table for jobs and the filesystem for larger JSON payloads.

### Why
- `wp_options` is wrong for long audit histories and large diff payloads
- jobs need filtering, timestamps, statuses, and linkage to backups
- payload files can become large

## Recommended tables

## 1) `wp_dbvc_bricks_jobs`

Columns:

- `id`
- `job_uuid`
- `job_type` (`export`, `import_compare`, `apply`, `rollback`)
- `status`
- `created_at`
- `updated_at`
- `user_id`
- `source_site_url`
- `package_id`
- `package_label`
- `domains_json`
- `summary_json`
- `backup_id`
- `error_message`

## 2) `wp_dbvc_bricks_backups`

Columns:

- `id`
- `backup_uuid`
- `created_at`
- `user_id`
- `linked_job_uuid`
- `option_names_json`
- `file_path`
- `checksum`
- `status`

## Filesystem recommendation

Store files under a private DBVC folder, for example:

```text
wp-content/uploads/dbvc/bricks-portability/
  exports/
  imports/
  compare/
  backups/
  logs/
```

If DBVC already has a safer private storage abstraction, use that instead.

## Compare result payload recommendation

Store compare results as JSON file, not only in table columns.

Shape:

```json
{
  "session": {
    "job_uuid": "job_xxx",
    "created_at_gmt": "2026-04-22T16:30:00Z"
  },
  "package": {
    "source_site": "https://site-a.example"
  },
  "summary": {
    "domains_with_drift": 4,
    "rows_total": 68,
    "new_in_source": 11,
    "value_changed": 49,
    "warnings": 8
  },
  "domains": [
    {
      "domain": "global_classes",
      "summary": {
        "rows_total": 22,
        "changed": 18,
        "new": 3,
        "warnings": 1
      },
      "rows": []
    }
  ]
}
```

## Decision storage recommendation

As the user assigns review decisions, store them in a separate JSON payload linked to the compare job.

This avoids losing work if the page reloads.

```json
{
  "job_uuid": "job_xxx",
  "decisions": {
    "row_global_classes_alt__btn": {
      "decision": "replace_target",
      "acknowledged_warnings": true
    }
  }
}
```

## Row key recommendation

Every compare row needs a stable unique key, for example:

```text
<domain>:<status-match-basis>:<canonical-name-or-id>
```

Examples:
- `global_classes:name:alt__btn`
- `global_variables:token:--gap-m`
- `theme_styles:name:button-primary`

## Retention policy

Recommended defaults:

- keep export packages: 30 to 90 days
- keep compare payloads: 30 to 90 days
- keep backups: until manually removed or 90+ days
- keep lightweight job metadata longer

Expose cleanup settings in the feature settings screen.
