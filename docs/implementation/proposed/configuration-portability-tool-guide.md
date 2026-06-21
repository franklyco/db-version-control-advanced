# DBVC Configuration Portability Tool Implementation Guide

Last updated: 2026-05-19
Current phase: `P1`
Status legend: `OPEN` | `WIP` | `CLOSED` | `DEFERRED`

## Current Answer

No unified DBVC configuration/settings portability tool is implemented yet.

Related pieces already exist, but they solve narrower problems:

- Bricks Portability exports/imports Bricks-owned artifacts and drift domains, not all DBVC plugin settings.
- Third-party portability currently covers supported plugin entities such as WS Form definitions and selected non-sensitive settings.
- The existing DBVC Configure screens save many option-backed settings, but there is no single downloadable DBVC configuration package with import review, environment replacements, redaction, backup, or rollback.

This guide defines the new `dbvc-configuration-portability-tool` as a registry-backed package workflow for DBVC settings only.

## Objective

Add a DBVC-native tool that lets agencies export the current site's DBVC configuration into a downloadable package and import that package on another WordPress site running DBVC.

The tool must preserve agency baseline settings while making environment-specific values explicit, reviewable, replaceable, or excluded before apply.

Primary use cases:

- Configure DBVC once on a source site, then apply the same baseline to client/staging/production sites.
- Share default DBVC core settings, add-on settings, tools settings, media handling, masking, AI package generation rules, logging preferences, and Bricks governance policies.
- Avoid leaking or incorrectly applying site-local paths, URLs, credentials, transient state, logs, runtime reports, or site identity.
- Let importers preview exactly which settings will change before anything is written.

## Non-Goals

Do not include in v1:

- Raw all-`dbvc_%` option dump/import.
- Direct import of arbitrary option names from uploaded JSON.
- Content/entity portability. Posts, terms, media, ACF options content, Bricks templates, Bricks global classes, and third-party objects remain handled by their existing portability/import systems.
- Encrypted secret transport. v1 should exclude secrets by default and prompt on import when a secret is required.
- Remote site-to-site push/pull.
- Multisite fleet propagation.
- Scheduled drift monitoring for configuration baselines.
- Automatic creation of missing plugins, add-ons, post types, taxonomies, ACF groups, or external API accounts.

## Recommended UX Location

Start inside the existing DBVC admin footprint:

- Primary entry point: `DBVC Export -> Configure -> Configuration Portability`.
- Optional later split: a dedicated `DBVC -> Config Portability` submenu if the review UI outgrows the Configure tab.

Reasoning:

- The workflow is about settings, so operators expect it near Configure.
- The existing Configure screen already groups core, import, media, AI, and add-on settings.
- A dedicated class should render the tool UI so `admin/admin-page.php` does not absorb more responsibility.

Recommended v1 admin files:

- `admin/class-configuration-portability-page.php`
- `includes/Dbvc/ConfigurationPortability/DomainProviderInterface.php`
- `includes/Dbvc/ConfigurationPortability/Registry.php`
- `includes/Dbvc/ConfigurationPortability/PackageService.php`
- `includes/Dbvc/ConfigurationPortability/ImportSessionStore.php`
- `includes/Dbvc/ConfigurationPortability/DiffService.php`
- `includes/Dbvc/ConfigurationPortability/BackupService.php`
- `includes/Dbvc/ConfigurationPortability/ApplyService.php`
- `includes/Dbvc/ConfigurationPortability/RestController.php`

## Core Principle

This feature must be option-aware, not option-blind.

Every exported setting must come from a registered domain provider that declares:

- option keys it owns
- labels, grouping, defaults, and field-level metadata
- sanitizer/apply method
- environment-specific fields
- secret fields
- export default policy
- import strategy
- compatibility version

Uploaded packages are untrusted. The importer must ignore unknown domains and unknown field keys unless a registered provider explicitly accepts them.

## Settings Domain Model

Each settings domain should implement a narrow provider contract.

```php
interface DomainProviderInterface
{
    public function get_key(): string;
    public function get_label(): string;
    public function get_version(): int;
    public function get_groups(): array;
    public function get_fields(): array;
    public function export(array $selection, array $context): array;
    public function diff(array $incoming, array $current, array $context): array;
    public function sanitize_for_apply(array $incoming, array $resolved_environment, array $current): array;
    public function apply(array $sanitized, array $context): array;
    public function capture_backup(array $context): array;
    public function rollback(array $backup, array $context): array;
}
```

The exact PHP interface can evolve, but the provider boundary should stay.

## Provider Field Metadata

Each field should expose metadata like this:

