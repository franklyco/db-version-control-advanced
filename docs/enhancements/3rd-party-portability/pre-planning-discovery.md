# 3rd Party Portability: Pre-Planning Discovery

## Objective

Extend DBVC core import/export beyond WordPress posts and taxonomy terms so plugin-owned entities can travel with DBVC JSON packages.

Initial provider: WS Form.

Initial scope:

- WS Form form definitions.
- WS Form non-sensitive settings.
- Configuration under Configure -> Import Defaults -> 3rd Party Portability.

Out of scope for the first slice:

- WS Form submissions.
- WS Form stats.
- WS Form style entities as first-class portable records.
- Broad support for unrelated form plugins before their storage contracts are inspected.
- Matching forms by label or numeric ID when a DBVC portability UID is unavailable.

## DBVC Integration Points

Exports currently run through these core paths:

- `DBVC_Sync_Posts::export_options_to_json()`
- `DBVC_Sync_Posts::export_menus_to_json()`
- `DBVC_Sync_Taxonomies::export_selected_taxonomies()`
- `DBVC_Sync_Posts::export_post_to_json()`
- `DBVC_Backup_Manager::generate_manifest()`

The new third-party export slice hooks into `dbvc_after_export_options`, matching the existing ACF options group integration. This keeps third-party entities in the same full/chunk/WP-CLI export flow before the final manifest is generated.

Imports currently run through:

- `DBVC_Sync_Taxonomies::import_taxonomies()`
- `DBVC_Sync_Posts::import_all()`
- `DBVC_Sync_Posts::import_backup()`
- upload routing through `DBVC_Import_Router`

The first implementation adds a third-party sync folder and imports enabled provider payloads after post imports. Backup manifest restores route `item_type=third_party` entries through the same provider adapter.

## Sync Layout

WS Form payloads are written under:

```text
third-party/ws-form/forms/ws-form-<source-id>-<slug>.json
third-party/ws-form/settings.json
```

This keeps plugin-owned entities separate from CPT folders, taxonomy folders, `options.json`, and ACF options group exports.

## WS Form Storage Discovery

Installed provider detected on the LocalWP site:

- Plugin folder: `wp-content/plugins/ws-form-pro`
- Version observed: `1.10.53`
- Identifier constant: `WS_FORM_IDENTIFIER = ws_form`
- Database table prefix constant: `WS_FORM_DB_TABLE_PREFIX = wsf_`

WS Form creates custom tables:

- `wp_wsf_form`
- `wp_wsf_form_meta`
- `wp_wsf_form_stat`
- `wp_wsf_group`
- `wp_wsf_group_meta`
- `wp_wsf_section`
- `wp_wsf_section_meta`
- `wp_wsf_field`
- `wp_wsf_field_meta`
- `wp_wsf_submit`
- `wp_wsf_submit_meta`
- `wp_wsf_style`
- `wp_wsf_style_meta`

Observed live table counts at discovery time:

- Forms: 13
- Form meta rows: 2110
- Groups: 24
- Group meta rows: 149
- Sections: 58
- Section meta rows: 1395
- Fields: 250
- Field meta rows: 9186
- Form stat rows: 558
- Styles: 2
- Style meta rows: 1382
- Submissions: 3
- Submission meta rows: 45

## WS Form Form Shape

WS Form provides a form object API:

- `WS_Form_Form::db_read(true, true, false, false, true)` reads a full form definition with groups, sections, fields, and meta.
- `WS_Form_Form::db_update_from_object($form_object, true, true, true)` imports a full form object.
- `WS_Form_Form::db_import_reset()` clears an existing form before rebuilding it.
- `db_conditional_repair()`, `db_action_repair()`, `db_meta_repair()`, `db_checksum()`, and `db_publish()` repair and publish after import.

The first DBVC adapter uses WS Form's own API rather than writing group, section, field, or meta rows directly.

DBVC adds a WS Form form meta key:

```text
dbvc_portability_uid
```

Import matching rule:

- Match only by `dbvc_portability_uid`.
- If a payload has no UID, generate a UID and create a new form.
- Do not match by source numeric ID or label.

This avoids writing a remote form payload into the wrong local WS Form record.

## WS Form Settings Shape

Relevant options discovered:

- `ws_form`
- `ws_form_css`

The `ws_form` option contains provider settings and may contain sensitive values. Discovery found key families that include license keys, API keys, secret keys, tokens, and provider credentials.

First-pass policy:

