# AI Package Manual QA Checklist

Last updated: 2026-06-16

## Runtime Smoke

- Run `php scripts/check-wp-runtime-ai-import-smoke.php`.
- Confirm the script reports `status: completed`.
- Confirm retained validation, translation, and import artifacts are written.
- Confirm relationship resolution reports processed and applied counts.

## Tools > Download Sample Entities

- Open `DBVC Export > Tools`.
- Confirm the schema readiness, ACF coverage, and current fingerprint summary render.
- Generate a sample package for at least one post type and one taxonomy.
- Confirm the default package profile is `Compact AI Chat`.
- Download the ZIP and confirm the compact package contains:
  - `dbvc-ai-manifest.json`
  - `START_HERE.md`
  - `SCHEMA_COMPACT.json`
  - one `samples/posts/{post_type}.json` file for each selected post type
  - one sibling `samples/posts/{post_type}.context.json` file for each selected post type
  - one `samples/terms/{taxonomy}.json` file for each selected taxonomy
  - one sibling `samples/terms/{taxonomy}.context.json` file for each selected taxonomy
- Confirm each `.context.json` file keeps only the authoring context needed by AI tools:
  - object context
  - field `type`
  - field `choices`
  - best available field `context`
- Confirm `START_HERE.md` and `SCHEMA_COMPACT.json` include the required returned `dbvc-ai-manifest.json` template with:
  - `package_type: "dbvc_ai_submission_package"`
  - `package_schema_version`
  - `source_sample_package.site_fingerprint`
  - `source_sample_package.package_schema_version`
  - `intended_operation`
  - `counts`
- Switch to `Full Reference` only when explicitly testing the richer package profile.

## Configure > AI + Integrations

- Open `DBVC Export > Configure > AI + Integrations`.
- Confirm generation defaults persist after saving.
- Confirm the package profile setting includes `Compact AI Chat` and `Full Reference`.
- Confirm the validation defaults include the site fingerprint mismatch policy:
  - `Block mismatched packages`
  - `Allow with warning`
- Save defaults without an API key and confirm settings persist.
- If testing provider catalog behavior in a safe local environment, add an API key and confirm the model catalog refreshes.
- Confirm the model dropdown still renders a valid fallback when the catalog is unavailable.

## Legacy Upload Guard

- Upload a non-AI DBVC JSON file.
- Upload a non-AI ZIP file.
- Confirm the legacy upload path behaves exactly as it did before the AI branch.

## AI Intake Blocking

- Upload a sample package ZIP to the import surface.
- Confirm DBVC blocks the package as the wrong package type.
- Upload a submission package with no usable source sample package fingerprint.
- Confirm DBVC blocks with `site_fingerprint_missing`.
- Set the mismatch policy to `Block mismatched packages`.
- Upload a submission package with a true mismatched fingerprint.
- Confirm DBVC blocks with `site_fingerprint_mismatch` before import.

## AI Intake Warnings

- Upload a submission package with a manifest/entity count mismatch.
- Confirm DBVC shows `valid_with_warnings`.
- Confirm warning confirmation is required when the warning policy is `confirm`.
- Confirm validation and translation artifacts are downloadable from the review surface.
- Set the mismatch policy to `Allow with warning`.
- Upload a submission package with a true mismatched fingerprint.
- Confirm DBVC downgrades `site_fingerprint_mismatch` to a warning and still requires the normal warning confirmation flow.
- Upload a legacy AI-generated submission manifest that has root `site_fingerprint` but no `source_sample_package.site_fingerprint`.
- Confirm DBVC accepts it with `site_fingerprint_legacy_top_level`.
- Upload a legacy AI-generated submission manifest that omits `intended_operation` but includes `validation_defaults.package_mode`.
- Confirm DBVC accepts it with `operation_inferred_from_validation_defaults`.
- If `validation_defaults.package_mode` is `create_and_update`, confirm DBVC normalizes it to `create_or_update` with `operation_legacy_alias`.

## Create and Update Flow

- Upload a `create_only`, `update_only`, or `create_or_update` AI package.
- Confirm update entities resolve by `vf_object_uid` first.
- Confirm update entities without a UID can still resolve through approved slug/numeric ID paths.
- Confirm update entities with a stale/unmatched UID block instead of falling back unless `dbvc_allow_uid_fallback_matching` is intentionally enabled for the QA environment.
- Confirm newly created entities receive post-import parent, taxonomy, and supported ACF relationship backfill when referenced elsewhere in the same package.

## ACF Coverage

- Confirm nested `group` fields import and rehydrate correctly.
- Confirm `checkbox` and multi-select values are stored as single array meta values.
- Confirm `repeater` rows import with the correct row count and field values.
- Confirm `flexible_content` imports layout order correctly.
- Confirm non-empty unsupported media fields such as `image`, `file`, and `gallery` are blocked in v1.

## Security and Permissions

- Confirm generation and import actions require an administrator-capable user.
- Confirm nonce failures do not perform package generation or import.
- Confirm retained artifact downloads are denied for invalid or tampered intake identifiers.
