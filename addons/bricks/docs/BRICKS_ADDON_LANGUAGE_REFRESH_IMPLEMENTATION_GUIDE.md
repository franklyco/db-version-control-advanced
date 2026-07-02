# Bricks Add-on Language Refresh Implementation Guide

Date: 2026-06-25
Status: Implemented; targeted verification passed.

Related docs:
- `addons/bricks/docs/BRICKS_ADDON_USER_FACING_LANGUAGE_AUDIT.md`
- `addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`
- `addons/bricks/docs/BRICKS_PORTABILITY_MANAGER_IMPLEMENTATION_NOTES.md`
- `docs/roadmap.md`

## 1) Purpose

Implement the user-facing Bricks language refresh safely, using the reviewed language audit as the copy source of truth, without changing Bricks add-on behavior, storage, REST contracts, package contracts, or other DBVC addon integrations.

This guide lives beside the audit doc because the change is cross-cutting UI copy, not a new Bricks runtime phase. The active Bricks implementation checklist should keep tracking feature work such as portability, media-backed domains, transport, and evidence gates.

## 2) Source Of Truth

Use the `Use this version` column and answered review decisions from `BRICKS_ADDON_USER_FACING_LANGUAGE_AUDIT.md`.

The most important reviewed decisions are:
- Keep the page title `DBVC Bricks Add-on`.
- Use `Main Site (Mothership)` for page/panel titles and first mentions.
- Keep `Mothership` in descendant labels/buttons where the user needs continuity with the existing workflow.
- Use `Client Site`, not `Receiving Site`.
- Use `Update Package` for Bricks sync packages.
- Use `Bricks Settings Transfer` for the standalone portability page.
- Use `Protected Local Changes` for the feature area and `Protected Variant Items` for individual protected records.
- Use `Test (Canary)`, `Pilot (Beta)`, and `Live (Stable)` on first listing in a page, section, or panel.

## 3) Non-Negotiable Safety Boundaries

Only change display text unless a separate implementation task explicitly approves more.

Do not rename:
- Option keys such as `dbvc_bricks_role`, `dbvc_bricks_channel`, or `dbvc_bricks_command_transport_mode`.
- Stored enum values such as `mothership`, `client`, `canary`, `beta`, `stable`, `AUTO_ACCEPT`, `PENDING_INTRO`, or `client_pull_envelope`.
- REST routes under `dbvc/v1/bricks` or `dbvc/v1/bricks/portability`.
- JSON keys, response codes, error `code` values, manifest keys, checksum keys, package IDs, or receipt IDs.
- DOM IDs, CSS classes, `data-*` attributes, tab keys, query parameters, menu slugs, nonce names, action names, or localStorage keys.
- Internal hook names, filters, actions, PHP class names, file names, test fixture keys, or DBVC activity event names.

Technical text should remain visible in:
- Raw JSON/details panes.
- Advanced diagnostic output.
- Exported package manifests.
- Error `code` values.
- Developer docs, archived implementation history, and test fixtures.

Where a raw value is needed for support, pair it with a plain label instead of replacing it. Example: `Live (Stable)` can display beside the raw value `stable`.

## 4) Implementation Strategy

Prefer small, reversible slices. Each slice should be behavior-neutral and testable by rendered output plus existing contract tests.

### 4.1 Display Label Helpers First

Add a narrow display-label layer before editing large UI blocks.

Recommended helper locations:
- Bricks sync UI: add small static helper methods in `DBVC_Bricks_Addon` first, because the current code already centralizes settings, tabs, field metadata, and inline admin JS in `addons/bricks/bricks-addon.php`.
- Portability UI: update existing local maps in `addons/bricks/portability/assets/bricks-portability.js` and PHP labels in the portability classes. Avoid adding new autoload/bootstrap files unless duplication becomes meaningful.

Suggested helper responsibilities:
- `role_display_label($raw_role)`: `mothership` -> `Main Site (Mothership)`, `client` -> `Client Site`.
- `channel_display_label($raw_channel)`: `canary` -> `Test (Canary)`, `beta` -> `Pilot (Beta)`, `stable` -> `Live (Stable)`.
- `policy_display_label($raw_policy)`: keep raw saved values, display friendlier labels.
- `status_display_label($raw_status)`: map Bricks UI statuses such as `CLEAN`, `DIVERGED`, `PENDING_REVIEW` for display only.
- `artifact_type_display_label($raw_type)`: `bricks_template` -> `Template Entity`, option-backed records -> `Bricks Setting`.

Guardrail: helper input/output must never feed persistence, REST request bodies, signatures, checksums, filters, or comparison logic.

### 4.2 Keep Array Keys Raw

For settings metadata and select options, preserve existing array keys and only change option labels.

Example shape:

