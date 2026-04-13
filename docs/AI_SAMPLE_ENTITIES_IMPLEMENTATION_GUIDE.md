# AI Sample Entities + AI Package Intake Implementation Guide

Last updated: 2026-04-09  
Current phase: `P9`  
Status legend: `OPEN` | `WIP` | `CLOSED` | `DEFERRED`

## Current Intentionally Unfinished Items

- [ ] Full ACF underscore reference meta synthesis is still pending beyond the currently supported simple, nested, and first-pass deferred relationship families.
- [ ] Deferred media-oriented ACF field families such as `image`, `file`, and `gallery` are policy-locked to `block` when non-empty, but they still need clearer operator-facing UX and end-user docs.
- [ ] Deeper relationship-like ACF translation remains pending beyond the current first pass for `post_object`, `relationship`, and `taxonomy`.
- [ ] Advanced clone edge cases still need broader validation beyond the current first-pass storage-aware clone expansion path.
- [ ] AI intake reporting still needs a richer cross-linked import-history view beyond the current server-rendered drill-down panels and retained artifact downloads.
- [ ] Full browser-driven end-to-end AI upload/import QA with a real submission ZIP is still pending.

## Objective

Add a new core DBVC workflow that:

1. lets operators generate a site-specific Sample Entities Package for use in browser-based AI sessions
2. lets DBVC detect and validate a stricter AI Submission Package on upload
3. translates accepted AI package entities into DBVC-native sync-compatible JSON
4. reuses the existing DBVC import helpers and upload/import architecture rather than introducing a second import engine

The finished workflow should feel like:

- `DBVC Export > Tools > Download Sample Entities`
- user uploads that package into ChatGPT, Claude, or another AI session
- AI returns a stricter DBVC AI package ZIP
- user uploads the ZIP into DBVC
- DBVC shows an AI-specific validation and preflight review surface
- DBVC imports only when the package is valid or explicitly confirmed with warnings

## Foundation Reference

This guide implements the decisions locked in:

- [AI_PACKAGE_FOUNDATION_SPEC.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_PACKAGE_FOUNDATION_SPEC.md)

That foundation spec remains the source of truth for:

- package boundaries
- manifest strategy
- canonical entity JSON contract
- create/update identity policy
- slug-based reference rules
- ACF discovery strategy
- validation states
- staging, cleanup, and version rules

## Must-Preserve Constraints

- Do not change the behavior of the current generic legacy upload/import flow when an uploaded archive is not an AI package.
- Do not replace proposal bundles or proposal review flows.
- Do not call external AI services during AI package validation or import.
- Keep AI submission handling deterministic.
- Treat canonical DBVC-shaped JSON as the import target contract, with AI-oriented documentation wrapped around it.
- Keep this feature core to DBVC, not addon-gated.
- Keep add-on configuration server-rendered unless a stronger reason appears later.
- Prefer extracting reusable services over embedding logic in `admin/admin-page.php`.

## Intended Product Surfaces

### 1. Tools submenu

Add a new submenu under the DBVC top-level menu:

- `Tools`

The first tool on this surface is:

- `Download Sample Entities`

### 2. Configure subtab

Extend the existing Configure surface with:

- `AI + Integrations`

This owns generation defaults, validation defaults, user-authored notes/rules, and future optional provider settings.

### 3. AI package intake handling

When DBVC detects `dbvc-ai-manifest.json` in an uploaded archive, it should route that upload into an AI-specific intake flow rather than the generic upload success/fail path.

### 4. AI preflight review surface

AI package uploads must surface a dedicated review UI that shows:

- package status
- counts and stats
- warnings and blocked issues
- dry-run/report links
- required confirmations
- import controls

This is an explicit workstream and should not be reduced to a passive admin banner alone.

## Recommended Module Shape

The implementation should stay modular and avoid turning `admin/admin-page.php` or a single service file into a monolith.

Recommended core PHP layout:

```text
admin/
  class-ai-tools-page.php
  class-ai-package-intake-page.php
includes/Dbvc/AiPackage/
  Settings.php
  Storage.php
  RulesService.php
  SiteFingerprintService.php
  SchemaDiscoveryService.php
  AcfDiscoveryService.php
  ObservedShapeService.php
  TemplateBuilder.php
  SampleDocBuilder.php
  SamplePackageBuilder.php
  SubmissionPackageDetector.php
  SubmissionPackageValidator.php
  SubmissionPackageTranslator.php
  SubmissionPackageImporter.php
  ValidationReportFormatter.php
```

Recommended UI layout:

```text
src/
  admin-ai-intake/
    index.js
    style.css
```

The UI bundle can remain small and purpose-built. A full React workspace is not required on day one if the first AI preflight surface is a focused results section/modal on the existing upload/import page.

## Current Code Anchors

Before implementation begins, keep the current DBVC entry points in mind.

### Admin bootstrap

- [db-version-control.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/db-version-control.php)
  - currently requires core includes and admin files directly
  - loads `admin/admin-menu.php` and `admin/admin-page.php` only inside `is_admin()`
  - is the correct place to require any new AI package service classes unless a narrower shared loader is introduced

### Menu registration

- [admin/admin-menu.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-menu.php)
  - currently registers the top-level `dbvc-export` page and the `Entity Editor` submenu only
  - is the correct place to add the new `Tools` submenu

### Main DBVC admin surface

- [admin/admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php)
  - currently renders the `Import/Upload`, `Export/Download`, `Configure`, `Backup/Archive`, `Logs`, and `Docs & Workflows` tabs
  - currently owns the large unified Configure save handler
  - already has an established subtab pattern that should be extended rather than replaced
  - is the most likely host for the new `AI + Integrations` Configure subtab and the first AI preflight review rendering path

### Current upload/import entry point

- [includes/class-sync-posts.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-posts.php)
  - `handle_upload_sync()` is the current upload entry point for ZIP and JSON uploads
  - important safety note: the method currently clears the sync folder before normal ZIP extraction in many cases
  - AI package detection must branch before any destructive sync-folder clearing occurs

### Existing upload routing helper

- [includes/class-import-router.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-import-router.php)
  - already normalizes uploaded JSON files and routes them into DBVC directories
  - remains the legacy path for non-AI JSON uploads

### Existing storage hardening and ZIP patterns

- [includes/class-options-groups.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-options-groups.php)
  - shows current option sanitization and directory hardening patterns
