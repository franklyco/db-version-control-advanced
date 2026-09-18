# Deferred phase — Brand Control Center · admin options (permanent curation surface)

**Status:** deferred, not scheduled. Records intent + context so a future planner does not rediscover it.

**Recorded:** 2026-08-27 alongside the maintainer's bulk approval of the recommender's `include` set (see E-093 in `../tracking/EVIDENCE-LOG.md`).

**Companion decisions:** [D-059](../tracking/DECISION-LOG.md) (curation-tool policy — temporary kill-switch) is superseded in spirit by this deferred phase but stays authoritative until this phase is scheduled and lands.

---

## The idea

The R3-BX curation tool was originally scoped as a **temporary, kill-switch-gated admin surface** that admins would run once to seed the future Vertical control provider, then turn off (D-059). During curation the maintainer identified a stronger long-term product opportunity: keep the tool as a **permanent admin governance surface** for the Visual Editor.

Rename direction for the promoted tool: **"Brand Control Center · admin options"** (parallel to "Brand Control Center" which is the frontend user surface).

## What "admin options" would let admins do

Beyond the current include/ignore/defer + category + priority + notes:

- **Granular per-field lock/unlock at the admin level.** Even after a field is `include` in the curated seed, an admin can toggle whether it's actually surfaced in the frontend Brand Control Center for a given site or a given role. Useful when a client shouldn't be able to touch (say) `mission_statement` even though it's a supported field.
- **Per-role visibility.** Different WordPress roles could see different subsets of the drawer. Editor sees brand content + colors; contributor sees only their scoped fields; administrator sees everything.
- **Site-level overrides.** A Vertical child site (Flourish, dental practice X) might expose or hide fields relative to the seed. The seed stays authoritative; overrides layer on top.
- **Change window annotations.** Admin can attach "reserved for launch redesign" / "frozen during rebrand" notes that surface in the drawer as inspect-only reasons.
- **Approve/reject workflow.** For agencies with review processes: proposed brand-control changes flow through approval before the drawer picks them up. Overkill for solo owners; possibly valuable for larger clients.
- **Audit read view.** "Who last changed which brand control, and when" — reusing the existing `ChangeJournalRecorder` reads.
- **Curation refresh.** Re-scan ACF field groups + let the admin approve newly-appeared fields since the last curation run (Vertical schema evolves; the drawer should not drift).

## Current state vs. target state

| Aspect | Current (R3-BX temporary) | Target (deferred admin-options phase) |
|---|---|---|
| Menu label | "BCC Curation" | "Brand Control Center · admin options" |
| Kill switch | `dbvc_visual_editor_curation_tool_enabled`, default off, meant to be turned off once curated | Always available to admins; may still have a kill switch but default on when the master Visual Editor switch is on |
| Location | Settings → Visual Editor → BCC Curation (temporary submenu) | Same admin location, promoted to permanent |
| Persistence | `dbvc_visual_editor_curation_decisions` option | Same option OR a promoted schema (see migration story below) |
| Export | Committable JSON to `addons/visual-editor/curation/vertical-approved-controls.json` (one-shot seed) | Still exports the seed — but decisions become live governance, not just seed data. Export becomes an audit snapshot. |
| Scope | Options-page ACF fields only | Options-page fields today; a future promotion could open post-owned / term-owned / user-owned governance too — but that is its own deferred question, not part of this phase |
| Consumers | Vertical provider seed (future) | Vertical provider (still), plus the runtime drawer directly consumes the store for per-site/per-role visibility gating |
| Test surface | 22 focused PHPUnit tests | Expanded — role/site-override behavior gets its own tests |
| Rename effort | — | Rename `CurationPage` → `BrandControlCenterAdminPage` (or similar); rename the option key with a migration (see below) |

## What promotion entails

Concretely, the promotion is more than a rename. Rough scoping (not a commitment):

