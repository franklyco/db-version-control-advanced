# AI Sample Package Compact Profile Plan

Status: Implemented baseline with QA/metrics follow-up
Date: 2026-06-16
Scope: Reduce AI sample package context size for browser-based chat workflows without weakening DBVC import safety

## Current Resume Snapshot

Compact mode is implemented locally and is the default profile.

- Implemented so far:
  - `compact_ai_chat` exists as a real package profile and is the default
  - the Tools page and `AI + Integrations` defaults expose the profile setting
  - compact generation currently emits:
    - `dbvc-ai-manifest.json`
    - `START_HERE.md`
    - `SCHEMA_COMPACT.json`
    - `samples/posts/{post_type}.json`
    - `samples/posts/{post_type}.context.json`
    - `samples/terms/{taxonomy}.json`
    - `samples/terms/{taxonomy}.context.json`
  - compact generation skips sibling sample markdown docs, but emits sibling `.context.json` files with object context plus path-keyed field type, choices, and authoring context
  - `START_HERE.md` and `SCHEMA_COMPACT.json` include an explicit returned `dbvc-ai-manifest.json` template with nested `source_sample_package.site_fingerprint`
  - intake accepts legacy AI-generated manifests that copied sample `site_fingerprint` to the root or used `validation_defaults.package_mode` instead of `intended_operation`, but only with warnings
  - full-reference generation still emits the richer package
  - full-reference sample markdown no longer duplicates the full JSON template snapshot
  - authoring docs now describe `docs/NOTES.md` and `reports/generation-summary.md` as optional, not required
- Validation already completed for the current local state:
  - `php -l` passed on the touched compact-profile PHP files
  - [scripts/check-wp-runtime-authoring-smoke.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/scripts/check-wp-runtime-authoring-smoke.php) passed with `compact-authoring-smoke-ok`
  - `vendor/bin/phpunit tests/phpunit/AiPackageWorkflowTest.php` passed with `12 tests, 133 assertions`
- Most important remaining compact-mode gaps:
  - the Tools page does not yet show estimated file count or prompt footprint before generation
  - package-size regression metrics and before/after comparisons are still missing
  - browser-level QA with a real compact package in a browser LLM workflow is still pending

## Purpose

This plan defines a compact package profile for DBVC AI sample package generation.

The current AI sample package is accurate, but it is too repetitive for browser-based LLM chat apps that have practical context limits far below their advertised maximums. The result is:

- too many root docs with overlapping instructions
- too many sibling sample `.md` files
- repeated JSON and prose describing the same fields
- reduced model attention on the actual canonical entity templates
- weaker output quality in returned AI submission packages

The goal of this plan is to reduce package size, repetition, and prompt burden while preserving the exact DBVC import contract.

## Outcome

The finished feature should support two generation profiles:

- `compact_ai_chat`
  - default profile
  - optimized for ChatGPT, Claude, and similar browser chat sessions
  - minimal file count
  - minimal repeated prose
  - one merged contract doc
  - one merged schema artifact
- `full_reference`
  - optional profile
  - preserves the richer current package for deep review, offline inspection, or non-chat workflows

The returned AI submission package should also be documented as thinner by default.

## Core Recommendation

Keep the data canonical and reduce everything else.

That means:

- preserve canonical DBVC-shaped sample JSON
- preserve strict import-facing manifest rules
- preserve selected-object scope controls
- preserve validation and intake safety
- reduce repeated prose, repeated snapshots, and redundant docs

The main reduction should come from removing duplication, not from weakening the field contract.

## Must-Preserve Constraints

- Do not change DBVC’s importer-facing canonical JSON field names.
- Do not create a second abstract output format for AI authoring.
- Do not weaken blocked-field rules or validation rigor just to shrink the package.
- Do not require outbound AI provider calls.
- Do not require `full_reference` assets in order for AI submission packages to validate.
- Do not break already generated sample packages or already accepted submission package structures.

## Target Product Shape

### 1. New package profile setting

Add a generation profile setting with these options:

- `Compact AI Chat`
- `Full Reference`

Recommended default:

- `Compact AI Chat`

