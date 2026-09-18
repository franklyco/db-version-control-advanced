# Codex status-update handoff - R1-D static mockup delivered

> **SUPERSEDED IN PART, 2026-08-16.** Codex has completed its review, issued decisions D-030 to
> D-032 and evidence E-040, updated the package to 2.4.2, and authorized the D4A correction
> checkpoint. The status-document edits described below have been overtaken by Codex's own
> updates. **The five "open items" in section 8 are all resolved** - see `DESIGN-DECISIONS.md`
> section 3 for the rulings. Retained for provenance only; do not action section 9 as written.

Instructions for Codex to update DBVC status documents now that the R1-D Claude static mockup is
complete. Written by Claude Code at the user's request.

> **Note on this file.** It is not one of the outputs listed in the R1-D capsule's output
> contract, and it is not a specification. It exists so a Codex agent thread can apply the status
> updates without re-deriving them. It holds no authority over the capsule, the roadmap, or the
> tracker. Delete it once the updates land.

**Section 9 is a paste-ready prompt.** Everything before it is the evidence behind it.

---

## 1. Which repository, and which file

**Repository:** there is exactly one git repository involved.

```
/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main
```

The surrounding Local WP install (`dbvc-codexchanges/`) is **not** a git repository - `git
rev-parse` inside it fails. So every path below is relative to the plugin repository root, and
"the roadmap" is unambiguous.

**The roadmap file:** repository `AGENTS.md` says `docs/roadmap.md`; the R1-D mockup documents say
`docs/ROADMAP.md`. **These are the same file.**

| Check | Result |
|---|---|
| `git ls-files` name | `docs/ROADMAP.md` |
| On-disk directory entry | `roadmap.md` |
| Inode of both spellings | `190946516` - identical |
| `git config core.ignorecase` | `true` |

Use the git-tracked spelling **`docs/ROADMAP.md`**. Do not create `docs/roadmap.md` as a new file:
on this case-insensitive macOS checkout it would silently overwrite, and on a case-sensitive
filesystem such as Linux CI it would produce a real duplicate roadmap.

**Branch and base:** `codex/visual-editor-linked-posts-plan`, synchronized 0/0 with its upstream,
base commit `5db4b40`. The R1-D mockup files are uncommitted and untracked, alongside the
pre-existing uncommitted R0/R1-A/R1-B/R1-C work.

## 2. What actually changed, in one paragraph

The R1-D Claude static mockup for the Frontend Media Manager is complete through all four gated
sub-phases (D1 evidence and component plan, D2 default desktop mockup, D3 states and responsive,
D4 handback package). It lives at `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/` and
consists of 15 files: `index.html`, `states.html`, `styles.css`, `mockup.js`, `README.md`,
`COMPONENT-NOTES.md`, `WIRING-SCHEMATIC.md`, `DESIGN-DECISIONS.md`, two session handoff aids
(including this one), and five screenshots. **No production PHP, JS, or CSS was created or modified, no route is called, and
no descriptor, Media Library action, or content mutation exists.** Frontend-table translation -
the second half of R1 slice 4 - remains pending and is Codex's work.

## 3. Files to update - and one file NOT to update

| File | Action |
|---|---|
| `docs/ROADMAP.md` | Update the Visual Editor add-on row - section 4 |
| `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md` | Update the R1 status row - section 5 |
| `docs/dropins/.../tracking/IMPLEMENTATION-TRACKER.md` | Update the R1 row, two checkboxes, and the R1-D checkpoint - section 6 |
| `PACKAGE-MANIFEST.json` | **Do not change** - section 7 |
| `PACKAGE-CONTENTS.sha256` | **Do not change** - section 7 |

## 4. `docs/ROADMAP.md`

In the **Active Work** table, the **Visual Editor add-on** row. Find this sentence inside the
Notes cell:

> R1-D is authorized, with a token-conscious Claude context capsule and R1-C-safe fixture
> prepared; static mockup and frontend table work remain pending.

Replace with:

> R1-D is authorized and its Claude static mockup is complete through sub-phases D1-D4, delivered
> read-only at `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/` with a component inventory,
> wiring schematic, design decision record, and static validation evidence; frontend table
> translation remains pending and no production asset, route, descriptor, or mutation was added.

Leave the rest of the cell unchanged - the R1-A/R1-B/R1-C descriptions, the coverage boundary, the
deferred-path list, and the inherited-limitation notes are all still accurate.

## 5. `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md`

In the **Repository-reconciled program status** table, the `R1` row. Find:

> The token-conscious R1-D Claude context capsule and safe fixture are prepared; static mockup and
> frontend table work remain pending.

Replace with:

> The token-conscious R1-D Claude context capsule and safe fixture are prepared, and the R1-D
> Claude static mockup is complete through D1-D4 at
> `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`; frontend table translation remains
> pending.

