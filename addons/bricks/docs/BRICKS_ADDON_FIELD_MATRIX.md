# DBVC Bricks Add-on Field Matrix

Date: 2026-02-14  
Status: Discovery/planning only (no implementation)

## 1) Purpose

This matrix defines the concrete UI/config fields for the DBVC Bricks Add-on, their option keys, validation rules, defaults, and engine touchpoints so implementation can stay deterministic and low-risk.

Terminology: posts/terms are **Entities**.

## 2) Bricks Artifact Registry (MVP + next)

### 2.1 Entity-backed artifacts

| Artifact Type | Storage | Identity | Selection Unit | Default Policy | Notes |
|---|---|---|---|---|---|
| `bricks_template` | WP Entities (`post_type=bricks_template`) | `vf_object_uid` (DBVC Entity UID) | per Entity | `REQUIRE_MANUAL_ACCEPT` | Includes template taxonomy/meta in export/apply pipeline. |

### 2.2 Option-backed artifacts

| Artifact Type | Option Key | Storage Table | Identity | Selection Unit | Default Policy |
|---|---|---|---|---|---|
| `bricks_components` | `bricks_components` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_components` (item-level optional later) | full option (MVP), item-level later | `REQUEST_REVIEW` |
| `bricks_global_classes` | `bricks_global_classes` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_global_classes` | full option (MVP), item-level later | `REQUEST_REVIEW` |
| `bricks_color_palette` | `bricks_color_palette` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_color_palette` | full option | `REQUEST_REVIEW` |
| `bricks_typography` | `bricks_typography` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_typography` | full option | `REQUEST_REVIEW` |
| `bricks_theme_styles` | `bricks_theme_styles` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_theme_styles` | full option | `REQUEST_REVIEW` |
| `bricks_element_defaults` | `bricks_element_defaults` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_element_defaults` | full option | `REQUEST_REVIEW` |
| `bricks_breakpoints` | `bricks_breakpoints` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_breakpoints` | full option | `REQUIRE_MANUAL_ACCEPT` |
| `bricks_custom_fonts` | `bricks_custom_fonts` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_custom_fonts` | full option | `REQUEST_REVIEW` |
| `bricks_icon_fonts` | `bricks_icon_fonts` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_icon_fonts` | full option | `REQUEST_REVIEW` |
| `bricks_custom_css` | `bricks_custom_css` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_custom_css` | full option | `REQUEST_REVIEW` |
| `bricks_custom_scripts_header` | `bricks_custom_scripts_header` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_custom_scripts_header` | full option | `REQUIRE_MANUAL_ACCEPT` |
| `bricks_custom_scripts_footer` | `bricks_custom_scripts_footer` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_custom_scripts_footer` | full option | `REQUIRE_MANUAL_ACCEPT` |
| `bricks_global_settings` | `bricks_global_settings` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_global_settings` | full option | `REQUEST_REVIEW` |
| `bricks_global_classes_locked` | `bricks_global_classes_locked` | `wp_options` / `wp_{blog_id}_options` | `option:bricks_global_classes_locked` | full option | `REQUEST_REVIEW` |

### 2.3 Default excludes (never ship in package)

| Option Key | Reason |
|---|---|
| `bricks_license_key` | secret/license |
| `bricks_license_status` | environment-specific |
| `bricks_remote_templates` | remote cache/noise |

## 3) UI Field Matrix: Configure -> General Settings -> Add-ons -> Bricks

## 3.0 Core Add-ons activation fields (Configure -> Add-ons)

| Field Label | Option Key | Type | Allowed Values / Validation | Default | Required | Used By |
|---|---|---|---|---|---|---|
| Enable Bricks Add-on | `dbvc_addon_bricks_enabled` | checkbox | `0/1` | `0` | no | global add-on bootstrap gate |
| Add-on Visibility Mode | `dbvc_addon_bricks_visibility` | select | `submenu_only`, `configure_and_submenu` | `configure_and_submenu` | yes | admin IA behavior |
| Publish Target Mode (mothership) | `dbvc_bricks_publish_target_mode` | select | `all_sites`, `selected_sites` | `all_sites` | yes | package audience control |
| Publish Allowed Site UIDs | `dbvc_bricks_publish_allowed_sites` | JSON list | list of known `site_uid` strings | `[]` | if target mode=selected_sites | package audience allowlist |

Activation behavior contract:
- `dbvc_addon_bricks_enabled=0`:
  - Bricks submenu is not registered under DBVC menu.
  - Bricks REST endpoints/hooks/jobs are not registered.
- `dbvc_addon_bricks_enabled=1`:
  - Bricks submenu is registered under top-level `dbvc-export`.
  - Bricks add-on UI/routes/jobs may load.

Input instructions (render as help text beneath input):
- `Enable Bricks Add-on`:
  - "Turn on to register Bricks submenu, routes, and jobs. Turn off to fully disable Bricks runtime."
- `Add-on Visibility Mode`:
  - `configure_and_submenu` (recommended): show Bricks settings in Configure and show submenu when enabled.
  - `submenu_only`: keep Bricks settings hidden from Configure UI and manage operations from submenu only.
- `Publish Target Mode`:
  - `all_sites`: package available to all connected sites.
  - `selected_sites`: package available only to selected connected sites in mothership table.
- `Publish Allowed Site UIDs`:
  - managed from connected-sites selector table (do not require manual JSON editing in normal operation).

## 3.1 Connection tab

| Field Label | Option Key | Type | Allowed Values / Validation | Default | Required | Used By |
|---|---|---|---|---|---|---|
| Role | `dbvc_bricks_role` | select | `mothership`, `client` | `client` | yes | endpoint behavior + UI gating |
| Mothership Base URL | `dbvc_bricks_mothership_url` | url | `esc_url_raw`, https required in production | empty | if role=client | package/proposal HTTP client |
| Auth Method | `dbvc_bricks_auth_method` | select | `hmac`, `api_key`, `wp_app_password` | `hmac` | yes | HTTP auth |
| API Key ID | `dbvc_bricks_api_key_id` | text | `[A-Za-z0-9._-]{3,128}` | empty | conditional | auth headers |
| API Secret | `dbvc_bricks_api_secret` | password | non-empty; masked on read | empty | conditional | request signing |
| Request Timeout (sec) | `dbvc_bricks_http_timeout` | integer | min 5, max 120 | `30` | yes | remote package/proposal calls |
| Strict TLS Verify | `dbvc_bricks_tls_verify` | checkbox | `0/1` | `1` | no | HTTP client |
| Read-only Mode | `dbvc_bricks_read_only` | checkbox | `0/1` | `0` | no | disables apply/publish actions |
| Connection Test (action) | n/a | action | nonce + `manage_options` | n/a | n/a | diagnostics only |

Input instructions (render as help text beneath input):
- `Role`:
  - `client`: receives packages/applies changes from mothership.
  - `mothership`: publishes/reviews packages and proposals.
- `Mothership Base URL`:
  - Enter the full admin origin for the mothership WordPress site, for example:
    - production/staging: `https://mothership.example.com`
    - LocalWP: `https://dbvc-mothership.local`
  - Do not include trailing slash or REST path.
  - Required only when `Role=client`. Leave empty on mothership sites.

