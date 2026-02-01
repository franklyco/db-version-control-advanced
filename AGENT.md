# DBVC Admin App Agent Guide

> **TL;DR:** Use this doc as the quick-start for working on the admin app. You’ll mostly jump between the REFRACTOR plan (or its per-epic wiki pages once we split it up), this checklist, and the Testing Matrix. Always back up the bundle, work on feature branches, run lint/tests + manual QA, and keep fixtures/docs updated.

This guide summarizes how to work on the DB Version Control admin React app safely. Use it alongside `docs/REFRACTOR.md`, `README.md`, and the existing WordPress build docs.

## Repo Layout & Generated Assets
- Source JS/CSS lives under `src/admin-app/**` (goal of the refactor). The current compiled bundle is `src/admin-app/index.js` (legacy) with output at `build/admin-app.js`.
- Before editing, tag or copy the current bundle (`git tag refactor-backup-<date>`, archive `build/admin-app.js`) so a working reference exists.
- Never edit `build/*.js` directly; always run `npm run build` to regenerate artifacts.
- Drop anonymized QA bundles under `docs/fixtures/` (see `docs/fixtures/README.md`). Use the “Dev upload” option in the admin uploader to copy new ZIPs there when you need refreshed fixtures.

## Workflow & Branching
1. Create a feature branch per epic/sub-epic (e.g., `feature/resolver-bulk-refactor`).
2. Keep `main` using the legacy bundle until the modular replacement reaches parity.
3. Update `docs/REFRACTOR.md` when you start/finish a sub-step (status + QA notes).
4. Run lint/tests + manual QA (see Testing Matrix) before opening a PR.
5. Use feature flag `DBVC_USE_MODULAR_APP` (see below) to toggle between bundles in dev/staging.

## Feature Flag Toggle
- Add `define( 'DBVC_USE_MODULAR_APP', true );` to `wp-config.php` (or use a filter stub) to enqueue the new modular bundle once it exists.
- Default should remain `false` on production until the modular build is fully vetted.
- QA should exercise both states whenever code touches the enqueue logic.
- Dev fixtures live outside WP uploads; use the feature flag only after verifying proposals import correctly from both WP uploads and `docs/fixtures/`.

## Testing Matrix
| Area | Automated | Manual |
| --- | --- | --- |
| JS Modules | `npm run lint`, `npm test` | Load proposals, select entities, open drawer, make Accept/Keep decisions |
| Masking/Resolver | Unit tests for hooks/components | Run masking drawer load/apply/undo + resolver bulk apply (reason/UID/path) |
| Apply Flow | Integration test (when added) | Run apply modal for success + failure scenarios, verify history + toasts |
| PHP/WP | `composer test` (when available) | Verify REST endpoints via WP admin on supported WP/PHP versions |

Record deviations (e.g., skipped manual step) in PR notes.

## Sample QA Scenarios
- **Masking:** use a proposal with masked meta fields; test load/apply/undo/revert.
- **Duplicates:** import a manifest with duplicate slugs to exercise the duplicate modal + cleanup.
- **New entities:** ensure proposals include new posts/terms so new-entity gating UI appears.
- **Resolver conflicts:** test attachments requiring reuse/download/map to confirm conflict workflows.

## Logging & Telemetry
- For critical flows (masking apply, resolver bulk, proposal apply, duplicate cleanup) add structured console logging in dev builds to trace proposal IDs, entity IDs, actions, and outcomes.
- Avoid noisy logs in production; gate verbose output behind `window.DBVC_DEBUG` or similar.

## Contribution Checklist
- [ ] Backup/tag current bundles before editing.
- [ ] Work on a feature branch; reference the REFRACTOR sub-step ID in commit messages.
- [ ] Implement code/tests, run `npm run lint && npm test`.
- [ ] Execute manual QA from the matrix relevant to your change.
- [ ] Update `docs/REFRACTOR.md` checkboxes + notes.
- [ ] Mention feature flag requirements or telemetry additions in PR descriptions.

Following this guide keeps the refactor “on the rails” and gives future agents the context they need to modify or enhance the admin app safely.