```php
'options' => [
    'stable' => self::channel_display_label('stable'),
    'beta' => self::channel_display_label('beta'),
    'canary' => self::channel_display_label('canary'),
]
```

Do not invert this shape. The submitted value must remain `stable`, `beta`, or `canary`.

### 4.3 Update Copy By Surface, Not By Find-And-Replace

Do not do a broad replacement of words like `artifact`, `mothership`, `drift`, `domain`, or `stable`.

Use the audit table source locations and update one surface at a time:
1. Bricks admin static PHP copy.
2. Bricks configure groups, fields, help text, and select display labels.
3. Bricks inline admin JS messages and dynamic table/detail labels.
4. Portability PHP page labels and localized messages.
5. Portability JS label maps and dynamic notices.
6. Portability registry display labels.
7. REST error `message` fields only where the UI surfaces them directly.

After each surface, run a targeted render/test check before moving on.

## 5) File-Level Plan

| File | Planned work | Safety notes |
|---|---|---|
| `addons/bricks/bricks-addon.php` | Update settings group labels, field labels/help, tab labels, panel headings, button text, notices, inline JS messages, and detail labels. | Keep option keys, tab keys, DOM IDs, endpoint config, nonce names, query values, and raw enum values unchanged. |
| `addons/bricks/portability/class-dbvc-bricks-portability.php` | Rename visible page/subtab/workbench language to `Bricks Settings Transfer`, `Transfer Package`, `Review Changes`, `Selected Action`, and backup/restore wording. | Keep `PAGE_SLUG`, REST namespace, asset handles, HTML IDs, and `data-portability-*` attributes unchanged. |
| `addons/bricks/portability/assets/bricks-portability.js` | Update `STATUS_META`, `MATCH_META`, `ACTION_META`, dependency labels, notices, empty states, confirmation prompts, and modal headings. | Keep status keys, action keys, session payload keys, API paths, row IDs, and decision values unchanged. |
| `addons/bricks/portability/class-dbvc-bricks-portability-registry.php` | Update registry `label` values that are shown in the transfer UI. | Keep domain keys, option names, file slugs, match order, and portability flags unchanged. |
| `tests/phpunit/BricksAddonPhase8Test.php` | Update render assertions for main admin labels, disabled notice, tab labels, and read-only wording. | Retain assertions that IDs and role-gated panels render correctly. |
| `tests/phpunit/BricksAddonPhase9Test.php` | Update display-label helper expectations if `get_artifact_class_label()` changes, or add coverage for a new display helper while leaving the old helper stable. | Keep behavior tests around persisted artifact type values unchanged. |
| `tests/phpunit/BricksAddonPhase10Test.php` | Update apply/change request/package UI string assertions if touched. | Preserve control ID and disabled-state assertions. |
| `tests/phpunit/BricksAddonPhase13Test.php` | Update connected-sites/package table header assertions such as package/channel labels. | Preserve endpoint, targeting, and receipt assertions. |
| `tests/phpunit/BricksAddonPhase14Test.php` | Update release-stage definition assertion from raw canary/beta/stable text to reviewed labels. | Preserve promotion/progression raw value assertions. |
| `tests/phpunit/BricksAddonPhase19BTest.php` | Update Protected Artifacts display strings to Protected Local Changes / Protected Variant Items. | Preserve protected-variant CRUD, read-only, and payload annotation assertions. |
| `tests/phpunit/BricksAddonPhase19CTest.php` | Update mothership protected-visibility labels if asserted. | Preserve fleet aggregation and deep-link behavior assertions. |
| `tests/phpunit/BricksPortabilityManagerTest.php` | Update page title, workbench, status, action, and table label assertions. | Preserve REST, package/session/apply/rollback behavior assertions. |

## 6) Recommended Slices

### Slice 0: Baseline And Inventory

Before implementation:
1. Confirm the audit doc is final enough to implement.
2. Run a targeted string inventory:
   - `rg -n "Mothership|Client|Artifact|Entity|Drift|Golden|Package|Channel|canary|beta|stable|Protected Artifacts|Portability|Domain|Object|Approved Action" addons/bricks tests/phpunit/Bricks*`
3. Run baseline tests for the surfaces most likely to change.
4. Save any failing baseline separately so language work is not blamed for pre-existing failures.

Exit criteria:
- Known baseline is recorded.
- No implementation begins while unrelated Bricks tests are unexpectedly failing without explanation.

### Slice 1: Bricks Sync Display Helpers

Add helper coverage first.

Target changes:
- Add display helper methods in `DBVC_Bricks_Addon`.
- Add/update tests for raw-to-display mappings.
- Do not yet change large rendered pages.

Exit criteria:
- Helper tests pass.
- No persisted values or REST payloads change.

### Slice 2: Configure Settings Language

Target changes:
- `get_settings_groups()`
- `get_field_meta()`
- `get_field_help_texts()`
- select display labels for role, source mode, channel, force channel, registry state, connected-sites modes, command transport, policies, and scan mode.

