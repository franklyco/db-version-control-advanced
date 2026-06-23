# AI Agent Quickstart

Status: Current
Last verified: 2026-06-22
Source of truth: `includes/Dbvc/AiPackage/PackageDocBuilder.php`, `includes/Dbvc/AiPackage/SamplePackageBuilder.php`
Read when: an AI agent needs to create a returned DBVC package from a DBVC sample package.
Minimum context: `package-layout.md`, `entity-shapes.md`, and the selected sample JSON plus sibling `.context.json`.

## Fast Path

Create a ZIP whose root contains:

```text
dbvc-ai-manifest.json
entities/posts/{post_type}/{slug}.json
entities/terms/{taxonomy}/{slug}.json
```

Only include post entity files when creating or updating posts, pages, or CPT records. Only include term entity files when creating or updating taxonomy terms.

## Current Contract

The returned manifest must use this minimum shape:

```json
{
  "package_type": "dbvc_ai_submission_package",
  "package_schema_version": 1,
  "source_sample_package": {
    "site_fingerprint": "copy-from-sample-package",
    "package_schema_version": 1
  },
  "intended_operation": "create_only",
  "counts": {
    "post_entities": 0,
    "term_entities": 0
  }
}
```

Allowed `intended_operation` values are:

- `create_only`
- `update_only`
- `create_or_update`

## Authoring Rules

- Mirror the DBVC-generated sample JSON field names exactly.
- Use `ID: 0` for net-new post, page, or CPT entities.
- Use `term_id: 0` for net-new term entities.
- Do not invent `vf_object_uid` for net-new content.
- Only include `vf_object_uid` when an existing update target is known.
- Put posts/pages/CPTs under `entities/posts/{post_type}/{slug}.json`.
- Put terms under `entities/terms/{taxonomy}/{slug}.json`.
- Match the filename slug to `post_name` or `slug`.
- Prefer slug-based references over numeric IDs.
- Do not invent DBVC bookkeeping fields, history hashes, or ACF underscore reference meta.
- Keep unsupported media-like ACF fields empty unless the sample package explicitly shows a supported structure.

## Nuance

The sample package is context. The returned submission package is import-adjacent. Do not return the DBVC sample package layout as the import package.

Use each selected object type's sibling `.context.json` to understand field meaning, field type, choices, and the best available Object Type Context or Field Context summary. Do not infer value shape from prose alone when the sample JSON or compact schema shows a stricter shape.

## Examples

- `examples/submission-manifest-create-only.json`
- `examples/page-create.json`
- `examples/service-create.json`
- `examples/term-create.json`

## Maintenance Notes

Update this file when `PackageDocBuilder`, `SamplePackageBuilder`, manifest validation, or package layout behavior changes.