The R1 status label itself stays **"In development"**. Consider updating the phrase "R1-D
authorized" to "R1-D mockup delivered; translation pending" if the row's status shorthand is used
elsewhere.

Optionally add an evidence line alongside the existing R1-A/R1-B/R1-C evidence paragraphs:

> R1-D mockup evidence on 2026-08-15: static HTML/CSS/JS mockup delivered under
> `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`. Ad hoc structural validation passed
> 30/30 checks covering tag balance, id uniqueness, ARIA reference resolution, role census,
> table/colgroup agreement, selector scoping, banned-API absence, and R2-vocabulary exclusion.
> `node --check` passes. Geometry verified at 1440/900/390px with no horizontal overflow. No
> production code changed, so no PHP suite, agent-docs check, or lint baseline is affected.

## 6. `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/IMPLEMENTATION-TRACKER.md`

**6.1** The `R1` row of the release table (around line 24). Find the final cell:

> R1-A/R1-B/R1-C complete for review; R1-D authorized and context prepared; Claude D1/static mockup/frontend work pending

Replace with:

> R1-A/R1-B/R1-C complete for review; R1-D Claude static mockup delivered D1-D4 and awaiting review; frontend table translation pending

**6.2** In the R1 checklist (around lines 105-106), tick two boxes:

```
- [ ] Claude static mockup delivered/reviewed
- [ ] Accepted/adapted/rejected design decisions recorded
```

becomes

```
- [x] Claude static mockup delivered/reviewed
- [x] Accepted/adapted/rejected design decisions recorded
```

The design decision record is `DESIGN-DECISIONS.md` section 2, which lists accepted, adapted, and
deferred/rejected concept-image candidates in three explicit tables. **Tick the second box only if
"recorded" means recorded by Claude; if it means reviewed and ratified by Codex, leave it open
until that review happens.**

Leave every other R1 checkbox unticked. Production scrollable table, state coverage in production,
keyboard/touch/mobile QA, Bricks Builder isolation, and feature flag/fallback/release notes are all
still outstanding - the mockup does not satisfy any of them.

**6.3** In the **R1-C review stop line**, the remaining-gates line currently reads:

> - Remaining release gates: **Claude/static mockup review, frontend table, browser/accessibility/Builder QA, large-site/payload profiling, feature fallback/release evidence**

Replace with:

> - Remaining release gates: **static mockup review sign-off, frontend table, browser/accessibility/Builder QA, large-site/payload profiling, feature fallback/release evidence**

**6.4** In the **R1-D authorization and context-preparation checkpoint**, two lines are now stale:

> - Claude D1 state: **ready to start; static mockup not yet delivered or reviewed**
> - Next review line: **Claude returns the read-only D1 component/token/responsive/wiring plan before any static mockup files are created**

Replace with:

> - Claude D1-D4 state: **complete; static mockup delivered at `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/` and awaiting Codex review**
> - Next review line: **Codex reviews the accepted/adapted/deferred decision record and adjudicates the open questions in `DESIGN-DECISIONS.md` section 3 before frontend-table translation begins**

**6.5 Do not touch** the `Claude mockup and design decision record` checkboxes in the **R4** and
**R6** sections. Those releases have no mockup and are not started.

## 7. Why the package manifest and checksums must NOT be updated

This was verified rather than assumed, and it corrects an earlier note of mine that called the
missing entry a gap. It is not a gap.

- `PACKAGE-MANIFEST.json` declares `target_location:
  DBVC/docs/dropins/dbvc-visual-editor-brand-controls-guide/`.
- All 46 of its `files[]` entries are package-relative (`00-GOVERNING-DIRECTIVES.md`,
  `ui-ux/...`, and so on).
- Neither `PACKAGE-MANIFEST.json` nor `PACKAGE-CONTENTS.sha256` contains any reference to
  `ui-mockups` - grep returns zero in both.
- The mockup lives at `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`, **outside** the
  package directory.
- The R1-C fixture at `ui-ux/fixtures/media-manager-r1c-view-model.json`, which *is* inside the
  package, is already listed in the manifest and already has a checksum line.

Adding mockup entries would put out-of-package paths into a package-scoped manifest and
invalidate the checksum contract. **Leave both files alone.**

If the manifest's `repository_reconciliation` block is separately maintained as a running status
record, updating an R1-D status field *inside* it is a judgement call for Codex - but no `files[]`
entry and no checksum line should be added for the mockup.

## 8. Accuracy guardrails for whoever writes the update

Claims that are **true** and safe to write:

- The R1-D static mockup is delivered through D1-D4 and is awaiting review.
- It is read-only, calls no route, and adds no production asset.
- A design decision record with accepted/adapted/deferred candidates exists.
- Structural validation passed 30/30; `node --check` passes; geometry verified at three widths.

Claims that would be **false** - do not write these:

- That the Media Manager frontend table is implemented. It is not; that is R1 slice 4's second
  half and remains pending.