- Export non-sensitive settings only.
- Exclude keys containing sensitive patterns such as `license`, `api_key`, `secret`, `token`, `password`, `private_key`, and `client_secret`.
- Preserve local sensitive values during import.
- Include excluded key paths in `settings.json` for operator visibility.

## Manifest Shape

`DBVC_Backup_Manager` classifies WS Form payloads as:

```json
{
  "item_type": "third_party",
  "third_party_provider": "ws_form",
  "third_party_object_type": "form",
  "third_party_uid": "...",
  "third_party_label": "..."
}
```

Settings payloads use:

```json
{
  "item_type": "third_party",
  "third_party_provider": "ws_form",
  "third_party_object_type": "settings",
  "third_party_label": "WS Form settings"
}
```

## Safety Contract

First-pass imports are capability-gated by DBVC admin actions or WP-CLI command context, then run WS Form mutations with scoped WS Form capabilities granted only during the adapter call.

Allowed first-pass mutations:

- Create a new WS Form form if no matching DBVC portability UID exists.
- Replace an existing WS Form form only when the payload UID matches local WS Form form meta.
- Update non-sensitive WS Form settings while preserving local sensitive values.

Disallowed first-pass mutations:

- Deleting local WS Form forms.
- Importing submissions or stats.
- Importing settings secrets.
- Inferring form identity from title, slug, or numeric source ID.

## Validation Plan

Minimum validation after implementation:

- `php -l` on touched PHP files.
- `git diff --check`.
- Confirm Configure -> Import Defaults -> 3rd Party Portability renders.
- Run a manual export on the LocalWP site after the user approves DB access/browser checks.
- Inspect generated `third-party/ws-form/forms/*.json` and `third-party/ws-form/settings.json`.
- Confirm `settings.json` excludes secret-bearing option keys.
- Confirm `manifest.json` classifies WS Form payloads as `third_party`.
- Use disposable WS Form fixtures before running a destructive import smoke test.

## Export Smoke Results: 2026-05-19

LocalWP target:

```text
/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/themes/vertical/sync/db-version-control-main/
```

Smoke command:

- Enabled WS Form forms and non-sensitive WS Form settings.
- Left trashed WS Form forms disabled.
- Ran `DBVC_Third_Party_Portability::export_selected_entities()`.
- Regenerated `manifest.json`.

Observed output:

- Exported files: 11.
- WS Form form payloads: 10.
- WS Form settings payloads: 1.
- Manifest `third_party` entries: 11.
- Manifest labels and paths matched generated WS Form files.
- Settings payload included `ws_form` and `ws_form_css`.
- Sensitive-key scan under `settings.json -> options` found 0 exported keys matching secret patterns.
- `settings.json -> excluded_keys -> ws_form` recorded 35 excluded key paths.

UI follow-up from smoke:

- The first direct export attempt returned 0 because the custom Export Now action read saved options only.
- The handler now persists posted 3rd Party Portability checkbox values before exporting.

Import status:

- Form import smoke completed against approved disposable target WS Form ID 15.
- The smoke imported a temporary payload with only the label changed to `DBVC Portability Smoke Test - Form 15`.
- Import result returned success.
- WS Form ID 15 kept the same `dbvc_portability_uid`.
- WS Form ID 15 kept `publish` status.
- The original exported payload was then imported back successfully.
- Final label restored to `New Web Client Onboarding Form`.
- Final UID match count: 1.
- Final WS Form row count: 13, so no duplicate form was created.
- Partial backup restore smoke completed with a one-entry DBVC backup fixture.
- Fixture backup: `dbvc-third-party-smoke-15-20260519081742`.
- Fixture manifest contained one `item_type=third_party` WS Form form entry.
- `DBVC_Sync_Posts::import_backup($backup_name, ['mode' => 'partial', 'ignore_missing_hash' => true])` returned `imported=1`, `skipped=0`, and no errors.
- Backup restore temporarily changed form ID 15 to `DBVC Backup Restore Smoke - Form 15`.
- The original exported payload was imported back after the restore smoke.
- Final label after backup restore smoke: `New Web Client Onboarding Form`.
- Final UID match count after backup restore smoke: 1.
- Full restore mode was not used because it rewrites the sync folder; partial restore now includes `third_party` manifest entries directly.

Read-only probe:

- Added `scripts/check-third-party-portability.php`.
- The probe validates WS Form payload contract fields, settings redaction, and `third_party` manifest classification.
- Probe result on 2026-05-19: forms=10, settings=1, manifest `third_party` entries=11, excluded settings keys=35.