Guardrails:
- Keep the same setting keys.
- Keep the same saved option values.
- Keep advanced JSON/rules labels technical enough to support admins.

Exit criteria:
- Configure UI renders.
- Saving settings still persists raw values.
- Configuration portability provider tests still pass, especially placeholder handling for Bricks settings.

### Slice 3: Main Bricks Admin Static UI

Target changes:
- Page intro and disabled/read-only/loading notices.
- First-time checklist.
- Admin tab visible labels.
- Panel headings and body copy.
- Button labels and table headers in Compare Changes, Protected Local Changes, Apply & Backups, Change Requests, Update Packages, Status, and Help.

Guardrails:
- Keep tab keys such as `protected_artifacts`, `apply_restore`, and `documentation`.
- Keep DOM IDs such as `dbvc-bricks-panel-protected_artifacts`.
- Keep role-gating behavior unchanged.

Exit criteria:
- Client and Mothership role pages render with expected panels.
- Existing tests still verify IDs and disabled controls.
- Any exact-string assertions are updated intentionally.

### Slice 4: Main Bricks Inline JS

Target changes:
- `window.DBVC_BRICKS_ADMIN.messages`.
- Inline JS dynamic detail headings, confirm prompts, empty states, notices, and table row labels.
- Display labels for counts and statuses, while raw `item.status` values remain untouched in data.

Guardrails:
- Do not alter fetch endpoints or request payload keys.
- Do not alter idempotency key generation.
- Do not alter state object keys.
- Do not alter the read-only mutation guard logic.

Exit criteria:
- JS syntax check passes.
- Buttons still bind by ID.
- Read-only mode still blocks mutating actions.
- Protected variant create/remove still sends `artifact_uid`, `artifact_type`, `scope`, and `reason`.

### Slice 5: Bricks Settings Transfer PHP

Target changes:
- Visible page title/menu labels from Portability to Bricks Settings Transfer.
- Export/import package wording to Transfer Package where this is not the sync Update Package workflow.
- Workbench, filters, table headers, backup/restore labels, confirmation text, and localized `messages`.

Guardrails:
- Keep class names, page slug, REST namespace, asset handles, HTML IDs, and data attributes unchanged.
- Keep package/session/export/backup IDs unchanged, even if they include `bricks-portability` prefixes.
- Keep storage directories and package manifest keys unchanged.

Exit criteria:
- Page renders when Bricks addon is enabled and disabled.
- Existing export/import/session/apply/rollback flows remain wired to the same REST endpoints.

### Slice 6: Bricks Settings Transfer JS And Registry Labels

Target changes:
- `STATUS_META`, `MATCH_META`, `ACTION_META`, `DOMAIN_STATUS_META`, `DIFF_BUCKET_META`, dependency labels, notices, confirmation prompts, and modal headings.
- Registry `label` values surfaced in the domain list and row labels.

Guardrails:
- Keep status keys such as `identical`, `new_in_source`, and `same_name_different_id`.
- Keep action keys such as `keep_current`, `add_incoming`, and `replace_with_incoming`.
- Keep domain keys such as `global_classes`, `custom_fonts`, and `bricks_templates`.
- Keep option names such as `bricks_global_classes` and `bricks_custom_icons`.

Exit criteria:
- JS syntax check passes.
- Review filtering, sorting, pagination, bulk actions, dependency actions, draft save, apply, and rollback still operate on raw keys.

### Slice 7: User-Facing REST Messages

Only after UI surfaces are stable, review REST `message` text that appears directly in admin notices.

Guardrails:
- Do not change `WP_Error` code strings.
- Do not change HTTP status codes.
- Do not change structured `data` fields like `classification`, `endpoint`, `reason`, or remediation identifiers.
- Do not change messages used only for developer diagnostics unless they are shown in admin notices.

Exit criteria:
- Contract tests still assert the same error codes and structured fields.
- UI notices read cleanly without losing operator guidance.

## 7) Test Plan

### Minimum Syntax Gate

Run after every implementation slice that touches the listed file type:

```bash
php -l addons/bricks/bricks-addon.php
php -l addons/bricks/portability/class-dbvc-bricks-portability.php
php -l addons/bricks/portability/class-dbvc-bricks-portability-registry.php
node --check addons/bricks/portability/assets/bricks-portability.js
```

### Targeted PHPUnit Gate

Run after the main Bricks admin slices:

```bash
vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php tests/phpunit/BricksAddonPhase9Test.php tests/phpunit/BricksAddonPhase10Test.php tests/phpunit/BricksAddonPhase13Test.php tests/phpunit/BricksAddonPhase14Test.php tests/phpunit/BricksAddonPhase19BTest.php tests/phpunit/BricksAddonPhase19CTest.php
```

