# DBVC_BRICKS_ADDON_HANDOFF.md
_Last updated: 2026-02-12 (America/New_York)_

## Context + Decision
We are proceeding with a centralized architecture:

**DBVC will own the full “Golden Master + Drift Detection + Governance + Proposal Pipeline” system** for Bricks assets as a first-class **DBVC Add-on** module.

UI + configuration will live in DBVC under:
- **DBVC → Configure → General Settings → Add-ons → Bricks**

We previously ran a Discovery kickoff and Codex has already generated discovery documents. This handoff updates the plan:
- Codex must **update the existing discovery docs** to reflect this new DBVC-centric architecture.
- Any VF integration becomes optional/thin and is not required for the initial build.

**Terminology note:** In DBVC, posts/terms are referred to as **Entities**. Use that language in code, docs, and UI where applicable.

---

## Goal
Ship a DBVC Add-on called **Bricks** that:
1) Maintains a **Golden Master** (versioned Bricks “packages”) on a designated Mothership site.
2) Detects **drift** between a ClientSite’s local Bricks assets and the last applied golden version.
3) Provides **governance policies** (auto-accept/manual accept/always override/request review/ignore).
4) Enables **proposal pipeline**: ClientSite changes can be submitted to Mothership, queued for review, and approved into the next golden release.
5) Provides a safe **apply + rollback** workflow using DBVC’s artifact engine primitives.

We start with a new **Discovery-Update pass** (no implementation), then proceed to MVP.

---

## Bricks Asset Types (Managed Artifacts)
Treat each of the following as a managed artifact type (expandable over time):

**MVP (first iteration)**
- `bricks_template` (CPT posts) — Entity-backed
- `bricks_global_classes` (options-based)
- `bricks_global_variables` (options-based)

**Later**
- global-colors
- components
- theme-styles
- template_tag
- template_bundle
- any other Bricks global settings keys discovered in the environment

> During discovery-update, Codex must confirm the exact storage locations and option keys used by the installed Bricks version(s) in your environment.

---

## DBVC Add-on Module Requirements

### 1) Add-on Registration + Structure
Implement as a DBVC Add-on module. Proposed structure (adapt to existing DBVC conventions):

- `addons/bricks/`
  - `bricks-addon.php` (bootstrap + registration)
  - `admin/`
    - `class-bricks-settings-page.php`
    - `class-bricks-ui-status.php`
    - `class-bricks-ui-diff.php`
    - `class-bricks-ui-proposals.php`
  - `engine/`
    - `class-bricks-artifact-adapter.php` (bridges DBVC engine + Bricks storage)
    - `class-bricks-canonicalizer.php`
    - `class-bricks-drift-scanner.php`
    - `class-bricks-policy.php`
    - `class-bricks-package-source.php`
    - `class-bricks-proposal-client.php` (ClientSite → Mothership)
    - `class-bricks-proposal-server.php` (Mothership endpoints + review queue)
  - `storage/`
    - `class-bricks-db.php` (tables/migrations for proposals/status if not already in DBVC core)
  - `docs/`
    - `BRICKS_ADDON_OVERVIEW.md`
    - `BRICKS_ADDON_OPERATIONS.md`

**Constraint:** Do not create a parallel artifact engine in the add-on. Reuse DBVC’s existing hashing/canonicalization/packaging/history mechanisms.

---

### 2) UI Location + Tabs
Under **DBVC → Configure → General Settings → Add-ons → Bricks**, add a sub-navigation with tabs:

1) **Connection**
- Mothership base URL
- Site role: `Mothership` | `ClientSite`
- Auth method (initially HMAC token or API key)
- “Test connection” button (capability handshake)
- Read-only mode toggle (pull only, disable proposals)

2) **Golden Source**
- Source mode: `Bundled (optional)` | `Mothership API` | `Pinned Version`
- Mothership storage configuration:
  - Storage strategy: DB + uploads folder
  - Base folder name/path (within WP uploads or locked-down wp-content/private)
  - Retention: keep last N packages
- Signature verification (recommended)

3) **Policies**
- Defaults per artifact type
- Per-asset override (by asset UID)
- Auto-apply schedule (optional) and guardrails (maintenance window)

4) **Operations**
- “Scan for Drift” (manual)
- View drift summary (clean/diverged/overridden/pending review)
- View incoming golden package (select version)
- Diff viewer + “Apply selected” + “Create restore point”
- Rollback chooser (restore points list)

5) **Proposals**
- ClientSite: list diverged assets + “Submit proposal”
- Mothership: review queue (pending/approved/rejected/needs changes) + approve action
- Proposal status syncing back to ClientSites (optional in MVP; can be manual refresh)

---

## Data + Storage Requirements (DBVC-centric)
Prefer to reuse DBVC’s existing tables for:
- artifact registry
- history
- packages/restore points

