# BRICKS_ASSETS_HANDOFF.md
_Last updated: 2026-02-12 (America/New_York)_

## Purpose
This handoff defines the **requirements, workflows, data contracts, and discovery tasks** for implementing a centralized, versioned “source of truth” for the **Bricks Builder layer** across many client sites.

The goal is to eliminate manual, site-by-site diffing of Bricks exports by introducing:
- **Golden/Master Bricks Packages** (versioned artifacts)
- **Drift detection** (site vs last-applied golden)
- **Governance & policy** (auto-accept / manual accept / local override / request review)
- **Upstream proposals** (ClientSite changes can be submitted to Mothership for approval)

This work spans **two repos**:
- **DBVC**: generic artifact engine (hashing, canonicalization, packaging, apply/rollback, history)
- **VerticalFramework (VF)**: governance layer + UI + policy + mothership sync client

We will begin with a **Discovery Phase**. Codex must first analyze the latest repo code and report findings before implementation begins.

---

## Key Terms
- **Mothership**: the designated central WordPress install (or service) that stores approved “golden” Bricks packages and receives proposals from configured client sites.
- **ClientSite**: any customer website running VerticalFramework and (optionally) DBVC.
- **Bricks Assets**: Bricks templates + global design system/state that we treat as versioned artifacts:
  - `bricks_template` (CPT posts)
  - `global-classes`
  - `global-variables`
  - `global-colors`
  - `components`
  - `theme-styles`
  - `template_tag`
  - `template_bundle`
- **Golden Package**: an approved, versioned snapshot of Bricks assets (a “release”) distributed downstream.
- **Drift**: when ClientSite assets differ from the golden version last applied.
- **Proposal**: a ClientSite-submitted change set (like a pull request) for review/approval by Mothership.

---

## North Star Outcome
A developer can go to a ClientSite admin panel and:
1) See which Bricks assets match golden vs have drift (clean/diverged/overridden/pending).
2) Pull the latest (or pinned) golden package.
3) Review diffs for each asset (and later, optionally, per-template element/node).
4) Apply selected assets safely with restore-point + rollback.
5) Mark specific assets as **local master** (override) so golden doesn’t overwrite.
6) Submit local improvements to the Mothership for review; Mothership can approve and publish a new golden release.

---

## Discovery Phase (DO THIS FIRST)
### Codex instructions
Before implementing anything, Codex must:
1) **Review the latest VerticalFramework repo** to understand:
   - Existing feature loading system and Flourish Settings UI patterns
   - Existing logging helper(s) and debug mode conventions
   - Existing DB tables VF creates (if any), migrations patterns
   - Existing `vf_object_uid` usage patterns (generation, persistence, and where stored)
2) **Review the latest DBVC repo** to understand:
   - Current hashing strategy (what is hashed; canonicalization steps if any)
   - Export/import architecture (how artifacts are serialized)
   - Any existing UID system or mapping strategy (including `vf_object_uid` interplay)
   - Any existing “history”, “restore point”, or “diff” mechanisms
3) Produce a written **Discovery Report** (markdown) covering:
   - Where and how `vf_object_uid` is currently generated and stored
   - How DBVC currently computes hashes (including what fields are excluded/included)
   - Which Bricks data types are already supported by DBVC (if any)
   - Recommended “minimum viable” integration surface between VF and DBVC
   - Identified risks: noisy diffs, non-deterministic ordering, Bricks internal IDs, performance considerations

**Deliverable (Discovery):**
- `BRICKS_ASSETS_DISCOVERY_REPORT.md` in each repo (or a shared one in VF referencing DBVC findings)

> Implementation should not begin until this discovery report is produced and reviewed.

---

## System Architecture Overview
### Separation of concerns
**DBVC** should be the **artifact engine**:
- Canonicalize → fingerprint (hash) → diff → package → apply → rollback → history/audit
- Provide stable interfaces so VF can call it.

**VerticalFramework (VF)** should be the **governance layer**:
- UI + policy decisions + drift dashboard
- Choose package source (bundled vs mothership API vs pinned)
- Submit proposals upstream
- Store governance status fields (clean/diverged/overridden/pending review)
- Call DBVC engine methods to compute hashes/diffs/apply packages