- [includes/Dbvc/Transfer/EntityPacketBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/Transfer/EntityPacketBuilder.php)
  - shows current workspace and ZIP assembly patterns that should be reused where practical

## Near-Term Delivery Strategy

The next coding pass should not attempt to land the whole feature boundary at once.

Recommended first implementation slice:

1. land P1 completely enough that the new menu, page shell, settings service, storage service, and `AI + Integrations` placeholder exist
2. land the non-destructive portion of P2 so schema discovery and ACF discovery can be exercised independently
3. only then begin sample generation and AI upload branching work

This keeps the first tranche low-risk and avoids mixing UI scaffolding, archive handling, and importer-adjacent mutation in the same pass.

## P5 and P6 Contract Reference

Before implementing AI intake and translation, treat the following retained artifacts as the baseline contract defined in [AI_PACKAGE_FOUNDATION_SPEC.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_PACKAGE_FOUNDATION_SPEC.md).

### Validation report artifacts

- `reports/validation-report.json`
- `reports/validation-summary.md`

The JSON report is the canonical persisted object for:

- AI preflight UI rendering
- report downloads
- logging correlation
- PHPUnit fixture coverage

### Translation artifacts

- `translated/translation-manifest.json`
- `translated/sync-root/{post_type}/{filename}.json`
- `translated/sync-root/taxonomy/{taxonomy}/{filename}.json`

The `translated/sync-root/` tree should mimic DBVC sync layout without requiring immediate writes into the live sync folder.

### Critical P5/P6 rule

Update-intent translated artifacts should carry resolved local IDs before importer handoff:

- posts/CPTs: translated `ID` should be the matched local post ID
- terms: translated `term_id` should be the matched local term ID

Create-intent translated artifacts should preserve neutral create markers:

- posts/CPTs: `ID: 0`
- terms: `term_id: 0`

## Concrete P1 Landing Plan

This section translates `P1` into the intended file ownership and step order for the first coding tranche.

### P1 File Ownership

- [db-version-control.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/db-version-control.php)
  - require any new AI package classes needed for admin rendering and shared storage/settings
- [admin/admin-menu.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-menu.php)
  - register the new `Tools` submenu under `dbvc-export`
- [admin/admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php)
  - extend Configure subtab registration and placeholder rendering for `AI + Integrations`
  - keep save handling routed through current Configure patterns until a dedicated controller is justified
- [admin/class-ai-tools-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-ai-tools-page.php)
  - new page renderer/controller for `Tools > Download Sample Entities`
- [includes/Dbvc/AiPackage/Settings.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/Settings.php)
  - new AI settings defaults, sanitization, and retrieval service
- [includes/Dbvc/AiPackage/Storage.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/Storage.php)
  - new uploads-root resolution, hardening, and retention scaffolding service

### P1 Recommended Step Order

1. Add the new AI package class requires to [db-version-control.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/db-version-control.php).
2. Create [includes/Dbvc/AiPackage/Settings.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/Settings.php) with:
   - option names
   - defaults
   - sanitize helpers
   - read/write helpers
   - initial versioned settings schema constant
3. Create [includes/Dbvc/AiPackage/Storage.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/Storage.php) with:
   - uploads base resolution
   - sample package root resolution
   - AI intake root resolution
   - directory hardening helpers
   - cleanup retention constants only, not destructive cleanup execution yet
4. Add the new `Tools` submenu in [admin/admin-menu.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-menu.php).
5. Create [admin/class-ai-tools-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-ai-tools-page.php) and keep the first render to:
   - capability check
   - heading/intro copy
   - configuration form skeleton
   - nonce field
   - placeholder submit button
   - no package build side effects yet
6. Extend [admin/admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php) with a new Configure subtab ID, suggested as `dbvc-config-ai`.
7. Wire the current Configure save handler in [admin/admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php) to delegate AI settings sanitization/persistence into `Settings.php` instead of embedding new save logic inline.
8. Add placeholder rendering for the `AI + Integrations` subtab in [admin/admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php).
9. If the Tools page later needs dedicated assets, extend `dbvc_enqueue_admin_assets()` in [db-version-control.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/db-version-control.php) carefully so new hooks are additive and do not alter existing DBVC screens.

### P1 Explicit Non-Goals

The first coding tranche should not yet:

- build sample packages
- parse ACF local JSON
- alter upload/import branching
- add any AI preflight review UI
- add external provider credentials or network behavior

### P1 Acceptance Focus

Treat P1 as successful when:

- the new `Tools` submenu exists and renders
- the `AI + Integrations` Configure subtab exists and saves sanitized placeholder/default values
- AI storage roots can be resolved and hardened safely
- no existing `Import/Upload`, `Export/Download`, or `Entity Editor` behavior changes

## Tracking Protocol

This guide now uses checkbox-based phase tracking.

Rules:

- Every independently landable task should be represented as a markdown checkbox.
- If a parent task contains multiple independently verifiable implementation steps, represent those child steps as nested markdown checkboxes rather than leaving them as plain prose.
- Only mark a checkbox complete when the work is implemented and validated to the level described in the task notes.
- Leave a task unchecked if code landed but validation is still incomplete.
- Add tranche/date notes under the relevant phase when substantial progress is made.
- A phase should be treated as complete only when every tracked task and every exit checkbox for that phase is complete.

Recommended maintenance pattern:

- keep the phase `Status` line current
- update the progress table at the end of each landed tranche
- append tranche notes only where they materially help resumption

## Workstreams

| Workstream | Goal |
|---|---|
| `W1` | Core storage, settings, and admin wiring |
| `W2` | Schema discovery, ACF discovery, and rule synthesis |
| `W3` | Sample entity template generation and package assembly |
| `W4` | AI submission package detection, validation, and translation |
| `W5` | Dedicated AI preflight review UI and import controls |
| `W6` | Testing, fixtures, docs, rollout, and cleanup |

## Progress Tracker

| Phase | Status | Goal |
|---|---|---|
| `P0` | `CLOSED` | Foundation, architecture boundary, and contract decisions |
| `P1` | `CLOSED` | Core storage, settings, admin menu, and page wiring |
| `P2` | `WIP` | Schema discovery, ACF discovery, and fingerprint generation |
| `P3` | `WIP` | Sample template generation and documentation assembly |
| `P4` | `WIP` | Sample package build pipeline and Tools UI |
| `P5` | `WIP` | AI package detection, extraction, validation, and reporting |
| `P6` | `WIP` | Translation and importer handoff |
| `P7` | `WIP` | Dedicated AI preflight review surface |
| `P8` | `WIP` | `AI + Integrations` settings and rule authoring UX |
| `P9` | `WIP` | Tests, fixtures, docs, QA, and rollout closure |
| `P10` | `DEFERRED` | Optional managed provider integrations and outbound AI workflows |

