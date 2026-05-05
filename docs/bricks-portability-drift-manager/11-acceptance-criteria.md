# 11 · Acceptance Criteria

## Export
- User can choose which domains to export.
- Export generates a valid zip package.
- Package contains manifest, domain payloads, raw option payloads, and checksums.
- Export works without including unrelated DBVC data.

## Import validation
- User can upload a valid package.
- Invalid package structure is rejected with clear errors.
- Unsupported domains are surfaced without crashing the review flow.

## Compare engine
- Compare results are grouped by domain.
- Each drift row has:
  - status
  - display name
  - match summary
  - drift summary
  - warnings if any
  - decision slot
- Same-name / different-id and same-id / different-name are distinguishable.
- Identical rows are either hidden by default or collapsed.

## Review UI
- User can filter by domain, status, and warning state.
- User can bulk-apply decisions to selected rows or a whole domain.
- User can inspect a detailed diff drawer before approving.

## Apply
- System creates a backup before write.
- System applies approved changes only.
- Untouched target-only objects remain intact.
- System verifies post-write results.
- Failures surface precise reasons.

## Rollback
- User can restore a prior backup for a completed apply job.
- Restore returns the touched option values to their pre-apply state.

## Auditability
- System records export/import/apply/rollback jobs.
- User can inspect summary history.

## Performance
- Compare for moderate-sized Bricks payloads should remain usable in admin.
- Diff rows should be paginated or virtualized if large.

## Safety
- Only authorized users can access the tool.
- Package upload is validated server-side.
- Backup files are stored safely.
