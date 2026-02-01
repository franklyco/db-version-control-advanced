# DB Version Control Advanced

**Review, diff, and apply WordPress content safely via proposal bundles, JSON manifests, and a React-based admin workflow.**

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/) [![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/) [![License](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## Overview

DB Version Control Advanced extends the original DBVC exporter/importer with deterministic identities, proposal zips, and an end-to-end review pipeline. Export jobs collect posts, terms (legacy), menus, options, and media into a normalized manifest. Reviewers upload that proposal inside WordPress, triage differences with Accept/Keep selectors, resolve media conflicts, and finally apply the curated changes. All of this lives in a single “DBVC Export” admin screen powered by React and backed by REST endpoints.

Legacy full-site export/import and WP-CLI commands continue to ship for automation, but production workflows can now block direct imports until a proposal has been reviewed.

## Feature Highlights

- **Proposal-driven review** – Upload proposal zips, see resolver metrics, and diff entities inside the React UI. Accept/Keep individual fields, bulk-apply sections, or reject the entity entirely. Drawer-based review keeps context with keyboard & screen-reader support.
- **Identity layer + diff engine** – Posts, pages, and custom post types are stamped with stable `vf_object_uid` identifiers, allowing cross-environment comparisons. Diffs are grouped by section (content, meta, taxonomies, media references) to make large payloads easier to scan.
- **New entity gating** – Newly introduced entities are flagged automatically. Reviewers must explicitly “Accept new” before they can be applied, and the importer respects those decisions to prevent surprise inserts.
- **Duplicate detection & cleanup** – Resolver preflight checks manifest entities against the local site. If duplicate slugs or conflicting manifests are detected an overlay blocks review until reviewers select a canonical entry.
- **Deterministic media resolver** – Each proposal captures attachment hashes, bundle paths, and resolver decisions. Reviewers can reuse, download, skip, or remap attachments, then persist those decisions per proposal or globally. Resolver bulk tools target conflicts by reason or path, and bundles guarantee offline parity.
- **Global resolver rules** – CSV import/export, inline add/edit, and validation tooling keep attachment mappings in sync between proposals. New proposals preload these rules so reviewers rarely have to re-decide identical conflicts.
- **Logging & activity history** – Structured tables record exports, imports, resolver passes, and apply jobs. The React app surfaces recent apply history and toast notifications when background tasks finish.
- **WP-CLI & automation** – The classic `wp dbvc export`/`import` commands remain for CI and scripted environments, including chunked exports, diff baselines, and menu/option syncing.

## Requirements

- **WordPress** 6.0+
- **PHP** 7.4+
- **MySQL/MariaDB** with support for InnoDB and `utf8mb4`
- Ability to write to the configured sync directory (default `wp-content/uploads/dbvc-sync/`)
- Optional: **WP-CLI** for automation and CI/CD jobs

## Installation

### WordPress Admin
1. Download the plugin zip or clone into your project.
2. Visit **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin. A **DBVC Export** item appears in the admin menu.
4. Visit **DBVC Export** to configure sync paths, proposal defaults, and media policy.

### Manual Installation
1. Copy the repository into `/wp-content/plugins/db-version-control-main` (or install via Composer/git submodule).
2. Activate through **Plugins → Installed Plugins**.
3. Adjust permissions on your sync directory (`uploads/dbvc-sync` by default) so exports can write JSON and media bundles.

## Proposal Workflow

### 1. Generate a proposal
- Use **DBVC Export → Export/Download** to run a full export, diff export (baseline), or chunked export. Each run writes JSON into your sync folder and logs a snapshot.
- When “Require DBVC proposal review” is enabled, every import must originate from a proposal zip. Export actions provide a zipped bundle containing:
  - `dbvc-manifest.json` (schema v3) – site metadata, entity hashes, resolver decisions, and media index entries.
  - `entities.jsonl` – normalized entity snapshots with UID, metadata, and sections for diffing.
  - `media/` – optional deterministic bundles for referenced attachments.
- Export settings let you seed media bundles per proposal, mirror remote domains, and opt into remote-only vs bundle-only transport.

### 2. Upload & review
- Click **Open Proposal Review** inside the DBVC Export page or use the floating “Review proposals” button.
- Upload a proposal zip via drag-and-drop or pick an existing one from `/uploads/dbvc/proposals/`.
- The React app loads proposal metadata, resolver metrics, duplicate counts, and new-entity stats.
- Filter entities by status (needs review, conflicts, media needs attention, with decisions, new posts) or search by title/slug/type.
- Open an entity to see per-field diffs. Accept means “apply this change from the proposal”; Keep leaves the current site value untouched. Bulk actions exist at section level (content/meta/media) and globally for unresolved sets.
- “New post” badges highlight entities without a local counterpart. Reviewers can accept/decline the new entity directly from the table header or inside the drawer. Bulk accept controls run `scope=new_only` actions over the REST API.
- Media attachments render inside the resolver panel. Reviewers can reuse an existing attachment, force a download, skip entirely, or remap to a different attachment ID—optionally persisting the rule globally. Attachments also expose advanced bulk filters (reason, UID, manifest path).
- Duplicate overlays block navigation until canonical entries are chosen. Cleanup calls ensure extra JSON artifacts are deleted so reviewers only see authoritative data.

### 3. Apply curated changes
- When every required entity decision is made (no unresolved conflicts, duplicates cleared), reviewers click **Apply Proposal** in the React app. The REST apply endpoint enforces permissions, writes per-field updates, and logs summaries.
- Importer honors Accept/Keep decisions path-by-path. Entities without accepted paths are skipped, and skipped/new-entity declines appear in the apply history so reviewers know why content stayed untouched.
- After a successful apply the UI can auto-clear proposal decisions (Config → Import Defaults) to keep the option table lean; toggles exist to retain them for auditing.
- Optional: download deterministic zips or share them with downstream sites. All resolver decisions and bundles travel with the zip so another environment can replay the review process without starting over.

## Official Collections (WIP)

- Schema + storage scaffolding now exist for “mark official” flows. `DBVC_Database` provisions `wp_dbvc_collections` and `wp_dbvc_collection_items`, and the new `Dbvc\Official\Collections` helper copies manifests + entity snapshots into `uploads/dbvc/official/collection-{id}`.
- Call `Collections::mark_official( $proposal_id, $entities, $meta )` after a proposal is approved to persist release metadata (title, status, tags, checksum, manifest/archive paths) along with each reviewed entity payload.
- Snapshots are written as JSON under `entities/{vf_object_uid}.json` so future REST + CLI features can stream immutable bundles without having to rehydrate decisions from proposals.
- Upcoming work will layer UI tabs, REST endpoints, and CLI commands on top of this storage so teams can list, download, and revoke official bundles directly from WordPress.

## Legacy Export/Import & WP-CLI

The proposal workflow is the default for interactive reviews, but legacy automation remains:

```bash
wp dbvc export --batch-size=100          # batch export
wp dbvc export --baseline=latest         # diff export vs latest snapshot
wp dbvc export --chunk-size=250          # resumable chunked export
wp dbvc import --batch-size=25           # legacy full import (honors Accept state when sync dir already staged)
```

CLI commands continue to export menus and options automatically, respect chunked jobs stored in `wp_dbvc_jobs`, and log activity rows for observability. Imports should be restricted to CI/staging unless you deliberately bypass the React workflow.

## Settings Overview

- **Require DBVC Proposal Review** – hides the legacy Run Import form and forces reviewers into the React workflow.
- **Mirror Domain & Media Transport** – configure Auto vs Bundled-only vs Remote-only download behavior and whether proposal exports copy attachments into deterministic bundle folders.
- **Media Bundling Controls** – regenerate bundles, clear caches, and inspect bundle metrics.
- **Resolver Rule Management** – manage stored resolver decisions (`dbvc_resolver_decisions`), export/import CSVs, or prune stale entries.
- **Auto-clear Decisions** – automatically purge `dbvc_proposal_decisions` once an apply finishes so future proposals start clean.

## Data & Storage

- `wp_dbvc_snapshots`, `wp_dbvc_snapshot_items`, `wp_dbvc_jobs`, `wp_dbvc_media_index`, and `wp_dbvc_activity_log` provide durable history for exports/imports.
- Proposal decisions live in `dbvc_proposal_decisions` until applied/cleared. Resolver rules live in `dbvc_resolver_decisions`.
- Proposal zips and media bundles are stored in `wp-content/uploads/dbvc/{proposals,media-bundles,...}`. The cleanup tooling trims empty proposals to keep disk usage predictable.

## Troubleshooting & Logs

- **Activity log** (`wp_dbvc_activity_log`) captures every export/import/apply event with structured context.
- **File log** (`dbvc-backup.log`) mirrors high-level notices for deployments that prefer file-based monitoring.
- **Resolver warnings** – The React UI surfaces unresolved/blocked counts. See the proposal drawer for conflict reasons and recommended actions.
- **Permissions** – If proposals fail to upload ensure PHP has write access to `uploads/dbvc/proposals/` and that ZipArchive is available.
- **WP-CLI** – Use `--debug` to inspect chunking or diff baseline calculations; exported snapshot IDs are printed for traceability.
- **Legacy proposals** – Term snapshots ship in 1.3.4+. Re-upload older proposal zips (or call `DBVC_Snapshot_Manager::capture_for_proposal()` for each proposal ID) so reopened reviews compare taxonomy changes against the live site instead of treating everything as new.

## WP-CLI Usage

- `wp dbvc export` / `wp dbvc import` keep the legacy JSON sync flows for automation.
- `wp dbvc proposals list` prints proposal IDs, status, hashes, duplicate counts, resolver pending counts, and new-entity pending totals (add `--fail-on-pending` to exit with an error when anything still needs attention, `--recapture-snapshots` to regenerate snapshots for legacy proposals, or `--cleanup-duplicates` to run the manifest dedupe routine).
- `wp dbvc proposals upload path/to/proposal.zip [--id=<custom>] [--overwrite]` ingests a proposal ZIP, mirrors media bundles, and captures snapshots without visiting WP Admin.
- `wp dbvc proposals apply <proposal_id> [--mode=partial] [--ignore-missing-hash] [--force-reapply-new-posts]` reuses the React workflow’s importer so CI/staging can apply reviewed bundles.
- `wp dbvc resolver-rules list|add|delete|import` lets you manage global resolver rules from the terminal, mirroring the React UI’s CSV and inline editors.

## UI → CLI Tutorial Notes

- **Review queue:** Everything you can do in the DBVC Export React screen (list proposals, inspect counts, recapture snapshots, dedupe manifests) now has a CLI counterpart through `wp dbvc proposals list` flags.
- **Uploading & applying:** The UI’s “Upload proposal” and “Apply selections” drawer directly map to `wp dbvc proposals upload` and `wp dbvc proposals apply`, so deployment docs can link to these commands when describing the UI workflow.
- **Resolver rules:** The Configure → Media Handling UI references the `dbvc_resolver_decisions` store; add the `wp dbvc resolver-rules …` snippets to help reviewers follow along when they prefer terminal tutorials.
- Whenever UI docs mention a button (e.g., “Cleanup duplicates” or “Recapture snapshots”), include the equivalent CLI snippet above so runbooks can embed both approaches side-by-side.

## Roadmap

- **Snapshot polish** – add helper commands to recapture legacy proposals so term snapshots exist everywhere without manual uploads.
- **Official collections** – curated “official” bundles that can be re-exported on demand remain on the backlog.

This repository includes in-depth implementation notes under `handoff.md`, progress tracking inside `docs/progress-summary.md`, and media transport design details inside `docs/media-sync-design.md`.