Add-on-specific tables should be minimal, only if DBVC core does not already cover them.

### Required “States”
Maintain per-asset status:
- `CLEAN` (matches last applied golden hash)
- `DIVERGED` (local differs)
- `OVERRIDDEN` (policy always local master)
- `PENDING_REVIEW` (proposal submitted awaiting mothership decision)

### Proposal records
Proposals must capture:
- `proposal_id` (uuid)
- `asset_uid`, `asset_type`
- `base_golden_version`, `base_hash`
- `proposed_hash`
- `canonical_payload` (inline JSON or file reference in a package)
- `diff_summary`
- `notes/tags`
- status lifecycle: `DRAFT → SUBMITTED → RECEIVED → APPROVED|REJECTED|NEEDS_CHANGES`

---

## Core Flows (DBVC Add-on)

### Flow 1 — Mothership: Publish Golden Package
1) Export all managed Bricks artifacts using DBVC engine primitives
2) Canonicalize + fingerprint each artifact
3) Build a package manifest:
   - version (SemVer)
   - list assets with uid/type/hash + payload reference
4) Store package in configured mothership storage
5) Mark as “latest approved” for downstream consumption

**Output:** Golden package `vX.Y.Z`

---

### Flow 2 — ClientSite: Drift Scan
1) Fetch golden package headers/manifest (or use pinned/bundled)
2) Export local artifacts; canonicalize + fingerprint
3) Compare local hash to:
   - last applied golden hash (and/or incoming golden hash)
4) Update per-asset statuses and produce a drift summary

**Output:** clean/diverged/overridden/pending review list + diff availability

---

### Flow 3 — ClientSite: Apply Golden (Manual/Auto)
1) Preflight:
   - Validate package signature (if enabled)
   - Create restore-point package of current local state
2) Determine apply selection based on policy:
   - AUTO_ACCEPT includes automatically
   - REQUIRE_MANUAL_ACCEPT requires admin approval in UI
   - ALWAYS_OVERRIDE skips
   - REQUEST_REVIEW skips + flags
   - IGNORE skips
3) Apply in deterministic order:
   - option/global artifacts first
   - Entity-backed templates last
4) Verify resulting hashes match expected golden hashes
5) Commit status updates; else rollback using restore point

---

### Flow 4 — ClientSite: Submit Proposal Upstream
Trigger: Admin selects a diverged asset and clicks **Submit Proposal**.

1) Capture:
   - base golden version/hash
   - proposed canonical payload/hash
   - diff summary
   - notes/tags
2) Send to mothership endpoint:
   - `POST /dbvc/v1/bricks/proposals`
3) Mothership stores proposal as `PENDING`
4) ClientSite marks asset as `PENDING_REVIEW` (optional in MVP)

---

### Flow 5 — Mothership: Review Proposal → Approve → New Golden Release
1) Admin reviews proposal diff and payload
2) Decision:
   - APPROVE: incorporate into next golden build
   - REJECT / NEEDS_CHANGES: record notes
3) On approve:
   - update working set
   - publish new golden package version

**Optional:** notify client site of status change on next sync.

---

## Discovery-Update Requirements (Next Step)
Codex must now do a short “Discovery Update” pass based on this new plan:
- Update existing discovery docs to reflect **DBVC as the UI + governance home** (Add-on module)
- Identify DBVC’s existing patterns for:
  - Add-on registration and UI tabs under Configure
  - Settings storage patterns and sanitization
  - Existing artifact engine methods that can be reused directly
  - Any existing “Entities” abstraction to use for `bricks_template` posts
- Identify what new tables (if any) are truly needed vs reuse DBVC core

**Deliverables (update, do not implement yet):**
- Update `docs/BRICKS_ASSETS_DISCOVERY_REPORT.md`
- Update `docs/BRICKS_ASSETS_ENGINE_CONTRACT_DRAFT.md` (or create if absent)
- Add `addons/bricks/docs/BRICKS_ADDON_PLAN.md` summarizing:
  - module structure
  - required settings + UI tabs
  - minimal MVP scope
  - API endpoints for proposals + package fetch

---

## MVP Boundary (After Discovery Update)
Build MVP for:
- Drift scan for `bricks_template`, `bricks_global_classes`, `bricks_global_variables`
- Manual diff view (canonical JSON diff + summary)
- Restore-point + apply selected assets
- Proposal submission endpoint on mothership + client submission UI (review UI can be simple list in MVP)

---

## Notes / Non-Goals for MVP
- Do not attempt per-template node-level governance in MVP.
- Do not attempt full semantic diffing of Bricks template element trees in MVP (start with canonical JSON diff).
- Do not rely on server-level config (Nginx/Apache). Use WordPress-safe storage.

---

**END OF HANDOFF**