```php
[
    'key' => 'dbvc_bricks_api_secret',
    'label' => 'API Secret',
    'type' => 'secret',
    'group' => 'connection',
    'default_export' => 'exclude',
    'environment_policy' => 'prompt',
    'apply_strategy' => 'keep_existing_unless_supplied',
    'sensitive' => true,
    'requires_confirmation' => true,
]
```

Recommended `environment_policy` values:

- `portable`: safe to export and apply as-is.
- `exclude`: never export.
- `redact`: export placeholder metadata only.
- `prompt`: export placeholder metadata and require importer input or keep-existing.
- `replace`: export source value but offer search/replace during import.
- `keep_existing`: import package cannot overwrite target value unless manually overridden.
- `advanced`: hidden behind expanded advanced controls.

Recommended `apply_strategy` values:

- `replace`
- `merge_assoc`
- `merge_list`
- `keep_existing_unless_supplied`
- `clear_if_confirmed`
- `provider_custom`

## Package Format

Use a ZIP package with JSON payloads.

Recommended layout:

```text
dbvc-configuration-package.zip
  manifest.json
  site.json
  domains/
    core-import-export.json
    masking.json
    media-handling.json
    logging.json
    master-tools.json
    ai-package.json
    content-collector.json
    bricks-addon.json
    visual-editor.json
    third-party-portability-settings.json
  redactions.json
  checksums.json
```

### `manifest.json`

```json
{
  "package_id": "dbvc-config-export-20260519T143012Z-a1b2c3",
  "package_type": "dbvc_configuration_portability",
  "package_version": 1,
  "created_at_gmt": "2026-05-19T14:30:12Z",
  "generator": {
    "plugin": "DBVC",
    "feature": "Configuration Portability Tool",
    "feature_version": "0.1.0"
  },
  "compatibility": {
    "min_dbvc_version": "1.1.0",
    "domains": {
      "bricks_addon": 1,
      "ai_package": 1
    }
  },
  "selected_domains": [
    "core_import_export",
    "media_handling",
    "bricks_addon"
  ],
  "contains_secrets": false,
  "contains_environment_placeholders": true
}
```

### `site.json`

```json
{
  "site_name": "Source Site",
  "home_url": "https://source.example",
  "wp_version": "6.x",
  "php_version": "8.x",
  "dbvc_version": "1.1.0",
  "active_theme": "theme-slug",
  "export_user_id": 1,
  "export_user_label": "admin",
  "notes": "Agency baseline for DBVC client sites"
}
```

### Domain Payload Shape

```json
{
  "domain": "bricks_addon",
  "label": "Bricks Add-on",
  "domain_version": 1,
  "exported_at_gmt": "2026-05-19T14:30:12Z",
  "groups": {
    "connection": {
      "label": "Connection",
      "fields": {
        "dbvc_bricks_role": {
          "value": "client",
          "policy": "portable"
        },
        "dbvc_bricks_mothership_url": {
          "value": "${DBVC_BRICKS_MOTHERSHIP_URL}",
          "policy": "prompt",
          "source_value_redacted": true
        },
        "dbvc_bricks_api_secret": {
          "value": null,
          "policy": "exclude",
          "source_value_redacted": true
        }
      }
    }
  }
}
```

## Environment-Specific Inputs

The import review must give operators a clear control for each environment-specific field.

Per-field import choices:

- `Use package value`
- `Replace with entered value`
- `Keep current target value`
- `Clear value`
- `Skip field`

Default import behavior should be conservative:

- secrets: `Keep current target value`
- local paths: `Keep current target value` or `Replace with entered value`
- source URLs/domains: `Replace with entered value`
- site identity: `Keep current target value`
- transient/runtime state: `Skip field`

Specific Bricks examples:

| Field | Default export | Default import |
|---|---|---|
| `dbvc_bricks_role` | include | use package value after confirmation |
| `dbvc_bricks_site_uid` | redact/prompt | keep current or prompt |
| `dbvc_bricks_mothership_url` | placeholder | prompt |
| `dbvc_bricks_api_key_id` | placeholder | prompt or keep current |
| `dbvc_bricks_api_secret` | exclude | keep current unless entered |
| `dbvc_bricks_intro_handshake_token` | exclude | keep current or clear with confirmation |
| `dbvc_bricks_client_registry_state` | exclude | keep current |
| `dbvc_bricks_local_instance_uuid` | exclude | keep current |
| `dbvc_bricks_connected_sites` | exclude in v1 | skip |
| `dbvc_bricks_clients` | exclude in v1 | skip |

AI and Content Collector API keys should follow the same pattern:

