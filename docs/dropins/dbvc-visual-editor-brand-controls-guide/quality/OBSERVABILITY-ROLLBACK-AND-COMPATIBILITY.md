# Observability, Rollback, and Compatibility

## Principles

- Each release is independently feature-isolated and rollbackable.
- Operational visibility must distinguish scan, discovery, descriptor, mutation, and UI failures.
- Feature rollback is not the same as content revert.
- Existing Visual Editor paths remain available until replacements prove stable.

## Feature isolation

Use the current plugin’s feature/settings conventions. Prefer separate control for:

- R1 Media Manager scan/report;
- R2 Media Manager remediation;
- R3 registry-backed Brand Control Center;
- R4 expanded center UI;
- R5 field-family enablements, where current settings support safe point-release gating;
- R6 Site Manager Workspace.

R2 should be disableable while leaving R1 read-only reporting available.

## Media Manager observability

Record through current logging/metrics conventions:

### Scan lifecycle

- scan started/completed/canceled/expired/failed;
- scanner/provider version;
- candidate count;
- entities processed;
- entities/findings produced;
- counts by field family;
- skipped/unsupported count;
- chunk count and duration;
- peak/representative memory or query counts where current tooling supports it;
- snapshot size/expiry issues.

### Row hydration

- group hydration success/failure;
- findings resolved since scan;
- permission/status/field-definition changes;
- descriptor hydration failures by family;
- latency and payload size for representative rows.

### Mutation

- selection/editor open failures;
- save success/failure by family;
- stale/conflict blocks;
- targeted revalidation result;
- cache invalidation issues;
- row-level partial failures;
- journal/audit integration failure.

Do not log attachment URLs, private content, nonces, or full opaque references.

## Brand/Workspace observability

Continue the prior package requirements:

- invalid/duplicate provider registration;
- unavailable control sources;
- descriptor hydration failures;
- unsupported option-field families;
- list/query latency and payload size;
- workspace navigation errors;
- mode-preservation failures;
- CSS/layering/browser errors where current client logging exists.

## Compatibility requirements

### Existing Visual Editor

- Current markers/badges remain functional.
- Review Fields remains functional.
- Go To Object remains functional until R6 replacement is verified.
- Shared Globals remains functional through R3–R5 rollout.
- Main editor panel remains authoritative.
- Existing image/gallery/featured-image editing has no regression.
- Existing journal/audit/cache behavior remains intact.

### WordPress and ACF

- Use supported public APIs and current plugin compatibility assumptions.
- Honor current post type/taxonomy capabilities.
- Handle ACF unavailable/deactivated state gracefully.
- Do not rely on one ACF return format for canonical storage.
- Media Library modal behavior must work under current WordPress version matrix.

### Bricks

- No assets/markers in builder/editor/iframe requests.
- No mutation of Bricks template JSON or static element settings.
- Current DOM patch/reload behavior remains field-family specific.

### VerticalFramework

- DBVC works without it.
- Provider/scanner/catalog integration fails closed.
- Cross-repository changes are separate and documented.
- Local filesystem paths never become runtime dependencies.

## Release rollback

### R1 Media Manager scan/report

- Disable the feature entry and scan requests.
- Expire/clear short-lived snapshots through a documented safe path.
- No content rollback is required.

### R2 Media Manager remediation

- Disable remediation while optionally retaining R1 reporting.
- Existing assignments remain valid content.
- Users retain current Visual Editor/backend editing paths.
- Content reversion, if needed, uses existing journal/recovery capabilities and is not automatic feature rollback.

### R3 Registry

- Disable registry-backed center.
- Preserve Shared Globals settings and prior interface.
- Remove optional providers without altering content.

### R4 Expanded center

- Disable expanded UI and fall back to minimal R3/Shared Globals surfaces.
- No content migration should be necessary.

### R5 Option families

- Disable the affected registration/family surface while leaving stored option values intact.
- Retain backend ACF editing.
- Revert code per point release.

### R6 Workspace

- Disable drawer/workspace.
- Restore toolbar/popover/Go To Object paths.
- Preserve mode cookie and content.

## Content recovery caveat

A journal entry does not automatically guarantee safe reversible undo. R1 performs no content mutation. R2 and later releases must not advertise true change-set undo unless a separate compare-and-swap rollback contract exists.

## Release notes requirements

Each release note should include:

- feature flag/enablement;
- user-visible behavior;
- scope and exclusions;
- permissions;
- data/storage changes;
- cache/session behavior;
- performance expectations;
- known limitations;
- test evidence;
- rollback instructions;
- follow-up work explicitly deferred.