This setting is stored alongside current generation defaults in [Settings.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/Settings.php) and exposed in the current server-rendered admin surface in [admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php).

### 2. Compact sample package layout

Canonical compact layout:

```text
dbvc-ai-manifest.json
START_HERE.md
SCHEMA_COMPACT.json
samples/posts/{post_type}.json
samples/posts/{post_type}.context.json
samples/terms/{taxonomy}.json
samples/terms/{taxonomy}.context.json
```

Compact profile rules:

- exactly one JSON sample per selected object type
- one sibling `.context.json` per selected object type with compact object context plus each sample field's type, choices, and best available authoring context
- no per-sample markdown docs by default
- no repeated template snapshots inside markdown
- no separate root docs for README, AGENTS, OUTPUT_CONTRACT, USER_RULES, or VALIDATION_RULES
- no multi-variant output unless the operator explicitly overrides the variant setting

### 3. Full reference package layout

Full reference keeps the richer current layout, with targeted cleanup:

- keep root docs available
- keep sibling sample markdown available
- remove duplicated “Template snapshot” JSON blocks from markdown because they repeat the sibling `.json`
- keep multi-variant output available

### 4. Minimal AI submission package contract

The default documented return contract should be:

```text
dbvc-ai-manifest.json
entities/posts/{post_type}/{slug}.json
entities/terms/{taxonomy}/{slug}.json
```

Optional return artifacts may still be tolerated:

- `docs/NOTES.md`
- `reports/generation-summary.md`

But those should not be required in the starter prompt or in the compact output contract.

## Compact Artifact Design

### START_HERE.md

This file replaces most current root docs in compact mode.

It should contain only:

- what this package is
- what the AI must return
- the required submission ZIP layout
- create/update identity rules
- slug-reference rules
- blocked/forbidden field rules
- one short workflow summary

It should not restate the full field catalog in prose.

### SCHEMA_COMPACT.json

This file replaces the current split between:

- `schema/object-inventory.json`
- `schema/field-catalog.json`
- `schema/validation-rules.json`

for compact mode only.

Recommended shape:

```json
{
  "package_schema_version": 1,
  "site_fingerprint": "sha256...",
  "selection": {
    "post_types": ["page", "service"],
    "taxonomies": ["category", "service_type"]
  },
  "return_contract": {
    "package_type": "dbvc_ai_submission_package",
    "package_schema_version": 1,
    "required_paths": [
      "dbvc-ai-manifest.json",
      "entities/posts/{post_type}/{slug}.json",
      "entities/terms/{taxonomy}/{slug}.json"
    ],
    "allowed_intended_operations": ["create_only", "update_only", "create_or_update"],
    "manifest_template": {
      "package_type": "dbvc_ai_submission_package",
      "package_schema_version": 1,
      "source_sample_package": {
        "site_fingerprint": "sha256...",
        "package_schema_version": 1
      },
      "intended_operation": "create_only",
      "counts": {
        "post_entities": 0,
        "term_entities": 0
      }
    }
  },
  "objects": {
    "service": {
      "entity_kind": "post",
      "required_fields": ["ID", "post_type", "post_title", "post_name"],
      "allowed_top_level_fields": [],
      "blocked_top_level_fields": [],
      "taxonomies": [],
      "meta": {},
      "acf": {}
    }
  }
}
```

Design rules:

- keep it machine-oriented, not prose-heavy
- include only fields relevant to selected object types
- include only compact validation facts that the AI needs to author valid payloads
- include provenance only where it materially helps authoring
- omit duplicated descriptive text that already appears in `START_HERE.md`

### Sibling `.context.json` Artifacts

Each compact sample JSON has a sibling `.context.json` file. These files should remain intentionally smaller than the internal Object Type Context and Field Context provider payloads.

Package-facing context should include:

- object-level authoring context
- path-keyed fields
- each field's type
- choices when known
- one best available context string, selected from `resolved_purpose`, then `effective_purpose`, then `default_purpose`

Package-facing context should omit provider trace data, confidence internals, hashes, raw field registry details, and any other system-specific metadata that does not help the AI assign values.