- `dbvc_ai_package_settings.providers.api_key`: exclude.
- `dbvc_cc_settings.openai_api_key`: exclude.
- provider/model choices: include.

## Initial Domain Inventory

This is the starting registry inventory. Phase 1 must turn this into executable provider metadata.

| Domain | Source | Include in v1 | Notes |
|---|---|---:|---|
| Core post type selection | `dbvc_post_types` | yes | Validate against available post types on import. Missing post types should warn and skip. |
| Core taxonomy selection | `dbvc_taxonomies`, taxonomy export flags | yes | Validate against available taxonomies. |
| Core filename/import policy | `dbvc_export_filename_format`, `dbvc_import_filename_format`, `dbvc_use_slug_in_filenames` | yes | Keep legacy flag in sync. |
| Core sync path | `dbvc_sync_path` | prompt | Site-local path. Do not apply silently. |
| FTP upload window | `dbvc_sync_ftp_window_until` | no | Runtime state, never portable. |
| Import defaults | `dbvc_allow_new_posts`, `dbvc_new_post_status`, `dbvc_new_post_types_whitelist`, `dbvc_import_require_review`, `dbvc_force_reapply_new_posts`, `dbvc_prefer_entity_uids`, `dbvc_allow_uid_fallback_matching`, `dbvc_diff_ignore_paths` | yes | Whitelists need target validation. UID fallback matching should stay disabled for staging/production sync profiles unless legacy JSON fallback is intentional. |
| Mirror domain | `dbvc_mirror_domain`, `dbvc_export_use_mirror_domain`, `dbvc_strip_domain_urls` | prompt | Environment-specific URL behavior. |
| Masking defaults | `dbvc_mask_defaults_meta_keys`, `dbvc_mask_defaults_subkeys`, `dbvc_mask_post_fields`, `dbvc_auto_export_mask_mode`, `dbvc_auto_export_mask_placeholder` | yes | Safe if reviewed. |
| Runtime export mask selections | `dbvc_export_last_mask_mode`, `dbvc_mask_action`, `dbvc_mask_meta_keys`, `dbvc_mask_subkeys`, `dbvc_mask_placeholder` | advanced | Some are last-run state. Default exclude except explicit advanced profile. |
| Options group selection | `dbvc_options_groups`, `dbvc_export_options_groups` | yes | This exports selection config, not ACF option values. Validate group availability. |
| Media handling | `DBVC_Media_Sync` option constants | yes | Local behavior, no temp maps. |
| Logging | `DBVC_Sync_Logger` option constants | partial | Include toggles and max size. Prompt for logging directory. Do not include logs. |
| Master tools | `DBVC_Master_Settings::OPTION_SETTINGS` | yes | Use existing sanitizer/defaults. |
| AI package | `Dbvc\AiPackage\Settings::OPTION_SETTINGS` | yes | Exclude provider API key. Include generation, validation, guidance, rules, provider/model choices. |
| Content Collector v1 settings | `DBVC_CC_Contracts::OPTION_SETTINGS` | yes | Exclude OpenAI key. Prompt for storage path. |
| Content Collector feature flags | `DBVC_CC_Contracts::get_feature_flag_defaults()` keys | yes | Include with explicit add-on selection. |
| Content Collector v2 settings | `DBVC_CC_V2_Contracts::get_default_values()` keys | yes | Use existing save/settings class. |
| Content Collector scrub approval status | `dbvc_cc_scrub_policy_approval_status` | advanced | Site/workflow state. Default exclude. |
| Bricks add-on settings | `DBVC_Bricks_Addon::get_settings_schema()` | yes | Use schema types, exclude secrets/runtime identity by default. |
| Bricks protected variants | `dbvc_bricks_protected_variants` | advanced | Governance data, not simple settings. Include only after review model exists. |
| Bricks connected sites/onboarding | `dbvc_bricks_connected_sites`, `dbvc_bricks_site_aliases`, `dbvc_bricks_clients`, onboarding transport | no in v1 | Fleet/runtime state and credentials. |
| Bricks UI diagnostics/idempotency | diagnostics and idempotency options | no | Runtime state. |
| Visual Editor activation | `DBVC_Visual_Editor_Addon::OPTION_ENABLED` | yes | Simple add-on toggle. |
| Visual Editor journal schema | `dbvc_visual_editor_journal_schema_version` | no | Internal storage/runtime state. |
| Third-party portability selections | `DBVC_Third_Party_Portability` option constants | yes | Include selection toggles, not exported WS Form entities. |
| Upload reports and resolver temp stores | `dbvc_sync_upload_report`, `dbvc_ai_upload_report`, resolver decisions, media temp maps | no | Runtime/review state, not portable config. |

