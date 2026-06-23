# Validation And Import Rules

Status: Current
Last verified: 2026-06-22
Source of truth: `includes/Dbvc/AiPackage/RulesService.php`, `includes/Dbvc/AiPackage/SubmissionPackageValidator.php`, `includes/Dbvc/AiPackage/SubmissionPackageTranslator.php`
Read when: debugging a rejected package or explaining DBVC AI package intake behavior.
Minimum context: `package-layout.md` and `entity-shapes.md`.

## Fast Path

DBVC returns one of three package states:

- `valid`: package may proceed to translation/import.
- `valid_with_warnings`: operator review or warning acceptance is required.
- `blocked`: package must not proceed.

## Current Contract

DBVC validates:

- archive layout
- manifest filename and JSON shape
- `package_type`
- `package_schema_version`
- `source_sample_package.site_fingerprint`
- `intended_operation`
- entity path shape
- required entity fields
- object key and slug alignment
- blocked fields and meta keys
- basic top-level field types

Allowed `intended_operation` values:

- `create_only`
- `update_only`
- `create_or_update`

Post/CPT required fields:

- `ID`
- `post_type`
- `post_title`
- `post_name`

Term required fields:

- `term_id`
- `taxonomy`
- `name`
- `slug`

Blocked post meta keys:

- `dbvc_post_history`
- `_dbvc_import_hash`

Blocked term meta keys:

- `dbvc_term_history`

Blocked term top-level fields:

- `parent_uid`

## Authoring Rules

- Use `dbvc_ai_submission_package` for returned packages.
- Do not upload a `dbvc_ai_sample_package` as an import package.
- Keep IDs numeric.
- Keep `meta` and `tax_input` as objects when present.
- Make `post_type` match the post path.
- Make `taxonomy` match the term path.
- Make entity filename slugs match payload slugs.
- Use update identity only when updating existing content.

## Nuance

A site fingerprint mismatch normally blocks import unless local policy explicitly downgrades that condition. Missing fingerprints still block.

Unexpected top-level fields warn. Blocked fields error. A package that passes manifest and entity validation can still block during translation if ACF values are unsupported or unsafe.

Update detection uses `vf_object_uid` or a positive numeric ID where available. Create entities use `ID: 0` or `term_id: 0`.

## Examples

See `examples/submission-manifest-create-only.json` for the smallest create package manifest.

## Maintenance Notes

Update this file when `RulesService`, validation severity, manifest compatibility, blocked fields, identity matching, or translator blocking behavior changes.
