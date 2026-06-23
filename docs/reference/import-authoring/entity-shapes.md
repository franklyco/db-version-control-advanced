# Entity Shapes

Status: Current
Last verified: 2026-06-22
Source of truth: `includes/Dbvc/AiPackage/TemplateBuilder.php`, `includes/Dbvc/AiPackage/RulesService.php`, `includes/Dbvc/AiPackage/SubmissionPackageValidator.php`, `includes/Dbvc/AiPackage/SubmissionPackageTranslator.php`
Read when: authoring page, CPT, service, or term JSON files for a returned DBVC package.
Minimum context: `package-layout.md` and the generated sample JSON for the object type.

## Fast Path

For new posts, pages, and CPT entries, use `ID: 0`.

For new terms, use `term_id: 0`.

Do not invent `vf_object_uid`. Keep entity paths, object keys, and slugs aligned.

## Current Contract

Post, page, and CPT files live at:

```text
entities/posts/{post_type}/{slug}.json
```

Minimum post/CPT shape:

```json
{
  "ID": 0,
  "post_type": "page",
  "post_title": "About Us",
  "post_name": "about-us"
}
```

Optional post/CPT fields include:

- `post_status`
- `post_content`
- `post_excerpt`
- `post_date`
- `post_date_gmt`
- `post_parent`
- `menu_order`
- `post_author`
- `post_password`
- `comment_status`
- `ping_status`
- `meta`
- `tax_input`
- `vf_object_uid` only for known update targets

Term files live at:

```text
entities/terms/{taxonomy}/{slug}.json
```

Minimum term shape:

```json
{
  "term_id": 0,
  "taxonomy": "service_type",
  "name": "Web Design",
  "slug": "web-design"
}
```

Optional term fields include:

- `description`
- `parent`
- `parent_slug`
- `meta`
- `vf_object_uid` only for known update targets

## Authoring Rules

- The `{post_type}` path segment must match payload `post_type`.
- The `{taxonomy}` path segment must match payload `taxonomy`.
- The `{slug}` filename should match `post_name` or `slug`.
- For create packages, use `ID: 0` or `term_id: 0`.
- For update packages, prefer `vf_object_uid` when DBVC supplied it.
- Use `tax_input` for taxonomy assignments on post/CPT entities.
- Use `meta` for ACF and registered meta values.
- Do not include DBVC history or import hash meta keys.

## Nuance

DBVC warns when a filename slug and payload slug do not match. DBVC blocks object key mismatches, missing required fields, non-numeric IDs, blocked fields, and blocked meta keys.

Unexpected top-level fields produce warnings. Unknown or unsupported ACF/meta value families may block during translation even when the top-level JSON shape validates.

## Examples

- `examples/page-create.json`
- `examples/service-create.json`
- `examples/term-create.json`

## Maintenance Notes

Update this file when post/term required fields, optional fields, blocked fields, update matching, or translator output changes.