## Simplification Strategy

### Highest-value reductions

- remove per-sample `.md` files in compact mode
- remove repeated root docs in compact mode
- remove the `Template snapshot` JSON block from sibling sample docs in all modes
- document the submission package as manifest + entity JSON only
- default to `single` variant in compact mode
- default to `conservative` shape mode in compact mode

### Secondary reductions

- hide optional docs behind the `full_reference` profile rather than document checkboxes in compact mode
- collapse root doc checkboxes into profile-aware presets
- keep compact prompts short and imperative
- avoid repeating field rules that can be represented structurally in `SCHEMA_COMPACT.json`

### Reductions to avoid

- do not remove the canonical sample JSON
- do not remove site fingerprint data
- do not remove blocked-field rules
- do not flatten away ACF field context that is required for valid authoring
- do not use purely human-readable prose in place of structural field data

## Recommended Implementation Order

## Phase C0. Contract Lock

Status: `CLOSED`

- [x] Update [AI_PACKAGE_FOUNDATION_SPEC.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_PACKAGE_FOUNDATION_SPEC.md) to recognize the compact profile and the minimal return-package contract.
- [x] Update [AI_SAMPLE_ENTITIES_IMPLEMENTATION_GUIDE.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_SAMPLE_ENTITIES_IMPLEMENTATION_GUIDE.md) to reference this compaction workstream.
- [x] Lock the default generation profile as `compact_ai_chat`.
- [x] Lock the returned submission package docs as optional rather than required.

## Phase C1. Settings and UI

Status: `WIP`

- [x] Add a package profile option in [Settings.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/Settings.php).
- [x] Add profile options:
  - [x] `compact_ai_chat`
  - [x] `full_reference`
- [x] Set the default to `compact_ai_chat`.
- [x] Add a package profile selector to the current Tools/admin surface in [admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php).
- [x] Make profile-aware UI behavior explicit:
  - [x] compact mode should explain that doc selection is preset and minimized
  - [x] full mode should continue to expose richer artifact choices
- [ ] Add a visible estimate block on the Tools page for:
  - [ ] total generated file count
  - [ ] root doc count
  - [ ] sample JSON count
  - [ ] estimated compact prompt footprint

## Phase C2. Builder Profile Support

Status: `WIP`

- [x] Extend [SamplePackageBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/SamplePackageBuilder.php) to branch on package profile.
- [x] Compact mode:
  - [x] write `START_HERE.md`
  - [x] write `SCHEMA_COMPACT.json`
  - [x] write one `.json` sample per selected object type
  - [x] skip sibling `.md` sample docs
  - [x] skip multi-doc root artifact generation
- [x] Full mode:
  - [x] retain current richer outputs
  - [x] remove redundant JSON snapshot duplication from sample markdown
- [x] Persist the chosen profile in the generated manifest.
- [ ] Add manifest counts for:
  - [ ] total files
  - [ ] root docs
  - [ ] sample docs
  - [ ] estimated compact token budget

## Phase C3. Compact Schema Builder

Status: `WIP`

- [x] Add a focused schema compaction service.
- [x] Current compact schema builder:
  - [x] [CompactSchemaBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/CompactSchemaBuilder.php)
- [x] Build compact schema output from existing schema bundle services rather than duplicating discovery logic.
- [ ] Include:
  - [x] selected objects only
  - [ ] required core fields
  - [ ] allowed and blocked top-level fields
  - [ ] relevant taxonomies
- [ ] compact meta context
- [x] compact ACF context
  - [x] return-contract requirements
- [ ] Exclude:
  - [ ] verbose prose
  - [ ] duplicated descriptions already present in `START_HERE.md`
  - [ ] low-signal provenance noise unless it affects authoring

## Phase C4. Root Doc Consolidation

Status: `WIP`

- [x] Add a compact root doc builder path in [PackageDocBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/PackageDocBuilder.php).
- [ ] Merge the minimum useful content from:
  - [x] `README.md`
  - [x] `AGENTS.md`
  - [x] `STARTER_PROMPT.md`
  - [x] `OUTPUT_CONTRACT.md`
  - [ ] `VALIDATION_RULES.md`
