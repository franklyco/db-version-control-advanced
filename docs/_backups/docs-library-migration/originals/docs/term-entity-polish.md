# Term Entity Polish Checklist

This document captures the concrete tasks required to harden the taxonomy entity experience now that term snapshots ship in manifests.

## Objectives
- Keep reviewer UX identical between post and term entities (filters, drawer layout, resolver badges).
- Ensure reopened proposals benefit from term snapshots without extra clicks.
- Document the CLI/cron hygiene needed to backfill legacy proposals.

## QA Coverage
1. **Drawer parity**
   - Open term diff drawers for tags, categories, and custom taxonomies.
   - Verify identity pills (taxonomy/slug) render above the diff and the resolver panel references term attachments when present.
   - Confirm Accept/Keep radios honour term field selections and persist when the drawer closes.
2. **Filters and search**
   - Toggle the “Term Fields” column set plus the “Term entities” filter to ensure pagination + virtualization behave.
   - Confirm “Needs Review” filter counts update when term-only proposals are auto-resolved.
3. **Resolver badges**
   - Create proposals that reference shared parents/attachments so resolver badges display “New term”, “Reused parent”, and conflict counts.
4. **Reopen flows**
   - Capture snapshots via `wp dbvc proposals list --recapture-snapshots` and reopen a proposal. Ensure stored term parents + metadata hydrate the diff without re-fetching the manifest.

## Backfill / Maintenance
- Run `wp dbvc proposals list --recapture-snapshots --only-terms` weekly until telemetry shows 90% of reopened proposals already include term snapshots.
- When audit logs show a stale term snapshot, execute `DBVC_Snapshot_Manager::capture_for_proposal( $proposal_id )` via wp shell to isolate the outlier.
- Document any taxonomy-specific sanitizer filters in `docs/meta-masking.md` so QA knows what edge cases to expect.

## Follow-up Tasks
- [ ] Automate the snapshot backfill through a nightly cron hook once CLI paths look stable.
- [ ] Add resolver metrics for term parents so the admin UI can sort unresolved hierarchies first.
- [ ] Update onboarding docs with GIFs/screens that highlight the term badges and filters.