## Export Profiles

Provide presets so agencies do not need to hand-pick every field.

### Agency Baseline

Default profile.

Includes:

- core post type/taxonomy selections
- import defaults
- masking defaults
- media handling
- logging toggles without directory
- master tool settings
- AI package non-secret settings
- Content Collector non-secret settings
- Bricks non-secret policies and operations
- Visual Editor activation
- third-party portability selection toggles

Excludes:

- API keys and secrets
- Bricks site identity and handshake state
- connected-site registry state
- logs, temp maps, diagnostics, upload reports
- sync/logging paths unless replaced with placeholders

### Add-On Baseline

Includes only add-on settings:

- Content Collector
- Bricks add-on
- Visual Editor
- AI package
- third-party portability toggles

### Core Import/Export Baseline

Includes only core DBVC behavior:

- post types
- taxonomies
- import defaults
- filename settings
- masking defaults
- media handling
- options group selection

### Full Review Package

Advanced profile.

Includes all registered non-secret fields and redacted placeholders for environment-specific fields. Requires per-field import review before apply.

### Local Clone Package

Deferred. This would allow optional secret inclusion or encrypted secret handling. Do not build until a real encryption and key-exchange model exists.

## Import Workflow

1. Upload ZIP package.
2. Validate ZIP structure, manifest, checksums, package type, package version, and domain versions.
3. Load only domains that have registered providers on the target site.
4. Build current target settings snapshot through providers.
5. Compare incoming vs current values.
6. Resolve environment fields:
   - prompt for missing required values
   - keep current secrets unless user supplies replacements
   - validate target post types, taxonomies, ACF option groups, and add-on availability
7. Show review table:
   - domain
   - group
   - field
   - current value
   - incoming value
   - effective value
   - policy
   - warning
   - decision
8. Save draft decisions in an import session.
9. Require final confirmation.
10. Capture backup of all affected domains.
11. Apply through provider sanitizers.
12. Report applied, skipped, blocked, and failed fields.
13. Offer rollback from backup.

## Diff Status Model

Each field diff should produce one of these statuses:

- `same`
- `changed`
- `incoming_missing`
- `target_missing`
- `blocked_secret`
- `needs_environment_value`
- `unsupported_field`
- `unsupported_domain`
- `target_dependency_missing`
- `skipped_by_policy`
- `ready`
- `applied`
- `failed`

## Import Decisions

Per-field decisions:

- `apply`
- `keep_current`
- `replace_value`
- `clear`
- `skip`

Bulk decisions:

- apply all safe portable values
- keep all environment-specific values
- skip all missing target dependency fields
- skip whole domain

Never allow a bulk action to apply secret or prompt-required fields without explicit entered values.

## Backup And Rollback

Before apply, create a backup package that records:

- backup id
- created at
- user id
- source import session id
- affected domains
- exact current values
- whether each option existed before apply
- target site context

Rollback should:

- validate the backup type and checksum
- restore only option keys owned by the backup domains
- delete options that did not exist before apply only when the backup says they were newly created
- call each provider's post-apply/runtime refresh hook
- record a rollback activity entry

Recommended storage:

```text
wp-content/uploads/sync/dbvc-config-portability/
  exports/
  sessions/
  backups/
  logs/
```

Use the existing sync directory security patterns for this storage.

## Security Rules

- Require `manage_options` for every admin and REST action in v1.
- Use nonces for form actions and REST nonce handling for admin requests.
- Reject non-ZIP uploads.
- Reject ZIPs with path traversal entries or unexpected top-level files.
- Validate checksums before reading domain payloads.
- Never trust domain keys, field keys, types, labels, or policies from the package without provider confirmation.
- Do not log raw secrets or full incoming payloads.
- Do not export secrets by default.
- Do not apply unsupported fields.
- Do not write arbitrary options.
- Do not evaluate package-provided PHP, SQL, regex beyond approved field validators, or file paths.
- Keep package import idempotent through session ids and apply confirmation.

## Provider Apply Reuse

Prefer existing sanitizers and save methods:

- Bricks add-on: `DBVC_Bricks_Addon::save_settings()` for schema-backed fields.
- Visual Editor: `DBVC_Visual_Editor_Addon::save_settings()`.
- Content Collector V2: `DBVC_CC_V2_Configure_Addon_Settings::save_settings()`.
- Content Collector V1 settings: `DBVC_CC_Settings_Service::sanitize_settings()` followed by controlled `update_option()`.
- AI package: `Dbvc\AiPackage\Settings::save_settings()`, with API key stripped unless provided during import.
- Master tools: `DBVC_Master_Settings` helper methods or a new narrow save method.
- Logging and media handling: new provider sanitizers around existing constants.
- Core standalone settings: new provider sanitizer to replace the current ad hoc Configure save logic.

