# Deferred Epic: Import and Staging Layer

Status: Deferred (not in current delivery scope)

Owner: ContentCollector core team

## Purpose

Define the full plan for a future import/staging epic without expanding current scope.

Current scope remains:
- Crawl a client site.
- Collect and store content in deterministic structured artifacts.
- Visualize artifacts in Explorer.
- Export data in supported formats (`json`, `yaml`, `md`) as zip bundles.

## Why Deferred

The plugin already delivers the migration preparation workflow. Importing into live WP content types adds risk (content overwrite, taxonomy conflicts, media remapping bugs, redirect regressions) and should ship as a separate controlled epic.

## Epic Goals (Future)

1. Add a staging model for review and approval before database writes.
2. Add deterministic import execution with idempotent reruns.
3. Add per-page status tracking for staging and import lifecycle.
4. Preserve provenance, AI decisions, and collision policy outcomes end-to-end.

## Non-Goals for This Epic

- Multisite support.
- WP-CLI tools.
- Automated data migration of old plugin versions.

## Functional Breakdown

### A) Staging Data Layer

Add a staging artifact per page that combines:
- Crawl payload (raw + sanitized preview reference).
- AI analysis and sanitization status.
- Import mapping decisions (target CPT, taxonomy mapping, slug plan).
- Compliance flags (PII/legal review).
- Redirect plan (`source_url -> target_permalink`).

Proposed file:
- `uploads/contentcollector/{domain}/{path}/{slug}.staging.json`

Staging schema (high level):
- `schema_version`
- `source` (`source_url`, canonical, hash, timestamp)
- `ai` (`status`, `mode`, `post_type`, `categories`, `needs_review`)
- `import_plan` (`target_post_type`, `target_slug`, taxonomy map, media map)
- `conflicts` (slug/taxonomy collisions + chosen resolution)
- `approval` (`state`, reviewer, reviewed_at, notes)
- `execution` (`import_status`, attempts, last_error, imported_post_id`)

### B) Staging Workflow

States:
- `new`
- `needs_review`
- `approved`
- `blocked`
- `ready_to_import`
- `imported`
- `failed`

Actions:
- Approve page.
- Edit mapping overrides.
- Resolve conflict.
- Mark blocked.
- Retry AI.
- Retry import.

### C) Import Execution Engine

Responsibilities:
- Create or update posts deterministically.
- Apply selected collision policies.
- Attach media and rewrite local references.
- Write mapping from source URL to imported permalink.
- Record per-page result and failure reason.

Idempotency rules:
- Use canonical source URL + hash + domain key as stable identity.
- Re-import updates existing staging-linked post when configured.
- Never duplicate if a deterministic key already exists unless explicitly overridden.

### D) Admin UI Additions (Future)

New pages:
- `Content Collector > Staging Queue`
- `Content Collector > Import Runs`

Capabilities:
- Filter by state (`needs_review`, `blocked`, `ready_to_import`, `failed`).
- Compare raw vs sanitized preview.
- Approve/override AI mapping.
- Bulk import approved pages.
- See run summary and failed pages with retry buttons.

### E) REST Endpoints (Future)

Potential namespace:
- `/wp-json/content-collector/v1/staging/*`
- `/wp-json/content-collector/v1/import/*`

Core endpoints:
- `GET /staging/queue`
- `GET /staging/item`
- `POST /staging/item/approve`
- `POST /staging/item/override`
- `POST /staging/item/block`
- `POST /import/run`
- `GET /import/run/{id}`
- `POST /import/retry`

### F) Observability and Audit

Extend current event logs with:
- Staging transitions.
- Import decision trace (policies used).
- Post IDs and permalinks created/updated.
- Failure code taxonomy (validation, media, taxonomy, permissions, DB write).

### G) Security

- Require `manage_options` for all staging/import actions.
- Nonce validation on admin actions.
- Strict path/domain sanitization (already used in current services).
- Redaction rules must be applied before any export/import path that can expose sensitive text.

## File/Class Plan (Future)

Proposed classes:
- `includes/class-cc-staging-service.php`
- `includes/class-cc-rest-staging.php`
- `includes/class-cc-import-service.php`
- `includes/class-cc-rest-import.php`
- `admin/views/staging-page.php`
- `admin/js/cc-staging.js`
- `admin/css/cc-staging.css`

## Acceptance Criteria (Future Epic Exit)

1. Staging queue exists and supports per-page approval workflow.
2. Import run can process approved items end-to-end.
3. Rerun is idempotent and does not duplicate content by default.
4. Failures are visible with actionable reason and retry path.
5. Redirect map includes imported target permalinks.
6. Existing crawl/explorer/export features remain unchanged.

## Rollout Strategy

1. Feature flag: `import_staging_enabled` default `false`.
2. Internal testing on fixture crawls first.
3. Beta on internal client migrations.
4. Production enablement after failure-rate and rollback criteria pass.

## Dependencies Before Starting This Epic

1. Stabilize current crawl + explorer + export workflow (in progress).
2. Expand regression fixtures for AI output variants.
3. Confirm final collision policy defaults with stakeholders.
4. Confirm import behavior for draft vs publish status.

