# AI Package Manual QA Checklist

Last updated: 2026-04-09

## Runtime Smoke

- Run `php scripts/check-wp-runtime-ai-import-smoke.php`.
- Confirm the script reports `status: completed`.
- Confirm retained validation, translation, and import artifacts are written.
- Confirm relationship resolution reports processed and applied counts.

## Tools > Download Sample Entities

- Open `DBVC > Tools`.
- Confirm the schema readiness, ACF coverage, and current fingerprint summary render.
- Generate a sample package for at least one post type and one taxonomy.
- Download the ZIP and confirm it contains:
  - `dbvc-ai-manifest.json`
  - top-level docs such as `README.md`, `AGENTS.md`, `STARTER_PROMPT.md`, `VALIDATION_RULES.md`
  - schema artifacts
  - per-sample `.json` and `.md` files

## Configure > AI + Integrations

- Open `DBVC Export > Configure > AI + Integrations`.
- Confirm OpenAI is the default provider.
- Save defaults without an API key and confirm settings persist.
- Add an API key in a safe local environment and confirm the model catalog refreshes.
- Confirm the model dropdown still renders a valid fallback when the catalog is unavailable.

## Legacy Upload Guard

- Upload a non-AI DBVC JSON file.
- Upload a non-AI ZIP file.
- Confirm the legacy upload path behaves exactly as it did before the AI branch.

## AI Intake Blocking

- Upload a sample package ZIP to the import surface.
- Confirm DBVC blocks the package as the wrong package type.
- Upload a submission package with a mismatched fingerprint.
- Confirm DBVC blocks the package before translation/import.

## AI Intake Warnings

- Upload a submission package with a manifest/entity count mismatch.
- Confirm DBVC shows `valid_with_warnings`.
- Confirm warning confirmation is required when the warning policy is `confirm`.
- Confirm validation and translation artifacts are downloadable from the review surface.

## Create and Update Flow

- Upload a create-only or mixed create/update AI package.
- Confirm update entities resolve in priority order: `vf_object_uid`, slug, then numeric ID.
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