The portability apply service should not duplicate each module's validation rules when a module already owns them.

## REST Endpoints

Recommended namespace:

- `GET /wp-json/dbvc/v1/config-portability/status`
- `POST /wp-json/dbvc/v1/config-portability/export`
- `POST /wp-json/dbvc/v1/config-portability/import`
- `GET /wp-json/dbvc/v1/config-portability/sessions/{session_id}`
- `POST /wp-json/dbvc/v1/config-portability/sessions/{session_id}/environment`
- `POST /wp-json/dbvc/v1/config-portability/sessions/{session_id}/draft`
- `POST /wp-json/dbvc/v1/config-portability/sessions/{session_id}/refresh`
- `POST /wp-json/dbvc/v1/config-portability/apply`
- `GET /wp-json/dbvc/v1/config-portability/backups`
- `POST /wp-json/dbvc/v1/config-portability/backups/{backup_id}/rollback`

Use the Bricks Portability REST controller as a pattern for idempotency, sessions, confirmation, and rollback, but keep the implementation generic and DBVC-wide.

## Admin UI Shape

### Export Panel

Controls:

- profile selector
- domain checkboxes
- expandable group/field checkboxes
- environment handling selector:
  - exclude environment fields
  - include placeholders
  - include prompted fields without values
- notes textarea
- export package button

Warnings:

- secrets are excluded by default
- target site must have DBVC and relevant add-ons installed
- missing post types/taxonomies/ACF groups will be skipped unless available on import target

### Import Panel

Controls:

- ZIP upload
- package summary
- compatibility warnings
- environment replacement form
- field diff/review table
- draft decisions
- final apply confirmation
- backup/rollback history

UI priority:

- show risky fields first
- group by domain and setting group
- use compact rows with expandable details
- show redacted values as `[redacted]`, never blank ambiguity

## Implementation Progress Tracker

| Phase | Status | Goal |
|---|---|---|
| `P0` | `CLOSED` | Initial repository discovery and guide creation |
| `P1` | `DONE` | Build configuration domain registry and provider contracts |
| `P2` | `DONE` | Implement export package builder with redaction and profiles |
| `P3` | `WIP` | Implement import validation, sessions, and diff engine |
| `P4` | `WIP` | Add admin UI and REST endpoints |
| `P5` | `WIP` | Add apply, backup, rollback, and runtime refresh |
| `P6` | `WIP` | Expand granular environment replacement controls |
| `P7` | `WIP` | Add PHPUnit coverage and safe runtime smoke tests |
| `P8` | `DEFERRED` | Add WP-CLI parity, encrypted secret transport, and fleet workflows |

Update this table at the end of each implementation tranche.

## Phase 0 - Discovery And Guide

Status: `CLOSED`

### Confirmed

- No existing whole-plugin DBVC configuration package tool was found.
- Current related portability systems are domain-specific, not settings-wide.
- DBVC settings are spread across core standalone options, structured option arrays, add-on option schemas, and runtime state options.
- Several existing settings are sensitive or environment-specific and cannot be blindly moved between sites.

### Files reviewed

- `AGENTS.md`
- `admin/admin-page.php`
- `admin/admin-menu.php`
- `includes/class-master-settings.php`
- `includes/class-sync-logger.php`
- `includes/class-media-sync.php`
- `includes/class-third-party-portability.php`
- `includes/Dbvc/AiPackage/Settings.php`
- `addons/bricks/bricks-addon.php`
- `addons/bricks/bricks-connected-sites.php`
- `addons/bricks/bricks-onboarding.php`
- `addons/bricks/bricks-protected-variants.php`
- `addons/bricks/portability/class-dbvc-bricks-portability-rest-controller.php`
- `addons/bricks/portability/class-dbvc-bricks-portability-package-service.php`
- `addons/content-migration/settings/dbvc-cc-settings-service.php`
- `addons/content-migration/shared/dbvc-cc-contracts.php`
- `addons/content-migration/v2/admin/dbvc-cc-v2-configure-addon-settings.php`
- `addons/content-migration/v2/shared/dbvc-cc-v2-contracts.php`
- `addons/visual-editor/bootstrap.php`

## Phase 1 - Registry And Provider Contracts

Status: `DONE`

### 2026-05-19 tranche notes

- Added the initial `Dbvc\ConfigurationPortability` provider contract, field metadata helper, option-backed provider base, and registry.
- Wired the registry classes into plugin bootstrap.
- Added first safe providers for:
  - Visual Editor activation
  - DBVC logging toggles/max-size/directory prompt
  - media handling settings