### Package sources
ClientSites should support:
1) **Bundled**: golden package shipped with VF releases (safe fallback)
2) **Mothership API**: pull latest approved golden package over authenticated API
3) **Pinned**: site pins a specific golden version (no auto upgrades)

---

## Data Model Requirements
### Stable Asset Identity
Every managed asset must have a stable identity:
- **Asset UID**: prefer `vf_object_uid` if it already exists and is reliable.
- If an asset lacks UID in its native storage, VF/DBVC must maintain a mapping table.

**Asset UID must remain stable across:**
- Export/import
- Site-to-site distribution
- Upgrades and re-imports

### Suggested Tables (ClientSite)
(Names/prefixes can be adjusted to match existing conventions)

1) `wp_vf_bricks_assets_registry`
- `id`
- `asset_uid` (string GUID)
- `asset_type` (enum/string: templates, global-classes, etc.)
- `wp_object_id` (post ID for `bricks_template`; null for options)
- `storage_key` (option name or other locator)
- `current_hash`
- `last_golden_hash_applied`
- `last_golden_version_applied`
- `status` (CLEAN | DIVERGED | OVERRIDDEN | PENDING_REVIEW)
- `policy` (AUTO_ACCEPT | REQUIRE_MANUAL_ACCEPT | ALWAYS_OVERRIDE | REQUEST_REVIEW | IGNORE)
- `updated_at`

2) `wp_vf_bricks_changes`
- `id`
- `asset_uid`
- `asset_type`
- `from_hash`
- `to_hash`
- `diff_summary` (json text)
- `payload_before` (optional)
- `payload_after` (optional)
- `change_source` (manual_edit | golden_apply | import | unknown)
- `changed_by_user_id` (nullable)
- `created_at`

3) `wp_vf_bricks_proposals`
- `id`
- `proposal_id` (uuid)
- `asset_uid`
- `asset_type`
- `base_golden_version`
- `base_hash` (hash that proposal is based on)
- `proposed_hash`
- `proposal_payload` (canonical payload or path/ref)
- `diff_summary`
- `status` (DRAFT | SUBMITTED | RECEIVED | APPROVED | REJECTED | NEEDS_CHANGES)
- `submitted_at`
- `reviewed_at`
- `review_notes`

> DBVC may already have equivalent tables for artifacts/history. If so, VF should **reuse DBVC tables** where appropriate, and only add VF-specific governance tables if necessary.

---

## Bricks Asset Extraction / Storage Targets
### Templates (`bricks_template` CPT)
- Stored as WP posts; include relevant postmeta that Bricks uses.
- Export payload must include:
  - post fields (title, slug, status if needed)
  - Bricks content JSON/meta
  - any VF/DBVC fields like `vf_object_uid` (if stored in postmeta)

### Global / Options-based assets
Bricks stores some “global” entities in WP options. Codex must confirm exact option keys during discovery.
Known examples from Bricks community:
- Global classes: `bricks_global_classes`
- Global variables: `bricks_global_variables`
(Other options likely exist for theme styles, palette, etc.)

**Requirement:** Export must canonicalize option payload to stable ordering and exclude site-specific noise.

---

## Canonicalization + Hashing Requirements
To avoid noisy diffs:
- Recursively sort object keys
- Normalize numeric/string formatting
- Remove known transient/noise keys (timestamps, cache keys, generated IDs if not stable)
- Ensure arrays representing “sets” are sorted deterministically (by UID or name)

**Fingerprint (hash):**
- `sha256(json_encode(canonical_payload))` (or existing DBVC approach if already standardized)

Codex must adapt to DBVC’s existing hashing engine if it already exists.

---

## Governance Policies (Required Behaviors)
Each asset (and later optionally, template nodes) can be governed by a policy:

- **AUTO_ACCEPT**: golden updates automatically apply if drift exists.
- **REQUIRE_MANUAL_ACCEPT**: show diff + require human approval before applying.
- **ALWAYS_OVERRIDE**: local site is authoritative; golden never overwrites.
- **REQUEST_REVIEW**: do not apply golden and flag for mothership review.
- **IGNORE**: do not manage this asset type (for early rollouts).