Update this table at the end of each landed tranche.

## P0. Foundation and Boundary Lock

Status: `CLOSED`

### Tracked Tasks

- [x] Lock sample package vs submission package boundary.
- [x] Lock canonical JSON direction around DBVC importer-compatible shapes.
- [x] Lock create-and-update package support.
- [x] Lock update precedence as `vf_object_uid`, then slug, then numeric ID.
- [x] Lock ACF discovery strategy around local `acf-json` first, runtime ACF second.
- [x] Lock dedicated AI preflight review surface as an explicit workstream.
- [x] Capture pre-implementation decisions in the foundation spec.

### Phase Exit

- [x] Foundation spec is usable as the planning baseline.
- [x] Deferred tranche-level decisions are called out explicitly instead of being hidden gaps.

## P1. Core Storage, Settings, and Admin Wiring

Status: `CLOSED`

### Outcome

Establish the feature's core entry points and persistent configuration before implementing schema discovery or package builders.

### Dependencies

- P0 complete

### Tracked Tasks

- [x] Register a new `Tools` submenu under the DBVC top-level menu.
  - [x] Keep the existing DBVC top-level menu slug and capability model.
  - [x] Do not disturb current `DBVC Export` or `Entity Editor` menu behavior.
  - [x] Keep menu registration ordering explicit so future submenu additions do not depend on side effects.

- [x] Add an initial server-rendered Tools page/controller for Sample Entities generation.
  - [x] Start server-rendered unless the page proves too complex.
  - [x] Keep first tranche focused on layout, form skeleton, and hook wiring rather than build logic.
  - [x] Gate the page with the same capability checks used by adjacent DBVC admin surfaces.
  - [x] Add nonce-backed form submission handling before any package-generation logic is introduced.

- [x] Add an `AI + Integrations` Configure subtab placeholder in the existing Configure area.
  - [x] This can ship as a placeholder section at first if settings forms are deferred to P8.
  - [x] Keep the subtab wiring stable enough that later settings fields can be added without route churn.

- [x] Define option storage for AI package settings.
  - [x] Define generation defaults storage.
  - [x] Define validation defaults storage.
  - [x] Define global user-authored notes/rules storage.
  - [x] Define per-post-type notes/rules storage.
  - [x] Define per-taxonomy notes/rules storage.
  - [x] Reserve namespaced storage for future provider settings.
  - [x] Sanitize and normalize values on save.
  - [x] Add a schema/version key so settings migrations can be handled explicitly later.

- [x] Define AI package storage roots.
  - [x] Define `uploads/dbvc/ai-sample-packages/`.
  - [x] Define `uploads/dbvc/ai-intake/`.
  - [x] Keep these separate from proposal and backup storage.
  - [x] Centralize root resolution so path validation is not reimplemented in multiple services.

- [x] Reuse existing directory hardening/security file patterns.
  - [x] Add `index.php` or the DBVC-equivalent directory guard.
  - [x] Add `.htaccess` or equivalent hardened directory behavior already used elsewhere in DBVC.
  - [x] Ensure hardening is applied to nested AI storage directories, not only the parent root.

- [x] Define default cleanup policy scaffolding for AI intake workspaces.
  - [x] Capture the 7-day retention default.
  - [x] Do not implement destructive cleanup logic inline with upload requests.
  - [x] Decide where scheduled cleanup hooks or manual cleanup entry points will live, even if execution lands later.

### Implementation Notes

- Prefer dedicated service classes for settings and storage.
- Keep path creation and path validation centralized.
- Avoid scattering AI-specific option names across unrelated files.

### Likely Touchpoints

- `admin/admin-menu.php`
- `admin/admin-page.php`
- `admin/class-ai-tools-page.php`
- `includes/Dbvc/AiPackage/Settings.php`
- `includes/Dbvc/AiPackage/Storage.php`

### Phase Exit

- [x] `Tools` submenu exists and renders safely.
- [x] `AI + Integrations` placeholder/subtab exists.
- [x] AI settings defaults are registered and sanitized.
- [x] AI storage roots are created and hardened.
- [x] No legacy menu or upload behavior regressed.

## P2. Schema Discovery, ACF Discovery, and Fingerprint Generation

Status: `WIP`

### Outcome

Build the normalized site schema context required for sample generation and submission validation.

### Dependencies

- P1 storage/settings/admin wiring available

### Tracked Tasks

- [x] Build a schema discovery service for site object structure.
  - [x] Capture supported post types and supports.
  - [x] Capture attached taxonomies.
  - [x] Capture taxonomy object relationships.
  - [x] Capture registered meta.
  - [x] Sort and normalize discovery output so later hashing is stable.

- [x] Build layered ACF discovery.
  - [x] Support local `acf-json` roots when available.
  - [x] Support runtime ACF APIs when available.
  - [x] Degrade gracefully when ACF is unavailable.
  - [x] Preserve source provenance per discovered field group or field family.

- [x] Implement local `acf-json` root discovery strategy.
  - [x] Include the known current Vertical pattern: `/wp-content/themes/vertical/acf-json`.
  - [x] Support future additional roots without hardcoding only one path.
  - [x] Skip non-field-group files safely.
  - [x] Handle unreadable or malformed JSON files without aborting the full discovery run.

- [x] Parse ACF location rules to map field groups to target object families.
  - [x] Map location rules to post types.
  - [x] Map location rules to taxonomies.
  - [ ] Map location rules to term objects where applicable.
  - [x] Retain unresolved location rules as metadata instead of silently dropping them.

- [x] Normalize ACF logical field metadata.
  - [x] Normalize field names.
  - [x] Normalize labels.
  - [x] Normalize types.
  - [x] Normalize choices.
  - [x] Normalize nested `sub_fields`.
  - [x] Normalize `layouts`.
  - [x] Normalize clone relationships.
  - [x] Normalize field ordering so repeated exports remain deterministic.