## 3.2 Golden Source tab

| Field Label | Option Key | Type | Allowed Values / Validation | Default | Required | Used By |
|---|---|---|---|---|---|---|
| Source Mode | `dbvc_bricks_source_mode` | select | `mothership_api`, `pinned_version`, `local_package` | `mothership_api` | yes | package resolver |
| Channel | `dbvc_bricks_channel` | select | `stable`, `beta`, `canary` | `stable` | yes | list/fetch package filters |
| Pinned Package Version | `dbvc_bricks_pinned_version` | text | semver-ish or package id | empty | if pinned | package resolver |
| Verify Package Signature | `dbvc_bricks_verify_signature` | checkbox | `0/1` | `1` | no | pre-apply integrity gate |
| Allow Fallback to Last Applied | `dbvc_bricks_allow_fallback` | checkbox | `0/1` | `1` | no | resilience path |
| Keep Package History (count) | `dbvc_bricks_retention_count` | integer | min 1, max 200 | `25` | yes | cleanup job |
| Package Fetch Batch Size | `dbvc_bricks_fetch_batch` | integer | min 10, max 500 | `100` | yes | package client pagination |

## 3.3 Policies tab

| Field Label | Option Key | Type | Allowed Values / Validation | Default | Required | Used By |
|---|---|---|---|---|---|---|
| Default Policy: Entity artifacts | `dbvc_bricks_policy_entity_default` | select | `AUTO_ACCEPT`, `REQUIRE_MANUAL_ACCEPT`, `ALWAYS_OVERRIDE`, `REQUEST_REVIEW`, `IGNORE` | `REQUIRE_MANUAL_ACCEPT` | yes | apply planner |
| Default Policy: Option artifacts | `dbvc_bricks_policy_option_default` | select | same enum | `REQUEST_REVIEW` | yes | apply planner |
| New Entity Requires Explicit Accept | `dbvc_bricks_policy_new_entity_gate` | checkbox | `0/1` | `1` | no | governance gate |
| Block Destructive Deletes | `dbvc_bricks_policy_block_delete` | checkbox | `0/1` | `1` | no | apply gate |
| Max Diff Payload KB | `dbvc_bricks_policy_max_diff_kb` | integer | min 50, max 5000 | `1024` | yes | diff generation guardrail |
| Per-artifact overrides | `dbvc_bricks_policy_overrides` | JSON map | validated map `artifact_uid => policy` | `{}` | no | policy resolver |