- Added PHPUnit coverage for registry discovery, provider filtering, environment redaction, unknown-field ignore behavior, apply/rollback, and invalid media values.

### 2026-05-19 follow-up tranche notes

- Extended field metadata and sanitization support for text, textarea, URL, key-list, and string-list fields.
- Added scalar option-backed providers for:
  - core import/export settings
  - masking defaults with runtime masking fields kept advanced-only
  - third-party portability settings
  - Bricks add-on settings with API secret and handshake token excluded and site linkage fields prompt-redacted
- Added PHPUnit coverage for core environment path redaction, masking advanced-field exclusion, third-party setting apply behavior, and Bricks secret redaction.

### 2026-05-19 nested settings tranche notes

- Added reusable nested option-array provider support for settings stored inside one WordPress option.
- Added built-in providers for:
  - Master Tools sample-entity download defaults
  - AI Package generation, validation, guidance, rules, and provider/model defaults with provider API keys excluded
  - Content Collector v1 settings with storage folder prompt-redacted and OpenAI API key excluded
  - Content Collector runtime/V2 activation, feature flags, import policy, and automation thresholds with workflow state kept advanced-only
- Added PHPUnit coverage for nested option-array apply, AI API key preservation, Content Collector storage/API-key policy handling, and Content Collector V2 threshold validation.

### Outcome

Create the domain registry and enough providers to inventory, export, validate, and apply known DBVC settings without raw option guessing.

### Tasks

- [x] Add `DomainProviderInterface`.
- [x] Add `Registry` with provider registration and lookup.
- [x] Add base field metadata helpers for scalar, enum, path, JSON map, and option-backed fields.
- [x] Add provider result arrays for export, diff, apply, backup, and rollback.
- [x] Add built-in providers for:
  - [x] core import/export settings
  - [x] masking settings
  - [x] media handling
  - [x] logging
  - [x] master tools
  - [x] AI package
  - [x] Content Collector v1/v2
  - [x] Bricks add-on settings
  - [x] Visual Editor activation
  - [x] third-party portability selection settings
- [x] Add filter hook `dbvc_configuration_portability_domains`.
- [x] Add tests proving unknown provider values and unknown fields are ignored.

### Acceptance Criteria

- A status call can list every registered domain, group, and field.
- Each provider can export current values with environment/secret policies attached.
- Each provider can capture a current-value backup.
- No provider writes directly during export or diff.

## Phase 2 - Export Package Builder

Status: `DONE`

### 2026-05-19 package builder tranche notes

- Added storage helpers under uploads `dbvc/dbvc-config-portability/` with `exports`, `sessions`, and `backups` roots.
- Added the export package builder for registry-backed domain exports.
- Implemented package id generation, export profiles, optional per-domain field selection, `manifest.json`, `site.json`, `domains/*.json`, `redactions.json`, `checksums.json`, ZIP creation, workspace cleanup, and `record.json`.
- Added PHPUnit coverage for ZIP layout, checksums, environment redactions, and API secret exclusion.

### Outcome

Generate a downloadable ZIP package for selected domains and fields.

### Tasks

- [x] Add package id generation.
- [x] Add package workspace under `dbvc-config-portability/exports/`.
- [x] Add export profiles.
- [x] Add field selection resolver.
- [x] Add redaction and placeholder service.
- [x] Add manifest writer.
- [x] Add domain JSON writers.
- [x] Add checksums.
- [x] Add ZIP builder.
- [x] Add export record/history storage.
- [x] Add tests for package layout, checksums, and redacted secrets.

### Acceptance Criteria

- Default export package contains no secrets.
- Package validates against its own checksums.
- Exported package lists selected domains and skipped environment fields.
- Export can be downloaded from admin without leaving unsafe temporary files.

## Phase 3 - Import Validation, Sessions, And Diff

Status: `WIP`

### 2026-05-19 import session tranche notes

- Added upload package staging for DBVC configuration portability ZIP files.
- Added safe extraction with path traversal protection.
- Added manifest/package type/schema validation and checksum validation before session creation.
- Added import session storage under `dbvc-config-portability/sessions/`.
- Added registry-backed domain diff generation without writing target settings.
- Added PHPUnit coverage proving staged import diffs are generated and target option values are not applied.

### 2026-05-19 apply preflight tranche notes

- Added import apply preflight validation for required environment decisions.
- Unresolved prompt-redacted fields now block apply unless the operator explicitly keeps the current target value or supplies a replacement.

### 2026-05-19 compatibility warning tranche notes