- [x] Build a deterministic site fingerprint service.
  - [x] Include post type signature.
  - [x] Include taxonomy signature.
  - [x] Include registered meta signature.
  - [x] Include ACF field catalog signature.
  - [x] Include user-authored rule signature.
  - [x] Include AI package schema version.
  - [x] Exclude volatile site-specific noise so the fingerprint remains schema-oriented.

- [ ] Emit reusable schema artifacts for package generation.
  - [x] Emit `object-inventory`.
  - [x] Emit `field-catalog`.
  - [x] Emit `validation-rules`.
  - [ ] Keep artifact schemas explicit so later validation code does not depend on inferred array shapes.

- [x] Record provenance in the schema layer.
  - [x] Preserve `registered` provenance.
  - [x] Preserve `acf` provenance.
  - [x] Preserve `observed` provenance.
  - [ ] When data came from multiple sources, preserve source attribution rather than collapsing it.

### Implementation Notes

- Treat local `acf-json` as a first-class source, not as an optional convenience.
- Keep discovery outputs deterministic and sorted.
- Favor normalized catalogs over passing raw ACF structures downstream.

### Likely Touchpoints

- `includes/Dbvc/AiPackage/SchemaDiscoveryService.php`
- `includes/Dbvc/AiPackage/AcfDiscoveryService.php`
- `includes/Dbvc/AiPackage/SiteFingerprintService.php`
- `includes/class-options-groups.php`
- `addons/content-migration/mapping-catalog/dbvc-cc-target-field-catalog-service.php`

### Risks

- ACF local JSON and runtime API data may not always match exactly.
- Some location rules may not be fully interpretable in tranche one. Preserve them as metadata and degrade safely.

### Phase Exit

- [x] Post type, taxonomy, and registered meta catalogs are generated.
- [x] Local `acf-json` discovery works on current Vertical-style sites.
- [x] Runtime ACF fallback works when local JSON is absent.
- [x] A stable site fingerprint can be produced deterministically.
- [x] Schema artifacts are usable inputs for later phases.

## P3. Sample Template Generation and Documentation Assembly

Status: `WIP`

### Outcome

Generate canonical AI-facing sample JSON templates and sibling markdown docs for selected post types and taxonomies.

### Dependencies

- P2 schema and ACF catalogs available

### Tracked Tasks

- [x] Build a canonical post/CPT template builder using DBVC importer-compatible JSON shape.
  - [x] Define required core fields.
  - [x] Define optional core fields.
  - [x] Define canonical `meta` placement.
  - [x] Define canonical `tax_input` placement.
  - [ ] Define stable field ordering for emitted post/CPT templates.

- [x] Build a canonical taxonomy term template builder.
  - [x] Define required term fields.
  - [x] Define optional term fields.
  - [x] Define canonical `meta` placement.
  - [ ] Define parent context guidance.
  - [ ] Define stable field ordering for emitted term templates.

- [x] Enforce create-template conventions for generated samples.
  - [x] Use `ID: 0` for post create templates.
  - [x] Use `term_id: 0` for term create templates.
  - [x] Omit top-level `vf_object_uid` for net-new samples.
  - [ ] Document these conventions in both top-level docs and sibling sample docs.

- [x] Implement `conservative` mode.
  - [x] Limit output to fields supported by registered schema, ACF schema, and locked DBVC-safe field families.
  - [x] Ensure unsupported or unknown observed fields never leak into conservative output.

- [x] Implement `observed_shape` mode.
  - [x] Bound sample scan per object type.
  - [x] Collect field presence and frequency.
  - [x] Prevent live value leakage.
  - [ ] Record which output fields were introduced only because they were observed.

- [x] Implement value styles.
  - [x] Support `blank`.
  - [x] Support `dummy`.
  - [x] Keep dummy-value generation deterministic so repeated sample builds do not drift unnecessarily.

- [x] Implement variant generation.
  - [x] Support `single`.
  - [x] Support `minimal`.
  - [x] Support `typical`.
  - [x] Support `maximal`.
  - [ ] Document the exact inclusion strategy for each variant so the output is deterministic.

- [x] Add multi-variant package support in settings/builder selection.
  - [x] Support a full-set variant option in addition to single-variant output.
  - [ ] Wire the full-set option into the archive assembly step.

- [ ] Filter protected/system-managed fields from sample authoring templates.
  - [ ] Do not teach the AI to author blocked DBVC runtime fields.
  - [ ] Expose blocked fields in docs when helpful, but not in the canonical fill-in templates.
  - [ ] Keep the filter list aligned with the foundation spec so sample and intake rules do not diverge.

- [x] Represent ACF fields logically instead of emitting raw underscore reference meta.
  - [x] Represent `group` as object.
  - [x] Represent `repeater` as array of row objects.
  - [x] Represent `flexible_content` as layout arrays with `acf_fc_layout`.
  - [x] Represent `clone` expanded logically with source noted in docs.
  - [x] Explain any deferred or unsupported ACF field families directly in the docs generated beside the sample.

- [x] Represent references using canonical structured slug refs.
  - [x] Represent post references.
  - [x] Represent taxonomy refs.
  - [x] Represent parent refs.
  - [x] Keep the reference contract identical between sample generation docs and submission validation rules.

- [x] Build per-sample markdown docs.
  - [x] Include background context.
  - [x] Include required vs optional fields.
  - [x] Include taxonomy rules.
  - [x] Include meta field context.
  - [x] Include ACF field context.
  - [x] Include available choices where known.
  - [x] Include relationship/reference guidance.
  - [ ] Include provenance notes.
  - [x] Include user-authored notes/rules merged in a predictable order.

### Implementation Notes

- Keep emitted templates deterministic and sorted.
- Do not emit raw site values in observed mode.
- Where ACF field naming is ambiguous, prefer logical authoring names and explain translation behavior in docs.

### Likely Touchpoints

- `includes/Dbvc/AiPackage/TemplateBuilder.php`
- `includes/Dbvc/AiPackage/ObservedShapeService.php`
- `includes/Dbvc/AiPackage/SampleDocBuilder.php`
- `includes/class-sync-posts.php`
- `includes/class-sync-taxonomies.php`

### Risks

- Observed-shape sampling can become noisy quickly. Cap scans and record provenance.
- ACF nested structures are likely to generate the most edge cases.

### Phase Exit

