# Content Collector V2 Fixtures

## Purpose

This directory is reserved for small, deterministic fixtures that support V2 contract tests, reviewability workflows, dry-run bridging tests, and browser or integration smoke coverage.

No committed fixtures are required yet for the current `Phase 13` seam, but this folder is the intended home for them as the reviewability and actionability phases land.

## Recommended Fixture Groups

- `ready-package/`
  - a run and package shape that is `ready_for_import`
- `blocked-package/`
  - a run and package shape that is blocked by QA or executor write barriers
- `review-overrides/`
  - mapping and media decision cases that prove override carry-forward into package output
- `dry-run-bridge/`
  - package records and media manifests shaped for import bridge tests
- `schema-labels/`
  - target object and field samples that prove human-readable schema presentation without losing stable machine refs
- `conflict-review/`
  - flagged URL cases for conflict-first queueing, explicit decision controls, and stale-decision handling

## Fixture Rules

- keep fixtures anonymized
- keep fixtures domain-scoped
- keep filenames stable and deterministic
- prefer the smallest payload that still proves the contract
- include only the artifact slices needed by the test

## Related References

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CONTRACTS.md`