1. **Rename** — Class + admin menu + option key + doc slug all move from "curation" to "admin options" / "governance." Keep a one-run migration for the option key (`update_option($new_key, get_option($old_key)); delete_option($old_key);`) so decisions carry forward.
2. **Kill-switch policy** — flip default from off → on for the admin surface (or drop the kill switch entirely and rely on the Visual Editor master switch + capability). Governance is a permanent admin tool, not a one-shot utility.
3. **Runtime consumption** — the R3-C production drawer already reads from the registry; the promoted admin-options store becomes a filter layer between the registry and the drawer's list response (a field marked "locked" here is excluded from the drawer's `items[]` for that user/site/role even if the underlying registry has it).
4. **Per-role visibility** — extend the decision schema with a `visible_to_roles` array. Default = all Visual-Editor-capable roles.
5. **Per-site overrides (multi-site / Vertical child sites)** — extend the schema with a `site_overrides` map keyed by site slug or blog id. Requires a policy decision on what the maintainer wants (site A can extend the seed vs. site A can only restrict from the seed).
6. **Audit read** — new admin panel (or add to admin-options table) showing recent brand-control changes from `ChangeJournalRecorder`. No new mutation authority; pure read.
7. **Curation refresh workflow** — a "Detect new fields" action that re-runs `FieldCandidateProvider::getCandidates()`, diffs against the store, and surfaces newly-discovered fields for approval. Existing decisions untouched.
8. **Tests** — expand the 22-test suite to cover role/site overrides + refresh diff.
9. **Docs** — the curation-tool README + release-doc R3-BX section gets a "Promoted to permanent admin governance" section; the deferred-phase doc (this one) gets closed out.

## Migration story

- The existing `dbvc_visual_editor_curation_decisions` option becomes the promoted governance store (either kept in-place or renamed with a one-run WP option migration). Every decision the maintainer has made (currently 770 records — 400 include / 370 defer) carries forward.
- The exported JSON at `addons/visual-editor/curation/vertical-approved-controls.json` remains the Vertical provider seed. On promotion, that JSON becomes an "audit snapshot" — still exportable, still committable, but no longer the sole downstream consumer.
- The kill switch (`dbvc_visual_editor_curation_tool_enabled`) either changes default (off → on) or is retired in favor of the master Visual Editor switch. Decision at promotion time.
- Zero data loss under any migration path — decisions are captured in the option, JSON is captured in the repo.

## Explicit non-goals for the CURRENT curation-tool phase

To keep R3-BX shipped and not scope-creep this deferred work in, the following are **explicitly NOT built now**:

- Per-role visibility rules
- Per-site overrides
- Site-cluster / multi-site behavior
- Approval workflow
- Audit read view (the existing `ChangeJournalRecorder` still writes; no admin view of it as part of this tool)
- Curation refresh workflow (today's tool always re-scans from live ACF on every render; a diff surface is deferred)
- Any rename of the admin menu or class
- Any change to the option key or schema

The current R3-BX tool stays as-is until this phase is explicitly scheduled and gated.

## Cross-references

- Current implementation: `addons/visual-editor/src/Admin/CurationPage.php` + `addons/visual-editor/src/Curation/*.php`
- Current release doc: `../releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md` (§R3-BX + follow-ups)
- Current adoption inventory (reusable infrastructure): `../knowledge/EXISTING-SUPPORT-INVENTORY.md`
- Superseded when promotion lands: [D-059](../tracking/DECISION-LOG.md) (temporary-tool policy)
- New decision on this deferral: D-062 (see DECISION-LOG.md; recorded 2026-08-27)
- Evidence of the maintainer's approve-all-recommender-includes bulk apply that surfaced this direction: E-093 in `../tracking/EVIDENCE-LOG.md`

## Deferral status

- **Deferred:** yes, indefinitely — no scheduled slice, no committed timing.
- **Reason:** the current R3-BX tool is sufficient to seed the Vertical control provider and unblock R3-C + R5.x. The admin-options promotion is a product opportunity, not a blocker.
- **Trigger to revisit:** any of these makes it worth scheduling —
  1. R3-C + R5.x land and real client-facing use surfaces demand for per-role/per-site scoping.
  2. Vertical adds a second client with different brand-control needs than the reference site.
  3. An admin requests "I need to lock this field mid-project without editing the JSON."
  4. Multi-site / Vertical child-site rollout begins.
- **When it is scheduled:** promote to a numbered release (likely alongside or after R6 Site Manager Workspace, since both are admin/governance surfaces) with its own release-doc under `../releases/`.

## What to remember when picking this up

- The existing option shape (`dbvc_visual_editor_curation_decisions`) is a natural fit; extending it in-place is easier than migrating to a new schema. Add fields, don't rename keys.
- The recommender's keyword rules (`FieldCurationRecommender`) are useful for *initial suggestions* only. The admin-options tool should let admins override them silently.
- The class names use "Curation" as the noun today. On promotion, either accept the legacy naming or migrate with class aliases; do NOT do a big-bang rename that breaks the existing test suite mid-flight.
- The kill-switch pattern from R3-BX is reusable for the promoted tool if a soft-disable is still wanted (matches the pattern documented in `../knowledge/EXISTING-SUPPORT-INVENTORY.md` §2.5).
- The recommender's `unlocks_at` mapping (which R5 slice serves a given field family) stays useful — admins can be shown "this field will unlock in R5.2" as guidance when deciding whether to expose it now.