- Added a lightweight session warning pass for package DBVC version, per-domain version mismatches, skipped domains, and empty target providers.
- Warnings are review-only and do not introduce a separate blocking workflow.

### Outcome

Upload a package, validate it, stage an import session, and compare incoming settings against current target settings.

### Tasks

- [x] Add ZIP upload validation.
- [x] Add safe extraction with path traversal protection.
- [x] Validate `manifest.json`, `checksums.json`, package type, and package version.
- [x] Add import session storage.
- [x] Add domain compatibility checks.
- [ ] Add dependency checks for post types, taxonomies, ACF option groups, and add-on classes.
- [x] Add diff service.
- [x] Add status model and warnings.
- [ ] Add draft decision persistence.
- [ ] Add tests for checksum failures, missing domains, unsupported fields, and target dependency warnings.
- [x] Add tests for package/domain compatibility warnings.

### Acceptance Criteria

- Import upload never writes DBVC settings.
- Unsupported package fields are shown as skipped or blocked, not applied.
- Environment-specific fields are clearly marked before apply.
- Diff rows can be regenerated from the session.

## Phase 4 - Admin UI And REST

Status: `WIP`

### 2026-05-19 first admin surface tranche notes

- Added a dedicated DBVC submenu page at `admin.php?page=dbvc-configuration-portability`.
- Added export profile/domain controls, ZIP download action, package upload form, package summary notices, and a first-pass diff preview table.
- Deferred REST endpoints, draft decisions, environment replacement inputs, apply, backup, and rollback to the next tranches.

### 2026-05-19 apply controls tranche notes

- Added import-session environment decision controls for prompt-redacted fields.
- Added explicit apply confirmation, backup-before-apply handling, applied-session summary, and rollback confirmation controls.
- Kept the current form-based admin workflow; REST endpoints, filters, draft decisions, and richer JS-assisted review remain open.

### 2026-05-19 warning display tranche notes

- Added a compact compatibility warning box to import review sessions.
- Kept warnings server-rendered with no new JavaScript or REST dependency.

### 2026-05-20 domain accordion tranche notes

- Replaced long per-domain diff tables with native server-rendered accordion sections.
- Added compact badges with icons for review-needed, changed, missing, secret-skipped, same, and other statuses.
- Added current/incoming preview columns inside each expanded domain so operators can inspect changes without leaving the accordion.

### Outcome

Expose export, import, review, environment replacement, draft, apply, and rollback controls.

### Tasks

- [ ] Add REST controller.
- [x] Add admin page renderer or Configure subtab integration.
- [x] Add export profile/domain controls.
- [x] Add upload form.
- [x] Add package summary panel.
- [x] Add first-pass diff table.
- [x] Add domain diff accordion.
- [x] Add status badges/icons for review, changed, missing, skipped secret, and same rows.
- [x] Add compatibility warnings display.
- [ ] Add domain/group/field filters.
- [x] Add environment replacement inputs.
- [ ] Add draft decisions.
- [x] Add apply confirmation checkbox.
- [x] Add backup/rollback controls.
- [ ] Add client-side JS only where needed for table filtering and environment prompts.

### Acceptance Criteria

- A manage-options user can export a default Agency Baseline package.
- A manage-options user can upload and review the package on another site.
- The UI distinguishes portable, secret, environment-specific, unsupported, and missing dependency fields.
- The UI never displays raw secret values.

## Phase 5 - Apply, Backup, Rollback, Runtime Refresh

Status: `WIP`

### 2026-05-19 apply/rollback service tranche notes

- Added import-session apply orchestration that preflights all selected domains before writing.
- Added backup capture into `dbvc-config-portability/backups/` before the first provider write.
- Apply uses each provider's sanitizer and apply method instead of raw option updates.
- Added rollback orchestration that restores provider backups and updates the session record.
- Added PHPUnit coverage for unresolved environment preflight, confirmed apply, backup capture, and rollback restore.

### Outcome

Apply approved settings safely and make the operation reversible.

### Tasks

- [x] Add apply service.
- [x] Require session id and explicit confirmation.
- [x] Capture backup before first write.
- [x] Apply by provider and field decision.
- [x] Use provider sanitizers or existing module save methods.
- [ ] Call runtime refresh hooks after relevant providers:
  - [x] Bricks runtime registration
  - [ ] Visual Editor runtime registration
  - [ ] Content Collector runtime registration
  - [ ] AI model catalog schedule refresh
- [x] Add rollback service.
- [ ] Add activity/log entries without secrets.
- [ ] Add partial failure reporting.
- [x] Add tests for backup and rollback.