## 3.4 Operations tab

| Field Label | Option Key | Type | Allowed Values / Validation | Default | Required | Used By |
|---|---|---|---|---|---|---|
| Drift Scan Mode | `dbvc_bricks_scan_mode` | select | `manual`, `scheduled` | `manual` | yes | scheduler/ops |
| Drift Scan Interval | `dbvc_bricks_scan_interval_minutes` | integer | min 5, max 1440 | `60` | if scheduled | cron |
| Auto-create Restore Point Before Apply | `dbvc_bricks_restore_before_apply` | checkbox | `0/1` | `1` | no | apply orchestrator |
| Restore Point Retention | `dbvc_bricks_restore_retention` | integer | min 1, max 100 | `20` | yes | cleanup |
| Apply Batch Size (artifacts) | `dbvc_bricks_apply_batch_size` | integer | min 1, max 200 | `25` | yes | apply runtime |
| Dry-run on Apply by Default | `dbvc_bricks_apply_dry_run_default` | checkbox | `0/1` | `1` | no | safety |
| Operation Buttons | n/a | action | nonce + `manage_options` | n/a | n/a | scan/apply/rollback |

## 3.5 Proposals tab

| Field Label | Option Key | Type | Allowed Values / Validation | Default | Required | Used By |
|---|---|---|---|---|---|---|
| Proposals Enabled | `dbvc_bricks_proposals_enabled` | checkbox | `0/1` | `1` | no | feature gate |
| Auto-submit on Divergence | `dbvc_bricks_proposals_auto_submit` | checkbox | `0/1` | `0` | no | client-side queue |
| Proposal Require Note | `dbvc_bricks_proposals_require_note` | checkbox | `0/1` | `1` | no | submission validator |
| Proposal Queue Limit | `dbvc_bricks_proposals_queue_limit` | integer | min 10, max 5000 | `500` | yes | storage guardrail |
| Default Reviewer Group (optional) | `dbvc_bricks_proposals_reviewer_group` | text | sanitized slug/list | empty | no | review routing |
| Mothership SLA (hours) | `dbvc_bricks_proposals_sla_hours` | integer | min 1, max 720 | `72` | no | dashboard badges |

## 3.6 Connected sites (mothership-only operations panel)