Run after the Settings Transfer slices:

```bash
vendor/bin/phpunit tests/phpunit/BricksPortabilityManagerTest.php
```

Run as the broader regression gate before handoff:

```bash
vendor/bin/phpunit tests/phpunit/BricksAddonPhase2Test.php tests/phpunit/BricksAddonPhase7Test.php tests/phpunit/BricksAddonPhase8Test.php tests/phpunit/BricksAddonPhase9Test.php tests/phpunit/BricksAddonPhase10Test.php tests/phpunit/BricksAddonPhase13Test.php tests/phpunit/BricksAddonPhase14Test.php tests/phpunit/BricksAddonPhase19BTest.php tests/phpunit/BricksAddonPhase19CTest.php tests/phpunit/BricksAddonPhase19DTest.php tests/phpunit/BricksAddonPackagesTest.php tests/phpunit/BricksPortabilityManagerTest.php tests/phpunit/ConfigurationPortabilityRegistryTest.php
```

### Manual Admin QA Gate

Validate these views in a browser or captured render after automated tests pass:
- Bricks add-on disabled state.
- Bricks admin as `client`: Settings, Status, Compare Changes, Protected Local Changes, Update Packages, Apply & Backups, Change Requests, Help.
- Bricks admin as `mothership`: Settings, Status, Compare Changes, Update Packages, Help.
- Read-only mode: visible labels changed, mutating controls still disabled.
- Protected Local Changes: create/remove prompts and errors use new wording, but submitted payload remains unchanged.
- Update Packages: release stages display as `Test (Canary)`, `Pilot (Beta)`, and `Live (Stable)` while raw option values remain saved as `canary`, `beta`, and `stable`.
- Bricks Settings Transfer: export package, upload transfer package, review table, row modal, dependency labels, save draft, apply, backup, and restore labels.

## 8) Regression Risks And Mitigations

| Risk | Why it matters | Mitigation |
|---|---|---|
| Raw enum values accidentally replaced with display labels | Could break settings save, package filtering, promotion, transport, and comparisons. | Keep raw array keys and request payloads unchanged; add helper tests and save/load tests. |
| DOM IDs or tab keys renamed while changing visible labels | Could break JS bindings, CSS, tests, deep links, and role-gated panels. | Change inner text only. Add render assertions for IDs after label updates. |
| Portability package/session IDs renamed | Could break existing storage and rollback history. | Do not change generated ID prefixes or storage paths in this pass. |
| REST `code` strings softened | Could break clients, tests, diagnostics, and remediation handling. | Only consider human `message` changes; never change `code`. |
| JS hardcoded strings become inconsistent with PHP labels | Could produce mixed old/new language in dynamic rows. | Update PHP and JS slices separately, then run `rg` inventory and manual UI QA. |
| Tests are weakened by only changing exact strings | Could miss broken controls. | Preserve or add assertions for IDs, disabled attrs, role-gated panels, payload keys, and raw values. |
| Other DBVC addons expect Bricks setting keys through configuration portability | Could break cross-addon config export/import. | Keep `includes/Dbvc/ConfigurationPortability/Providers/BricksAddonProvider.php` behavior unchanged unless tests show display-only labels need adjustment. |

## 9) Search Audit After Implementation

After all slices, run a final search. Remaining old terms are allowed only in current-text audit docs, technical/raw details, tests intentionally asserting raw values, code identifiers, archived docs, and API contracts.

Suggested search:

```bash
rg -n "Mothership|Receiving Site|Artifact|Entity|Drift|Golden|Package|Channel|canary|beta|stable|Protected Artifacts|Portability|Domain|Object|Approved Action|Read-only|Dry-run|Rollback" addons/bricks tests/phpunit/Bricks*
```

For each result, classify it as:
- `display-copy updated`
- `raw/contract intentionally unchanged`
- `test updated`
- `follow-up needed`

## 10) Rollback Plan

This work should not include migrations or data changes. Rollback should be a normal code revert of copy/helper/test changes.

Before merging:
- Keep language-refresh changes isolated from unrelated Bricks feature work.
- Avoid mixing runtime bug fixes into the same implementation pass.
- Record any pre-existing failing tests in the handoff.

If a regression appears:
1. Revert the latest language slice.
2. Confirm raw values and REST payloads are unchanged.
3. Re-run the targeted tests for that surface.
4. Reapply the copy more narrowly.

## 11) Definition Of Done

The language refresh is ready to ship when:
- All high-priority audit rows are implemented or explicitly deferred.
- User-facing primary UI uses the reviewed terms.
- Raw values remain visible only in advanced/details/support contexts.
- All targeted tests pass or have documented pre-existing failures.
- Manual admin QA confirms client, mothership, read-only, protected-change, package, and Settings Transfer flows still work.
- No other DBVC addon behavior changes are required.