Policy precedence:
1) Per-asset override
2) Per-asset-type default
3) Global default

---

## Core Flows (Detailed)
### Flow A — Publish Golden Package (Mothership)
**Actors:** Mothership Admin (internal team)

**Trigger:** On-demand or scheduled “Publish” action

**Steps:**
1) Mothership exports all managed Bricks assets via DBVC engine:
   - For each asset type: extract → canonicalize → hash
2) Build package manifest:
   - `version` (SemVer)
   - `assets[]` with UID/type/name/hash + payload path
3) Sign package (optional but recommended):
   - HMAC or asymmetric signature over manifest + payload hashes
4) Store package in mothership storage:
   - DB (package metadata) + filesystem (payloads)
5) Mark as “latest approved” (or keep multiple channels: stable/beta)

**Outputs:**
- Golden package `vX.Y.Z` available for client pull
- Changelog entry

---

### Flow B — ClientSite Pull + Scan (Drift Detection)
**Actors:** ClientSite Admin or automated cron

**Steps:**
1) Determine golden source:
   - bundled vs mothership API vs pinned
2) Fetch/locate selected golden package
3) For each managed asset:
   - Export local payload → canonicalize → hash → compare to:
     - `last_golden_hash_applied` (what site last accepted)
     - and/or incoming golden hash (what’s available now)
4) Update registry statuses:
   - CLEAN if local == last_golden_hash_applied
   - DIVERGED if local != last_golden_hash_applied
   - OVERRIDDEN if policy ALWAYS_OVERRIDE
   - PENDING_REVIEW if proposal submitted and awaiting mothership

**Outputs:**
- Dashboard view: clean/diverged/overridden/pending counts
- Diff availability for diverged assets

---

### Flow C — ClientSite Apply Golden (Manual or Auto)
**Actors:** ClientSite Admin (manual) or system (auto)

**Preflight:**
- Always create a restore-point package of the current local state (DBVC backup).

**Steps:**
1) Select incoming golden version (or auto latest)
2) Determine action per asset based on policy:
   - AUTO_ACCEPT → include in apply list
   - REQUIRE_MANUAL_ACCEPT → show diff; include only if approved
   - ALWAYS_OVERRIDE → skip
   - REQUEST_REVIEW → skip + flag
   - IGNORE → skip
3) Apply in deterministic order:
   - global settings/options first
   - then templates/posts last
4) Verify applied hashes match golden package hashes
5) Commit + update registry:
   - `last_golden_version_applied`
   - `last_golden_hash_applied`
   - set status CLEAN
6) On failure:
   - rollback using restore-point

**Outputs:**
- Site upgraded to golden version (fully or partially)
- Audit log and history entries

---

### Flow D — ClientSite Local Edits → Proposal Upstream (Push to Mothership)
**Example:** A ClientSite modifies a template or global class and wants it considered for the next golden version.

**Triggers:**
- Manual “Submit proposal” action in VF UI
- Optional auto-detection: when drift found, user can “propose this change”

**Steps:**
1) Identify asset(s) to propose:
   - templates: specific `asset_uid`
   - globals: specific option artifact
2) Capture proposal payload:
   - base golden version + base hash
   - local canonical payload + proposed hash
   - diff summary
   - optional notes/tags (bugfix/a11y/perf/conversion)
3) Create proposal record on ClientSite
4) Send to mothership via authenticated API:
   - `POST /vf-bricks/proposals`
   - payload includes site identity, proposal ID, asset UID/type, base version/hash, proposed hash, canonical payload, diff summary, notes
5) Mothership stores proposal as PENDING:
   - does NOT auto-merge
   - flags internal review queue
6) ClientSite marks proposal status:
   - SUBMITTED → RECEIVED (when mothership acknowledges)

**Outputs:**
- Proposal appears on mothership review screen
- ClientSite is flagged PENDING_REVIEW for that asset (optional)

---

### Flow E — Mothership Review + Approve Proposal → New Golden Release
**Actors:** Mothership Admin

