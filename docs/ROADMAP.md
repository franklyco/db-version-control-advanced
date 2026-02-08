# DB Version Control Roadmap

## Planned
1. Universal single upload intake
   - Provide one upload surface that accepts single JSON, multi-JSON batches, or ZIPs.
   - Auto-route entity files to the correct sync subfolders (post types, taxonomies, media, options).
   - Offer dry-run preview + stats before writing files.
   - Preserve current legacy upload and proposal intake flows as optional paths.
   - Optionally emit a proposal bundle/manifest from the same intake when review is desired.
2. Canonical registry + proposal workflow for protected entities
   - Define central canonical registry (authority site or repo) with signed revisions.
   - Add proposal pipeline for promotion to canonical (review, accept/reject).
   - Track per-entity protection status and sync state (GOD/MOD/REVIEW/NONE/REJECTED).
   - Add status-driven packets for outbound review and inbound approvals.
   - Define normalized hashing spec for canonical comparison (stable JSON ordering and field selection).
   - Document pStatus state diagram and allowed transitions.
   - Define minimal REST endpoints for canonical authority (submit proposal, approve/reject, fetch canonical).
   - Configure "Authority Site (WordPress)" mode in Configure → Certified Canonicals UI (authority URL, auth, sync direction, test connection).

## In Progress
- Triage and stabilize import/export edge cases across posts, terms, and media.
- Temporary 5-minute FTP upload window toggle in Configure > Import Settings (still requires additional testing).

## Shipped (Recent)
- Smart routing for flat JSON uploads into the sync folder.
- Import/export guards to prevent unintended sync folder wipes.
- Trash/delete handling for posts, terms, and media, including media bundle cleanup.