- That the mockup is integrated, wired, or connected to REST. It is not.
- That accessibility is verified. No axe/Lighthouse run and no assistive-technology testing were
  performed. Roles and names are structurally correct but unheard.
- That cross-browser behaviour is verified. Only Chrome was available.
- That the fixture discrepancies are resolved. Three remain open - see below.

**Still open and needing Codex adjudication**, all detailed in `DESIGN-DECISIONS.md` section 3:

1. `query.sort` is `entity_asc` but `items[]` is ordered by `scannedAt` descending.
2. `pagination.hasMore` is `true` although all 6 entities and 13 findings fit under `limit: 20`.
3. Home Page's `modifiedGmt` is ~4 hours later than its `scannedAt`, yet the expansion status is
   `current`.
4. Sort keys `missing_desc` and `scanned_desc` are unverified placeholders - only `entity_asc` is
   fixture-confirmed. Check them against `MediaScanReadModel`'s allowlist.
5. `expandedRows.changed`, `.resolved`, and `.unavailable` carry no `fields[]`, so three state
   cases render counts and a status banner only. No field rows were invented.

The fixture was **not modified**. It must stay that way until these are adjudicated.

## 9. Paste-ready status-update prompt

Copy everything between the markers into the Codex agent thread.

---8<--- BEGIN ---8<---

Status update: the R1-D Claude static mockup for the Frontend Media Manager is complete and
awaiting your review. Please update our status documents to match. Do not write production code
in this task.

Repository: the DBVC plugin repo at
`/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`
(the surrounding Local WP install is not a git repo). Branch `codex/visual-editor-linked-posts-plan`,
base `5db4b40`, synchronized 0/0. Existing uncommitted R0/R1-A/R1-B/R1-C work must be preserved:
do not reset, restore, stash, clean, or revert anything.

What happened: the Claude static mockup ran all four gated sub-phases D1-D4 and delivered 15 files
to `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`. It is read-only HTML/CSS plus an
optional local-only JS file. It calls no REST route, adds no production PHP/JS/CSS, and creates no
descriptor, Media Library action, or content mutation. Frontend-table translation is still pending
and is yours.

Please read `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/CODEX-STATUS-UPDATE-HANDOFF.md`
first. It carries the exact before/after replacement text for every edit below, plus the evidence
behind them. Then apply:

1. `docs/ROADMAP.md` - update the Visual Editor add-on row in Active Work (handoff section 4). Use
   this exact tracked path spelling; `docs/roadmap.md` in AGENTS.md refers to the same file via a
   case-insensitive filesystem, and creating a lowercase sibling would duplicate the roadmap on
   Linux.
2. `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md` - update the R1 row and
   optionally add the R1-D evidence paragraph (handoff section 5). R1 stays "In development".
3. `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/IMPLEMENTATION-TRACKER.md` -
   update the R1 row, tick the two mockup/decision checkboxes, and refresh the R1-C remaining-gates
   line and the R1-D checkpoint (handoff section 6). Leave the R4 and R6 mockup checkboxes alone.

Do NOT modify `PACKAGE-MANIFEST.json` or `PACKAGE-CONTENTS.sha256`. The mockup lives outside the
brand-controls package, both files are package-scoped with zero `ui-mockups` references, and the
R1-C fixture inside the package is already listed with a checksum. Handoff section 7 has the
evidence.

Accuracy constraints: do not claim the frontend table is implemented, that the mockup is wired or
integrated, that accessibility is verified (no axe run, no AT testing), or that cross-browser
behaviour is verified (Chrome only).

Then please review and adjudicate the five open items in
`docs/ui-mockups/dbvc-visual-editor/r1-media-manager/DESIGN-DECISIONS.md` section 3 - three fixture
discrepancies, the unverified `missing_desc`/`scanned_desc` sort keys, and the missing `fields[]`
for three expansion states. The fixture is unmodified and should stay that way until you decide.
Also reconcile the route-and-symbol cells in `WIRING-SCHEMATIC.md` against
`MediaManagerController` and `MediaScanReadModel`; those cells are design intentions transcribed
from the capsule, not verified bindings.

Report which files you changed and which of the five open items you resolved.

---8<--- END ---8<---

## 10. Provenance of the claims in this document

Everything asserted here was checked in the working tree on 2026-08-15, not recalled:

- Repo boundary: `git rev-parse --show-toplevel` in both directories.
- Roadmap identity: `git ls-files`, `ls -i` on both spellings, `git config core.ignorecase`.
- Manifest scope: parsed `PACKAGE-MANIFEST.json`, enumerated `files[]`, grepped both tracking files
  for `ui-mockups`.
- Stale sentences: located by line in each of the three status documents and quoted verbatim above.
- Mockup validation figures: re-run at D4 and again after the final stylesheet fix; both runs
  30/30.