**Steps:**
1) Review proposal metadata and diff summary
2) Optionally test apply proposal to staging clone (recommended)
3) Decision:
   - APPROVE → incorporate into next golden package build
   - REJECT → record review notes
   - NEEDS_CHANGES → request revision and keep open
4) If approved:
   - update mothership working set of assets
   - publish new golden version (vX.Y.Z+1)
5) Notify client (optional):
   - client sees proposal status APPROVED/REJECTED
   - if approved, client can pull new golden

**Outputs:**
- New golden package version published
- Audit trail from proposal → release notes

---

## API & Security Requirements
### Authentication
- Use per-site keys or tokens (aligned with your existing “authorized sites” system).
- Support “read-only client” mode (can pull golden but cannot push proposals).

### Package integrity
- Packages should be signed (recommended).
- ClientSite verifies signature before applying.

### Rate limiting & scheduling
- ClientSites should not spam mothership; implement:
  - max proposals per day
  - scheduled sync windows
  - backoff on failure

---

## UI Requirements (VerticalFramework)
Create admin area:
**Flourish Settings → Bricks Framework**
Tabs:
1) **Status**
   - Current golden version
   - Drift summary
   - Scan button
2) **Incoming Updates**
   - List assets that differ from incoming golden
   - Policy dropdown + selection
   - View diff
   - Apply selected
3) **Diff Viewer**
   - Start with canonical JSON diff + summary
4) **Outbound Proposals**
   - Show diverged assets
   - Submit proposal + notes
   - Proposal status

---

## Implementation Constraints
- **Do not break existing Bricks content.** Always create restore-points prior to applying.
- **Determinism matters:** canonicalization must produce stable hashes across runs.
- **Performance:** scanning should be incremental where possible; avoid full exports on every admin page load.
- **Compatibility:** do not assume Nginx/Apache config; use WordPress-safe storage patterns.
- **Extensibility:** treat each Bricks asset type as a pluggable artifact type, not hard-coded logic.

---

## Initial MVP (Post-Discovery) Suggested Boundaries
To reduce scope, the first implementation iteration should focus on:
1) `bricks_template` (CPT) — baseline export/hash/diff/apply
2) `bricks_global_classes` — option artifact
3) `bricks_global_variables` — option artifact

Then expand to remaining types after stability is proven.

---

## What Codex Should Output After Discovery
Codex must produce, at minimum:
1) A written discovery report (as noted)
2) A proposed integration plan that maps:
   - which classes/functions in DBVC will be reused
   - which VF feature module will host the UI
   - which DB tables already exist vs need to be added
3) A list of known Bricks storage keys in this environment:
   - exact WP option names and where templates store their JSON/meta
4) A risk matrix:
   - noisy diffs
   - unstable IDs
   - backwards compatibility on older client sites
   - how to handle partial apply where only some assets are updated

---

## Notes for Codex (Repo Review)
- **DBVC:** Find and document the existing hashing/canonicalization pipeline and how artifacts are represented.
- **VerticalFramework:** Find and document how `vf_object_uid` is generated/used and how feature modules are organized.
- Do not assume the architecture described here replaces existing systems; it must integrate cleanly with what already exists.

---

## Appendix: Minimal “Contracts” to Align VF + DBVC
Codex should propose (or adapt existing) callable methods such as:

### DBVC (engine)
- `dbvc_export_artifact($type, $filters = []): array`
- `dbvc_canonicalize($payload): array`
- `dbvc_fingerprint($canonical): string`
- `dbvc_diff($canonicalA, $canonicalB): array`
- `dbvc_build_package($artifact_list, $meta): Package`
- `dbvc_apply_package($package, $selection, $mode): Result`
- `dbvc_create_restore_point($scope): Package`

### VF (governance)
- `vf_bricks_scan_status(): void`
- `vf_bricks_get_incoming_updates($packageVersion): array`
- `vf_bricks_apply_selected($selection): Result`
- `vf_bricks_submit_proposal($asset_uid): Result`
- `vf_bricks_fetch_golden_package($source, $version = null): Package`

---

**END OF HANDOFF**