- [ ] Canonical post/CPT templates generate correctly.
- [ ] Canonical term templates generate correctly.
- [ ] Variant outputs are deterministic.
- [ ] Logical ACF structures are represented consistently.
- [ ] Per-sample markdown docs explain the fill-in contract clearly.

## P4. Sample Package Build Pipeline and Tools UI

Status: `WIP`

### Outcome

Operators can select post types/taxonomies, choose package options, and download a Sample Entities Package ZIP from `Tools`.

### Dependencies

- P3 template and doc builders available

### Tracked Tasks

- [x] Build a server-rendered configuration form on the Tools page.
  - [x] Add post type selection.
  - [x] Add taxonomy selection.
  - [x] Add shape mode selection.
  - [x] Add value style selection.
  - [x] Add variant set selection.
  - [x] Add observed-shape scan cap control.
  - [x] Add included docs control.
  - [x] Add capability and nonce checks for all generation submissions.

- [x] Validate and normalize incoming Tools form selections.
  - [x] Reject unsupported post types/taxonomies.
  - [x] Sanitize generation options.
  - [x] Provide actionable admin error messages.
  - [x] Prevent empty-selection submissions from entering the builder pipeline.

- [x] Build a package staging workspace under `uploads/dbvc/ai-sample-packages/{package_id}/`.
  - [x] Isolate each build.
  - [x] Create deterministic relative paths.
  - [x] Harden directories before writing artifacts.
  - [x] Ensure failed builds do not overwrite or contaminate adjacent package workspaces.

- [x] Generate package root docs.
  - [x] Generate `README.md`.
  - [x] Generate `AGENTS.md`.
  - [x] Generate `STARTER_PROMPT.md`.
  - [x] Generate `OUTPUT_CONTRACT.md`.
  - [x] Generate `USER_RULES.md`.
  - [x] Generate `VALIDATION_RULES.md`.
  - [x] Keep top-level docs opinionated enough that the package is usable without reading the foundation spec.

- [x] Generate schema artifacts.
  - [x] Generate `schema/object-inventory.json`.
  - [x] Generate `schema/field-catalog.json`.
  - [x] Generate `schema/validation-rules.json`.

- [x] Generate sample JSON and sibling `.md` files by object type and variant.
  - [x] Generate deterministic file naming by object family and variant.
  - [x] Keep per-sample `.md` and `.json` siblings aligned one-to-one.

- [x] Write `dbvc-ai-manifest.json`.
  - [x] Include generation settings.
  - [x] Include selected object families.
  - [x] Include site fingerprint and artifact fingerprints.
  - [x] Ensure manifest paths match the actual written archive layout.

- [x] ZIP the workspace and stream the package to the browser.
  - [x] Reuse existing ZIP helper patterns where useful.
  - [x] Avoid leaving long-lived partial artifacts on failure.
  - [x] Use deterministic archive entry ordering where practical so package diffs stay inspectable.

- [ ] Decide and implement sample package retention behavior for the first release.
  - [ ] Prefer short-lived temp staging unless debugging retention proves necessary.
  - [ ] Document whether successful package workspaces are deleted immediately or retained briefly for support/debugging.

### Implementation Notes

- The page can remain server-rendered in v1.
- The builder should be modular enough to support background builds later if needed.
- Generation failures should surface specific artifact-step errors, not a generic "package failed" notice.

### Likely Touchpoints

- `admin/class-ai-tools-page.php`
- `includes/Dbvc/AiPackage/SamplePackageBuilder.php`
- `includes/Dbvc/AiPackage/RulesService.php`
- `includes/Dbvc/AiPackage/Storage.php`
- `includes/Dbvc/Transfer/EntityPacketBuilder.php`

### Risks

- Very large observed-shape builds may become slow on large sites.
- If generation later needs async processing, keep the builder stateless enough to support job-based execution.

### Phase Exit

- [x] Tools UI accepts valid generation selections.
- [x] Sample packages build into a deterministic archive layout.
- [x] Top-level docs and schema artifacts are included.
- [x] Sample JSON and `.md` files are included correctly.
- [ ] ZIP download works without affecting unrelated DBVC workflows.

## P5. AI Package Detection, Extraction, Validation, and Reporting

Status: `WIP`

### Outcome

DBVC detects AI submission packages at upload time, extracts them into intake storage, validates them, and builds a structured report without disturbing the normal upload path for non-AI uploads.

### Dependencies

- P1 storage
- P2 schema/fingerprint services
- P3/P4 package contract artifacts as reference inputs

### Tracked Tasks

- [x] Add AI package detection based on root `dbvc-ai-manifest.json`.
  - [x] Detect AI packages positively rather than by exclusion.
  - [x] Do nothing different for non-AI packages.
  - [x] Keep detection isolated from later validation so package recognition and package acceptance are not conflated.

- [ ] Preserve the current upload path for non-AI archives and JSON files unchanged.
  - [x] Guard this explicitly in branching logic.
  - [x] Add regression logging if needed.
  - [ ] Add regression coverage so future AI-workflow edits do not silently alter generic upload routing.

- [x] Create an intake workspace under `uploads/dbvc/ai-intake/{intake_id}/`.
  - [x] Persist the source archive.
  - [x] Extract the archive.
  - [x] Normalize a single wrapper directory if present.
  - [x] Keep `source/`, `extracted/`, `translated/`, and `reports/` subdirectories.
  - [x] Reject path traversal, absolute-path, or parent-directory archive entries before extraction.
  - [x] Apply file-count and size guardrails before or during extraction so malformed archives do not exhaust resources.

- [ ] Validate manifest contract.
  - [x] Validate package type.
  - [x] Validate exact schema version.
  - [x] Validate site fingerprint.
  - [x] Validate operation mode.
  - [x] Validate declared counts and artifact metadata where applicable.
  - [ ] Validate that required manifest keys are present even when optional metadata is absent.

- [ ] Validate archive layout and file inventory.
  - [x] Validate required paths.
  - [x] Validate unsupported unexpected root paths.
  - [x] Validate duplicate file/entity collisions.
  - [x] Validate permitted file types and extensions for first-release package contents.
  - [ ] Reject unreadable, empty, or duplicate logical entity files with precise report entries.

- [ ] Validate entity JSON files.
  - [ ] Validate canonical JSON shape.
  - [x] Validate required fields.
  - [x] Validate create/update inference rules.
  - [ ] Validate update matching precedence.
  - [x] Validate protected/system-managed field policy.
  - [ ] Validate machine-readable validation rules.
  - [ ] Validate required slug reference resolvability.
  - [x] Distinguish JSON parse failures from semantic validation failures in the report.