| Field Label | Option Key | Type | Allowed Values / Validation | Default | Required | Used By |
|---|---|---|---|---|---|---|
| Connected Sites Registry Source | `dbvc_bricks_connected_sites_source` | select | `manual`, `heartbeat_auto` | `manual` | yes | site registry population mode |
| Connected Sites Table Filter | n/a | UI control | `all`, `online`, `offline`, `disabled` | `all` | n/a | mothership targeting table |
| Select All Connected Sites | n/a | action | table bulk action | n/a | n/a | publish target assignment |
| Selected Site Rows | n/a | action | row checkbox list by `site_uid` | n/a | if target mode=selected_sites | package targeting |

Connected sites table expected columns:
- `site_uid`
- `site_label`
- `base_url`
- `status` (`online|offline|disabled`)
- `last_seen_at`
- `auth_mode`
- `allow_receive_packages` (derived from selection or per-site lock state)

## 4) Canonicalization/Fingerprint Rules Matrix

| Artifact Type | Canonicalization Rule | Hash Input | Hash Output |
|---|---|---|---|
| `bricks_template` Entity | normalize + recursively sort keys + remove volatile timestamps/revision/meta-noise | canonical payload | `sha256:<hex>` |
| Option artifacts | normalize + recursively sort keys + stable item ordering by `id` then `name` when array of objects | canonical payload | `sha256:<hex>` |
| Code/script options | normalize line endings to `\n`, trim trailing whitespace | canonical text payload | `sha256:<hex>` |

Volatile/noise candidates to remove (validate in live Bricks env):
- Entity fields: `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`, lock/editor markers.
- Option payload fields: ephemeral UI timestamps, random generated IDs not required for runtime behavior.

## 5) Required REST Surface (Bricks Add-on in DBVC)

Namespace: `dbvc/v1/bricks`

- `GET /packages`
- `GET /packages/{package_id}`
- `POST /proposals`
- `GET /proposals`
- `PATCH /proposals/{proposal_id}`
- `POST /drift-scan`
- `POST /apply`
- `POST /restore-points`
- `POST /restore-points/{restore_id}/rollback`

All endpoints:
- `permission_callback` aligned to DBVC `manage_options`.
- nonce/auth enforcement consistent with `DBVC_Admin_App`.
- idempotency key support for mutating calls (`apply`, `proposal submit`).

## 6) Missing Items / Tasks / Sub-tasks (must add before coding)

### 6.1 Data contract hardening
- Confirm live Bricks schema for:
  - `bricks_theme_styles` structure (array vs map).
  - component item title path (`elements.0.label` fallback behavior).
- Freeze canonicalization rules per artifact and publish fixtures.
- Define strict JSON schema per artifact for validation failures.

### 6.2 Safety rails
- Add preflight checks:
  - plugin/theme presence (Bricks installed/active where required),
  - required option keys exist or safely default.
- Add apply transaction envelope:
  - restore point creation,
  - partial-failure rollback strategy,
  - final verification pass against expected hashes.
- Add destructive-operation guard:
  - deny delete/replace unless policy allows and reviewer approved.

### 6.3 Governance pipeline details
- Define proposal status machine:
  - `DRAFT -> SUBMITTED -> RECEIVED -> APPROVED|REJECTED|NEEDS_CHANGES`.
- Enforce actor attribution and audit events on every transition.
- Add queue de-duplication rule by `(artifact_uid, base_hash, proposed_hash)`.

### 6.4 Performance controls
- Enforce payload size limits per artifact and per package.
- Add chunked processing for large option payloads.
- Add diff truncation strategy with explicit "download raw" fallback.

### 6.5 Test and QA coverage
- Unit tests:
  - canonicalization determinism,
  - hash stability,
  - policy evaluator,
  - status transitions.
- Integration tests:
  - end-to-end package apply with rollback,
  - proposal submit/review/approve flow.
- Manual QA checklist:
  - satellite -> mothership -> approve -> satellite apply.

## 7) Implementation Order (on-rails sequence)

1. Build settings fields + sanitization + persistence.
2. Build artifact registry + canonicalizer + fingerprint utility.
3. Build drift scan read-only flow (no apply writes).
4. Build restore point + apply planner with dry-run.
5. Build proposal endpoints and queue.
6. Wire UI actions to endpoints.
7. Add full test matrix + regression fixtures.
