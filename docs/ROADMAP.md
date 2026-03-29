# DB Version Control Roadmap

## Planned
1. PlanMapper addon
   - Add a first-class DBVC addon that provides a true built-in Kanban board inside the plugin instead of relying on an external planning tool.
   - Copy the commented feature ideas and enhancement notes from the Vertical theme `sandbox.php` into the DBVC plugin as the initial seeded backlog before implementation starts.
   - Store planned items as their own DBVC-managed entities/CPTs so the studio can plan, prioritize, and move work across columns/states inside WordPress.
   - Define the initial data model for status, priority, owner, release window, dependencies, visibility, and frontend-ready summary fields.
   - Support internal planning/admin surfaces plus public frontend output that can show current work, upcoming work, and "next up" items without exposing internal-only notes.
   - Keep addon configuration aligned with existing DBVC patterns so setup remains modular, readable, and easy to extend.
2. AI note/file converter for PlanMapper intake
   - Add a logged-in intake surface where users can paste notes or upload files and have a flexible AI layer interpret them into PlanMapper-ready objects/CPT posts.
   - Normalize loose roadmap notes, enhancement bullets, and planning dumps into the required schema/meta shapes for DBVC-managed planning items.
   - Support flexible source formats (plain text, markdown, exported notes, roadmap drafts, uploaded files) with preview, validation, and manual confirmation before writes occur.
   - Reuse DBVC review, audit, and permission patterns where possible so generated items can be accepted, edited, or rejected before they become live PlanMapper entries.
   - Define guardrails for capability checks, nonce verification, schema versioning, and fallback behavior when the AI output is partial, ambiguous, or malformed.
3. Universal single upload intake
   - Provide one upload surface that accepts single JSON, multi-JSON batches, or ZIPs.
   - Auto-route entity files to the correct sync subfolders (post types, taxonomies, media, options).
   - Offer dry-run preview + stats before writing files.
   - Preserve current legacy upload and proposal intake flows as optional paths.
   - Optionally emit a proposal bundle/manifest from the same intake when review is desired.
4. Canonical registry + proposal workflow for protected entities
   - Define central canonical registry (authority site or repo) with signed revisions.
   - Add proposal pipeline for promotion to canonical (review, accept/reject).
   - Track per-entity protection status and sync state (GOD/MOD/REVIEW/NONE/REJECTED).
   - Add status-driven packets for outbound review and inbound approvals.
   - Define normalized hashing spec for canonical comparison (stable JSON ordering and field selection).
   - Document pStatus state diagram and allowed transitions.
   - Define minimal REST endpoints for canonical authority (submit proposal, approve/reject, fetch canonical).
   - Configure "Authority Site (WordPress)" mode in Configure → Certified Canonicals UI (authority URL, auth, sync direction, test connection).
5. Granular options import/export controls
   - Add include/exclude controls for option keys/prefixes instead of all-or-nothing `options.json` handling.
   - Support preview/dry-run summaries for options changes before apply.
   - Add per-option-group toggles in UI + CLI parity for scripted imports/exports.
   - Preserve safe defaults for sensitive/core options while allowing explicit overrides.
6. User documentation library integration (`DBVC_USER_DOCUMENTATION_LIBRARY`)
   - Adopt `docs/DBVC_USER_DOCUMENTATION_LIBRARY.md` as the seed source for user-facing operational docs.
   - Add an in-plugin documentation/library surface that can render and organize user guides.
   - Wire doc update workflow into release/backlog cadence so behavior notes stay current with implementation.
7. Evaluate and test a dedicated Firecrawl/Firebase agent skill
   - Confirm product naming/scope before implementation; the provided bootstrap command targets `firecrawl-cli`, not Firebase-native tooling.
   - Queue install/init for a later pass using `npx -y firecrawl-cli@latest init --all --browser`.
   - Verify the generated skill/tooling can scrape a page to clean Markdown.
   - Verify search plus scrape of top results.
   - Verify full-site crawl support.
   - Verify full-domain map support.
   - Document setup prerequisites, generated files, auth/config needs, and whether it should become a first-class Codex skill in this repo.

## In Progress
- Triage and stabilize import/export edge cases across posts, terms, and media.
- Temporary 5-minute FTP upload window toggle in Configure > Import Settings (still requires additional testing).
- Complete manual QA for the new targeted upload immediate-import flow in the legacy upload area (`docs/legacy-upload-immediate-import-plan.md`).

## Shipped (Recent)
- Targeted upload immediate import for post JSON, including upload-area toggle, targeted post-only import helper, and combined routing/import report output.
- Smart routing for flat JSON uploads into the sync folder.
- Import/export guards to prevent unintended sync folder wipes.
- Trash/delete handling for posts, terms, and media, including media bundle cleanup.