- [ ] Build a structured validation report with issue severity and scope.
  - [ ] Support `package` scope.
  - [ ] Support `file` scope.
  - [ ] Support `entity` scope.
  - [ ] Support `field` scope.
  - [x] Support `warning` severity.
  - [x] Support `error` severity.
  - [x] Include machine-usable issue codes so the UI and tests do not depend on exact prose strings.
  - [x] Include source package path and logical field path where applicable.
  - [x] Persist the canonical machine-readable artifact as `reports/validation-report.json`.
  - [x] Optionally emit `reports/validation-summary.md` for operator-facing download/review.

- [ ] Persist the validation report and log summary results.
  - [ ] Use the current DBVC logging system.
  - [ ] Ensure blocked packages produce retained reports.
  - [ ] Retain enough metadata for post-redirect rendering and later support/debug review.

- [x] Ensure blocked packages never mutate the sync folder or run import.
  - [x] Ensure blocked packages also do not create partial translated artifacts that could be mistaken for accepted input.

### Implementation Notes

- Report generation should be deterministic and serializable.
- Validation should stop import, not stop diagnostics. Even blocked packages should provide a useful report.
- Persist enough metadata so the preflight UI can render after redirects or refreshes.

### Likely Touchpoints

- `includes/Dbvc/AiPackage/SubmissionPackageDetector.php`
- `includes/Dbvc/AiPackage/SubmissionPackageValidator.php`
- `includes/Dbvc/AiPackage/ValidationReportFormatter.php`
- upload handlers in:
  - `includes/class-sync-posts.php`
  - `admin/admin-page.php`

### Risks

- Upload-time branching must stay explicit so AI detection never leaks into legacy non-AI paths.
- Validation failures must remain easy to inspect after the initial upload request ends.

### Phase Exit

- [ ] AI package detection works reliably.
- [ ] Non-AI uploads are unchanged.
- [ ] Intake extraction and wrapper normalization work.
- [ ] Structured validation reports are generated and retained.
- [ ] Blocked packages cannot proceed into translation/import.

## P6. Translation and Importer Handoff

Status: `WIP`

### Outcome

Accepted AI package entities are translated into DBVC-native sync-compatible JSON and handed off through existing importer helpers.

### Dependencies

- P5 validator/reporting pipeline complete enough to produce accepted entities

### Tracked Tasks

- [x] Build a submission package translator that converts AI package entities into canonical DBVC sync-compatible JSON.
  - [x] Translate posts/CPTs.
  - [x] Translate terms.
  - [x] Apply canonical field ordering.
  - [ ] Apply canonical value normalization.
  - [x] Keep a source-to-translated artifact map for diagnostics and report links.

- [x] Resolve create/update entity intent per entity.
  - [x] Resolve create-intent entities.
  - [x] Resolve update-intent entities.
  - [x] Block unresolved updates.
  - [x] Preserve the resolved intent in translated artifacts and summaries for operator review.

- [x] Build a slug-resolution sublayer against the current site.
  - [x] Resolve post relationships.
  - [x] Resolve taxonomy references.
  - [x] Resolve parent references.
  - [x] Log resolution failures clearly.
  - [x] Keep resolution reads scoped to the current LocalWP site context only.

- [x] Build logical-to-storage ACF translation.
  - [x] Map simple top-level and group-descended field names to field keys.
  - [x] Synthesize underscore reference meta for simple fields.
  - [x] Support a first deferred storage-aware pass for `post_object`, `relationship`, and `taxonomy`.
  - [x] Flatten nested structures where required by storage conventions.
  - [x] Handle unsupported non-empty deferred field families safely.
  - [x] Ensure translation behavior matches the sample-package authoring docs so operators are not surprised by field shape changes.

- [x] Write translated artifacts under `uploads/dbvc/ai-intake/{intake_id}/translated/`.
  - [x] Make translated JSON inspectable before import.
  - [x] Retain translated artifacts in dry-run mode.
  - [ ] Write per-entity translation failures separately when partial acceptance is ever allowed by policy.
  - [x] Persist `translated/translation-manifest.json`.
  - [x] Persist importer-ready files under `translated/sync-root/{post_type}/` and `translated/sync-root/taxonomy/{taxonomy}/`.

- [ ] Build an importer handoff layer that reuses current DBVC import helpers rather than inventing a second engine.
  - [x] Reuse current post import helpers.
  - [x] Reuse current term import helpers.
  - [x] Reuse current taxonomy assignment handling.
  - [x] Reuse current import logging patterns.
  - [x] Keep AI-specific preflight and translation concerns out of the core import helper signatures where possible.
  - [x] Reapply supported deferred relationships after import for newly created in-package entities.

- [ ] Decide and implement how temporary sync-folder staging is used, if needed.
  - [ ] Prefer helper-driven import first.
  - [ ] Only use sync-folder staging when required for compatibility.
  - [ ] Document the reason if sync-folder staging proves necessary so it can be revisited later.

- [x] Preserve dry-run translation output and reports even when import is not executed.

### Implementation Notes

- Keep translation and import handoff separate services so dry-run remains clean.
- Translation errors should map back to source package paths and logical field paths.
- Nested ACF storage translation now covers `group`, `repeater`, `flexible_content`, and first-pass `clone` expansion for supported leaf families.

### Likely Touchpoints

- `includes/Dbvc/AiPackage/SubmissionPackageTranslator.php`
- `includes/Dbvc/AiPackage/SubmissionPackageImporter.php`
- `includes/class-sync-posts.php`
- `includes/class-sync-taxonomies.php`
- `includes/class-import-router.php`

### Risks

- Relationship/reference resolution needs careful diagnostics or operators will not understand why an import failed.
- The current ACF translation pass is intentionally bounded; unsupported complex/media fields now block when non-empty rather than being guessed.
- Advanced clone combinations still need broader regression coverage even though the first storage-aware path is in place.

### Phase Exit

- [x] Accepted AI package entities translate into canonical DBVC JSON.
- [x] Slug reference resolution works for supported reference types.
- [x] ACF logical structures translate into storage-ready meta.
  - Note: v1 now covers nested `group`, `repeater`, `flexible_content`, and first-pass `clone` storage translation for supported leaf families; unsupported media/exotic families still block when non-empty.