### Acceptance Criteria

- No settings are written without backup.
- Rollback restores previous option existence and values.
- A failed provider does not hide which fields were or were not applied.
- Secrets are preserved unless explicitly supplied by the importer.

## Phase 6 - Granular Environment Controls

Status: `WIP`

### 2026-05-19 first environment controls tranche notes

- Added import-time keep-current or replace decisions for prompt-redacted fields included in the package.
- Added prompt-required validation before apply.
- Secrets that were excluded from the package remain blocked and must be configured directly until the explicit secret-supply workflow is added.

### Outcome

Make environment-specific values ergonomic for agencies running repeat imports.

### Tasks

- [ ] Add named environment replacement presets.
- [ ] Add import-time replacement for URLs/domains.
- [ ] Add import-time replacement for relative paths.
- [x] Add prompt-required field validation.
- [ ] Add per-domain environment policy overrides.
- [x] Add "keep target values" per-field action.
- [ ] Add warnings for Bricks mothership/client role mismatches.
- [ ] Add warnings for switching Visual Editor or Content Collector activation state.

### Acceptance Criteria

- Agencies can reuse one package across local, staging, and production targets.
- Bricks mothership URL/API secret handling is explicit.
- Import cannot proceed while required environment prompts are unresolved.

## Phase 7 - Tests, QA, And Documentation

Status: `WIP`

### Outcome

Lock the workflow with automated and manual validation.

### PHPUnit Coverage

- [x] Registry provider discovery.
- [ ] Core provider export and apply.
- [x] Secret redaction.
- [x] Package manifest/checksum validation.
- [x] Import session diff status.
- [x] Package/domain compatibility warnings.
- [ ] Missing dependency warnings.
- [x] Apply backup and rollback.
- [ ] Bricks secret keep-existing behavior.
- [x] AI package API key exclusion.
- [x] Unknown domain/field rejection.

### Runtime QA

- [ ] Export Agency Baseline from `dbvc-codexchanges.local`.
- [ ] Import same package back as a no-op session and verify same statuses.
- [ ] Modify a safe setting, import, review diff, apply, verify option value.
- [ ] Roll back and verify original option value.
- [ ] Confirm Bricks API secret is not exported.
- [ ] Confirm AI API keys are not exported.
- [ ] Confirm missing post type/taxonomy choices warn and skip.
- [ ] Confirm runtime refresh when Visual Editor activation changes.

### Documentation

- [ ] Add user-facing usage docs.
- [ ] Add developer provider docs.
- [ ] Add package schema reference.
- [ ] Add QA checklist.
- [ ] Add changelog entry.

## Phase 8 - Deferred Enhancements

Status: `DEFERRED`

Potential later work:

- WP-CLI commands:
  - `wp dbvc config export`
  - `wp dbvc config import`
  - `wp dbvc config apply`
  - `wp dbvc config rollback`
- Encrypted secret bundles.
- Remote package pull from agency baseline registry.
- Scheduled config drift checks.
- Multisite/fleet propagation.
- Saved review policies by agency/client.
- JSON schema files for package validation.
- Signed packages.
- Integration with Bricks mothership/client onboarding.

## Open Questions

- Should v1 add a new Configure subtab only, or a dedicated submenu from the beginning?
- Should `dbvc_sync_path` be imported as a relative path prompt, or excluded in the default Agency Baseline?
- Should Bricks `dbvc_bricks_role` be portable by default, or prompt-required?
- Should protected variants be treated as settings, governance state, or Bricks artifact metadata?
- Should Content Collector `dbvc_cc_scrub_policy_approval_status` be portable for agency baselines, or remain local workflow state?
- Should logging enabled state be portable when the target site is production?

## Anti-Patterns To Avoid

- Dumping every option whose name starts with `dbvc_`.
- Treating credentials as normal strings.
- Applying site identity fields from a source package without explicit operator action.
- Silently skipping invalid fields without a visible review status.
- Writing imported options before the review/apply step.
- Duplicating sanitizer logic that already exists in a module-specific settings class.
- Hiding partial apply failures.
- Mixing config portability sessions with Bricks portability sessions or content proposal sessions.
- Calling this "backup restore" or "site clone"; it is a settings baseline tool.

## Good First Milestone

Build a narrow vertical slice:

1. Registry with three providers:
   - Visual Editor activation
   - Logging toggles
   - Media handling settings
2. Export Agency Baseline ZIP.
3. Upload same ZIP.
4. Compare fields.
5. Apply one safe changed field after backup.
6. Roll back.

This proves the architecture before adding Bricks, AI package, and Content Collector settings with nested arrays and secret policies.