- [x] Write that merged content into `START_HERE.md`.
- [x] Keep `START_HERE.md` short enough that it can be pasted or skimmed in a browser chat without losing the actual JSON examples.
- [ ] Move user-authored guidance into a compact subsection instead of a separate always-on root doc.

## Phase C5. Sample Doc Reduction

Status: `WIP`

- [x] Stop emitting per-sample markdown docs in compact mode.
- [x] Remove the `Template snapshot` JSON block from [SampleDocBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/SampleDocBuilder.php) in all modes.
- [x] Keep sibling sample docs only in full-reference mode.
- [x] Ensure the compact schema and sibling `.context.json` artifacts carry enough ACF and taxonomy context that losing the sibling docs does not break authoring quality.

## Phase C6. Submission Contract Simplification

Status: `WIP`

- [x] Update [PackageDocBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/PackageDocBuilder.php) so compact prompts ask for only the minimal submission package layout.
- [x] Update [AI_PACKAGE_FOUNDATION_SPEC.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_PACKAGE_FOUNDATION_SPEC.md) to mark `docs/NOTES.md` and `reports/generation-summary.md` as optional return artifacts.
- [x] Verify [SubmissionPackageValidator.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/SubmissionPackageValidator.php) continues to accept minimal packages with only manifest + entity JSON.
- [x] If validator layout rules imply richer return docs, relax that expectation without weakening manifest or entity validation.

## Phase C7. Metrics, QA, and Rollout

Status: `WIP`

- [ ] Add fixture coverage for compact package generation.
- [ ] Add fixture coverage for minimal submission ZIP acceptance.
- [ ] Add PHPUnit coverage for profile-aware manifest/artifact counts.
- [ ] Add a package-size regression check:
  - [ ] file count comparison
  - [ ] root doc count comparison
  - [ ] bytes on disk
  - [ ] estimated token footprint
- [x] Add at least one runtime QA pass using a real compact package build in LocalWP.
- [ ] Add at least one browser QA pass using a real compact package in a browser LLM workflow.
- [ ] Record before/after comparisons in the docs.

## Recommended File Touchpoints

Primary expected touchpoints:

- [Settings.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/Settings.php)
- [admin-page.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php)
- [SamplePackageBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/SamplePackageBuilder.php)
- [PackageDocBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/PackageDocBuilder.php)
- [SampleDocBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/SampleDocBuilder.php)
- [SubmissionPackageValidator.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/SubmissionPackageValidator.php)
- [AI_PACKAGE_FOUNDATION_SPEC.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_PACKAGE_FOUNDATION_SPEC.md)
- [AI_SAMPLE_ENTITIES_IMPLEMENTATION_GUIDE.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/AI_SAMPLE_ENTITIES_IMPLEMENTATION_GUIDE.md)

Recommended new supporting files:

- [CompactSchemaBuilder.php](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/AiPackage/CompactSchemaBuilder.php)

## Migration and Compatibility

- Existing full-reference package generation should continue to work.
- Existing accepted AI submission package shapes should continue to validate.
- Compact mode should become the default for new generation runs only.
- Legacy or richer return packages should remain tolerated unless they violate the actual manifest/entity contract.

## Risks

- If compact mode removes too much ACF context, output quality will improve in breadth but regress in correctness.
- If compact mode preserves too many docs, the package will stay too large to help.
- If compact schema is generated from a second code path instead of the existing schema bundle, drift will appear quickly.
- If the submission contract docs are simplified without validator verification, operators may get a smaller package that still fails intake.

## Success Criteria

- A compact sample package can be generated with materially fewer files than the current default.
- The compact package still produces valid AI submission ZIPs against the current validator.
- Operators can choose full-reference mode when they want deeper docs.
- The compact starter flow is short enough for browser chat use without burying the canonical sample JSON.
- No regression is introduced in importer safety or AI intake validation quality.

## Immediate Next Step

Finish `Phase C3`, add validator and PHPUnit coverage for the thinner submission contract, then add package-size metrics to the Tools surface so the compact/full tradeoff is visible before generation.
