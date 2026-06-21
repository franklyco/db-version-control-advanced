# DBVC · Bricks Portability & Drift Manager

This package is a documentation handoff for a **new lightweight feature tool inside DBVC's Bricks add-on**.

## Purpose

Allow a user to:

1. choose which Bricks settings domains to export from **Site A**
2. generate a portable `.zip` package
3. upload that package into **Site B**
4. compare the package against Site B's current Bricks data
5. review drift in a fast bulk-friendly UI
6. approve changes
7. apply changes with **backup + rollback**

## Recommended internal feature name

**Bricks Portability & Drift Manager**

Alternative labels for UI:

- Bricks Sync
- Bricks Settings Import / Export
- Bricks Drift Review
- Bricks Theme Portability

## Why this should exist inside DBVC

This is not just a raw import/export utility. It is a **governed transfer layer** for Bricks configuration objects.  
That makes it a natural fit for DBVC because DBVC already thinks in terms of:

- controlled exports
- drift detection
- review before overwrite
- safer imports
- snapshots / restore
- structured object comparison

## Package contents

- `01-product-overview.md`
- `02-scope-and-option-registry.md`
- `03-architecture.md`
- `04-export-package-spec.md`
- `05-diff-engine-and-matching-rules.md`
- `06-ui-and-interaction-model.md`
- `07-import-apply-backup-rollback.md`
- `08-data-model-and-storage.md`
- `09-security-validation-and-permissions.md`
- `10-implementation-phases.md`
- `11-acceptance-criteria.md`
- `12-codex-kickoff-prompt.md`
- `13-open-questions-and-risks.md`
- `examples/*`

## Practical recommendation

Build this in **two layers**:

### Layer 1 — portable package + compare/apply engine
This is the required core.

### Layer 2 — nicer quality-of-life review UI
This is where the bulk decision workbench becomes excellent.

The mistake to avoid is starting with a fancy interface before the object normalization and diff engine are stable.
