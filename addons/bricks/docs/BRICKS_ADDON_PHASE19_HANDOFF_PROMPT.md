# Phase 19 Handoff Prompt (Fresh Session)

Continue implementation in:
`/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`

Primary goal:
Kick off **Phase 19A** from active docs and implement the first slice of shared-rules distribution foundation.

Read these authoritative docs first:
1. `addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`
2. `addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`
3. `addons/bricks/docs/BRICKS_ADDON_PLAN.md`
4. `addons/bricks/docs/BRICKS_ADDON_FIELD_MATRIX.md`

Archive references (do not edit unless explicitly requested):
- `addons/bricks/docs/archive/BRICKS_ADDON_PROGRESS_TRACKER_ARCHIVE_P1_P18.md`
- `addons/bricks/docs/archive/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST_ARCHIVE_P1_P18.md`

Current status:
- Phases **P1-P18** completed and archived.
- Active roadmap is now split into **P19A**, **P19B**, **P19C**.
- No implementation has started for 19A/19B/19C yet.

Strict scope for this session:
- Implement only **P19A-T0** and **P19A-T1** first (contract freeze + mothership shared profile persistence/API).
- Do **not** start P19B/P19C in same pass unless explicitly requested.

Required first implementation slice:
1. `P19A-T0-S1/S2/S3` contract definitions in code comments/docs where appropriate.
2. `P19A-T1-S1/S2/S3`:
   - canonical storage model for shared profile,
   - strict validator/normalizer for all 5 rule maps,
   - mothership REST endpoints for read/write shared profile.

Validation requirements before finalizing:
- Add/extend PHPUnit coverage for new endpoints + validation.
- Run targeted tests for new phase tests.
- Re-run regression tests that are naturally impacted.

Progress tracking requirements:
- Update `BRICKS_ADDON_PROGRESS_TRACKER.md` statuses only for touched P19A tasks/subtasks.
- Add concise test evidence entries with exact commands and PASS/FAIL.
- If blocked, mark explicit `BLOCKED` status and concrete cause/next action.

Implementation constraints:
- Preserve existing behavior for drift/apply/proposals/packages.
- No automatic artifact apply behavior changes in 19A.
- Use idempotency and auditable correlation IDs for new mutating endpoints.

If live evidence is needed in this session:
- Request/confirm current credentials/URLs before running remote commands.
- Capture timestamps, commands, and endpoint responses in tracker evidence.