- [x] Import handoff reuses existing DBVC helpers.
- [x] Dry-run translated artifacts are retained and inspectable.
  - Note: retained validation, translation, and import artifacts now exist; the remaining gap is broader QA plus richer failure partitioning if partial acceptance policy expands.

## P7. Dedicated AI Preflight Review Surface

Status: `WIP`

### Outcome

AI package uploads get a dedicated UI surface for review, warnings, blocked issues, stats, and explicit operator confirmation before import.

### Dependencies

- P5 validation reports
- P6 translation summaries or dry-run artifacts

### Tracked Tasks

- [x] Add an AI-specific summary banner in the plugin UI when an AI package upload is detected.
  - [x] Keep this secondary to the dedicated review section.
  - [x] Use it for fast recognition, not full diagnostics.
  - [x] Ensure it does not visually resemble generic success notices when the package is blocked or warning-state.

- [x] Add a dedicated AI preflight results section in the plugin UI.
  - [x] Show package type.
  - [x] Show schema version.
  - [x] Show site fingerprint status.
  - [x] Show post count.
  - [x] Show term count.
  - [x] Show warning count.
  - [x] Show blocked count.
  - [x] Show final state: `valid`, `valid_with_warnings`, `blocked`.
  - [x] Show intake identifier and generation/import timestamps where helpful for support/debugging.

- [x] Add grouped issue presentation.
  - [x] Show package-level issues.
  - [x] Show file-level issues.
  - [x] Show entity-level issues.
  - [x] Show field-level issues.
  - [x] Keep grouping stable so issue locations remain predictable between runs.

- [x] Add operator actions.
  - [x] Add `Import`.
  - [x] Add `Cancel`.
  - [x] Add `Download Report`.
  - [x] Add optional `View Dry-Run Artifacts`.
  - [x] Protect all state-mutating actions with capability checks and nonces.

- [x] Add required-confirmation UX for `valid_with_warnings` when settings require confirmation.
  - [x] Make the confirmation language explicit about what warnings remain unresolved.

- [x] Add a detail view for per-entity and per-field issues.
  - [x] Support a modal, drawer, or expandable inline panel.
  - [x] Keep the exact UI form tranche-level, but require the inspection surface itself.
  - [x] Include source file paths, field paths, and issue codes in the detail view.

- [x] Persist validation and preflight state long enough for refreshes and post-redirect rendering.
  - [x] Ensure retained preflight state honors cleanup windows and does not accumulate indefinitely.

- [x] Retain post-import audit artifacts.
  - [x] Persist `reports/import-report.json`.
  - [x] Persist `reports/import-summary.md`.
  - [x] Surface retained import artifact downloads in the review UI.

### Implementation Notes

- The results surface should be able to render a blocked package without any import actions visible.
- Keep the first pass focused on readability and operator actionability rather than overbuilding a new admin app.
- The dedicated section is required even if a banner also exists.

### Likely Touchpoints

- `admin/class-ai-package-intake-page.php`
- `admin/admin-page.php`
- `src/admin-ai-intake/index.js`
- `src/admin-ai-intake/style.css`

### Risks

- If results are shown only as generic notices, operators will not have enough context to fix blocked packages.
- Validation state must survive redirect flows cleanly.

### Phase Exit

- [x] AI uploads show a dedicated preflight review surface.
- [x] Warnings and blocked issues are grouped and readable.
- [x] Operators can confirm warning-state packages explicitly.
- [x] Report download works.
- [x] Retained import artifact downloads work.
- [x] Blocked packages cannot bypass the review surface into import.

## P8. `AI + Integrations` Settings and Rule Authoring UX

Status: `WIP`

### Outcome

Operators can configure generation defaults, validation defaults, and user-authored notes/rules through a dedicated Configure subtab.

### Dependencies

- P1 settings scaffolding
- P2 schema/rules understanding

### Tracked Tasks

- [x] Build a server-rendered `AI + Integrations` subtab.

- [x] Add generation defaults controls.
  - [x] Add shape mode control.
  - [x] Add value style control.
  - [x] Add variant set control.
  - [x] Add observed-shape scan cap control.
  - [x] Mirror the defaults used on the Tools page so configuration and execution do not drift.

- [x] Add validation defaults controls.
  - [x] Add warning handling policy control.
  - [x] Add allowed package operation mode control.
  - [x] Add strictness level control where applicable.
  - [x] Keep defaults aligned with the foundation spec until a later decision changes them explicitly.

- [x] Add global user-authored notes/rules inputs.
  - [x] Add markdown guidance input.
  - [ ] Add structured defaults input or mapped controls.
  - [ ] Explain how global rules merge with per-object overrides.

- [ ] Add per-post-type notes/rules inputs.
  - [ ] Add defaults support.
  - [ ] Add required-fields support.
  - [ ] Add per-type notes support.
  - [ ] Keep unsupported post types from rendering stale rule editors.

- [ ] Add per-taxonomy notes/rules inputs.
  - [ ] Add defaults support.
  - [ ] Add create-allowance support.
  - [ ] Add required-fields support.
  - [ ] Keep unsupported taxonomies from rendering stale rule editors.

- [ ] Add structured rule editing or mapped form controls for the machine-readable rules schema.
  - [ ] Start narrow.
  - [ ] Cover only rules the validator actually enforces in v1.
  - [ ] Preserve a stable serialized shape so rule changes are diffable and testable.

- [ ] Pre-populate rule forms with sensible defaults instead of blank states.
  - [ ] Provide a reset-to-default path so operators can recover from bad customizations without manual option cleanup.

- [x] Add optional, future-facing provider settings scaffold.
  - [x] Add OpenAI default provider support.
  - [x] Add credential field scaffolding.
  - [x] Add model defaults support.
  - [x] Add service mode support.
  - [x] Add recurring model catalog refresh support once an API key is stored.
  - [x] Keep credentials masked, optional, and fully non-blocking for local sample generation and AI intake.

### Implementation Notes

- Keep rule editing focused on enforceable rules first.
- Avoid building a huge generic JSON editor in v1 unless the simpler mapped form proves inadequate.
- Provider settings must stay optional and non-blocking.

### Likely Touchpoints

- `admin/admin-page.php`
- `includes/Dbvc/AiPackage/Settings.php`
- `includes/Dbvc/AiPackage/RulesService.php`

### Risks

