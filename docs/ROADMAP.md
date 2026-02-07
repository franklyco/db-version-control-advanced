# DB Version Control Roadmap

## Planned
1. Universal single upload intake
   - Provide one upload surface that accepts single JSON, multi-JSON batches, or ZIPs.
   - Auto-route entity files to the correct sync subfolders (post types, taxonomies, media, options).
   - Offer dry-run preview + stats before writing files.
   - Preserve current legacy upload and proposal intake flows as optional paths.
   - Optionally emit a proposal bundle/manifest from the same intake when review is desired.

## In Progress
- Triage and stabilize import/export edge cases across posts, terms, and media.

## Shipped (Recent)
- Smart routing for flat JSON uploads into the sync folder.
- Import/export guards to prevent unintended sync folder wipes.
- Trash/delete handling for posts, terms, and media, including media bundle cleanup.