- Rule editing will get unwieldy quickly if the first pass tries to support every possible nested rule visually.
- Keep v1 form inputs targeted to the rules that validation actually enforces.

### Phase Exit

- [ ] `AI + Integrations` subtab is usable.
- [ ] Generation defaults save correctly.
- [ ] Validation defaults save correctly.
- [ ] Global and per-type/per-taxonomy rule inputs save correctly.
- [ ] Default rule values are pre-populated.
- [ ] Optional provider settings do not interfere with the rest of the flow.

## P9. Tests, Fixtures, Docs, QA, and Rollout Closure

Status: `WIP`

### Outcome

The feature ships with deterministic fixtures, targeted tests, manual QA guidance, and updated user-facing docs.

### Dependencies

- P1 through P8 materially complete

### Tracked Tasks

- [x] Add PHPUnit coverage for site fingerprint generation.

- [ ] Add PHPUnit coverage for ACF discovery fallbacks.

- [ ] Add PHPUnit coverage for schema artifact generation.

- [ ] Add PHPUnit coverage for sample template generation.

- [ ] Add PHPUnit coverage for protected field filtering.

- [x] Add PHPUnit coverage for manifest validation.

- [ ] Add PHPUnit coverage for create/update inference and match precedence.

- [ ] Add PHPUnit coverage for slug reference resolution.

- [x] Add PHPUnit coverage for warning vs blocked classification.

- [x] Add PHPUnit coverage for AI submission translation.

- [x] Add fixture packages.
  - [x] Add a valid sample package fixture.
  - [x] Add a valid submission package fixture.
  - [x] Add a submission package fixture with warnings.
  - [x] Add a blocked submission package fixture.
  - [ ] Add at least one fixture that exercises nested ACF structures and structured slug refs together.

- [x] Add manual QA scenarios.
  - [x] Add a repo-owned runtime smoke script for AI intake/import regression coverage.
  - [x] Add a dedicated manual QA checklist doc in [AI_PACKAGE_QA_CHECKLIST.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_PACKAGE_QA_CHECKLIST.md).
  - [ ] Cover unchanged non-AI ZIP upload behavior in browser QA.
  - [ ] Cover AI package detection in browser QA.
  - [ ] Cover blocked AI package handling in browser QA.
  - [ ] Cover warning-confirm AI package handling in browser QA.
  - [ ] Cover create-only entity packages in browser QA.
  - [ ] Cover mixed create-and-update packages in browser QA.
  - [ ] Cover ACF logical field translation in browser QA.
  - [ ] Cover slug-based relationship resolution in browser QA.
  - [ ] Cover capability/nonce protection on generation and import actions in browser QA.

- [ ] Update user-facing docs.
  - [ ] Document sample package generation.
  - [ ] Document AI package upload/review/import.
  - [ ] Document settings reference.
  - [ ] Document supported and unsupported first-release field families so expectations stay realistic.

- [ ] Add rollout notes and post-ship cleanup/follow-up items.

### Implementation Notes

- Prefer fixture-backed contract tests over brittle ad hoc string assertions.
- Add at least one fixture package that exercises nested ACF structures.
- Include negative tests for protected fields and unresolved slug refs.
- The repo now includes `scripts/check-wp-runtime-ai-import-smoke.php` as a repeatable LocalWP smoke for validator, translation, importer handoff, nested ACF storage translation, deferred relationship backfill, supported ACF relationship fields, and retained import artifacts.
- The repo now includes PHPUnit coverage in [AiPackageWorkflowTest.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/tests/phpunit/AiPackageWorkflowTest.php) for fingerprint drift, manifest validation states, fixture-backed intake, nested translation, and blocked unsupported media fields.

### Likely Touchpoints

- `tests/phpunit/`
- `docs/`
- fixture directories under `tests/fixtures/`

### Risks

- Without fixture packages, schema drift and validation regressions will be hard to catch.
- Without explicit QA on non-AI uploads, legacy behavior regressions may slip in unnoticed.

### Phase Exit

- [ ] Core services have fixture-backed PHPUnit coverage.
- [ ] Manual QA checklist exists.
- [ ] User docs are updated.
- [ ] Rollout notes are documented.

## P10. Managed Provider Integrations

Status: `DEFERRED`

### Deferred Scope

- plugin-managed outbound AI requests
- provider-specific prompt orchestration
- provider-specific package generation
- retry/fallback provider logic
- hosted agent execution from inside DBVC

This work should only begin after the local sample-generation and AI intake/import loop is stable.

### Tracked Tasks

- [ ] Revisit whether DBVC should make outbound provider calls at all.
- [ ] Revisit provider credential storage and security requirements.
- [ ] Revisit whether provider-managed workflows belong in core or an addon.

## Tranche-Level Decisions Intentionally Deferred

These items should be revisited as real implementation details surface:

- whether `site fingerprint mismatch` remains hard-block-only forever or gains an explicit admin override later
- whether deferred media-centric ACF field types such as `image`, `file`, and `gallery` should `block` or `warn_and_strip` when non-empty values are submitted in v1
- whether plain slug strings should be tolerated in more contexts or whether structured slug refs remain mandatory everywhere
- whether large sample package generation needs async execution in the first release
- whether the AI preflight detail view ships as a modal, drawer, or expandable inline panel in the first tranche

## Suggested Task Order

1. Land P1 storage/settings/admin wiring.
2. Land P2 schema and ACF discovery.
3. Land P3 template generation.
4. Land P4 sample package building and Tools UI.
5. Land P5 AI package detection and validation.
6. Land P6 translation and importer handoff.
7. Land P7 dedicated AI preflight review surface.
8. Land P8 `AI + Integrations` settings.
9. Land P9 tests, fixtures, docs, and rollout closure.

## Closure Criteria

This feature is ready for rollout when all of the following are true:

- [ ] operators can generate a sample package from `Tools`
- [ ] the sample package contains canonical JSON templates, schema artifacts, and AI guidance docs
- [ ] DBVC detects AI submission packages without affecting generic uploads
- [ ] blocked AI packages do not mutate sync or import state
- [ ] warning-state AI packages present a dedicated confirmation surface
- [ ] accepted packages translate into DBVC-native JSON and import through existing DBVC helpers
- [ ] create and update entities both work under the locked precedence rules
- [ ] fixture-backed validation and translation tests are green
- [ ] user-facing docs explain both package generation and AI package upload/review/import
