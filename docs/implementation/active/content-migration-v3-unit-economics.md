# Content Migration V3: unit-economics baseline brief

**Lane:** Content Collection / Migration V3
**Item:** the unit-economics baseline. It has no slice id: §9.2 lists it as P1 with "—".
**Priority:** P1, alongside S3. The two are independent: S3 is blocked on site choices, and this item is not.
**Written:** 2026-11-13. **Base branch:** `master`, tip `901539f0` when this was written.

> **Canonical plan of record:** [`docs/implementation/proposed/content-collection-v3/README.md`](../proposed/content-collection-v3/README.md).
> The V3 guide owns the direction, the evidence register (§6) and the build sequence (§9.2's unit-economics row).
> **The measurement instrument already exists:** [`unit-economics-baseline.md`](../proposed/content-collection-v3/unit-economics-baseline.md), which covers what is timed, which pages, the stopwatch rules, the sheet and the comparison rule.
> This brief does not restate that protocol. It covers the work an agent does around it, plus the tool-side half of the comparison that the protocol only names.
> **Defer to the guide, then the protocol, on any conflict.**

Required reading order (per `AGENTS.md`):
1. `AGENTS.md` (repo root) and `docs/agent-entrypoints.md`.
2. The protocol, `unit-economics-baseline.md`, in full. It is short.
3. Two parts of the V3 guide:
   - §11.3's "Unit economics" row and §11.1 Q1. They define what the deliverable is, and so what may be timed.
   - §6.1c and §6.1g. They give the auto-accept and escalation rates a tool-side reviewer will actually face.
4. This brief.

---

## 0. Who does what

**The stopwatch is held by a person, never by an agent** (protocol header). An agent timing itself measures agent time, which is not the product's cost. The work therefore splits in three:

| Phase | Who | What |
|---|---|---|
| A. Build the target site the stopwatch runs against | agent | §2. Nothing in the repo builds it today. |
| B. Time both sides | **the owner, or a studio operator** | The manual pass uses the protocol's §1–§4. The tool pass uses §3 of this brief. |
| C. Derive and record the figures | agent | §4, using the owner's filled sheets. |

Phases A and C are agent work. Phase B is not, and an agent must not simulate, estimate or back-fill it.

## 1. The problem

The guide has said since 2026-10-10 that nobody has timed a manual migration. It also says the baseline is *"the only number that says whether any of this beats copy-paste"* (§9.2, §11.3). Every slice since then has claimed gains in G-4/G-5 terms, and none in operator minutes.

Two things block a timed run today. The protocol names both and solves neither:

- **There is no target to migrate into.** Protocol §1 requires *"a local WP install whose CPT + ACF shape matches the corpus's pinned `target-schema.v1.json`"*. No such install exists.
  - The only code that turns that schema into post types, post meta and ACF field groups is `install_target_schema()`, `acf_groups()` and `acf_fields()` in `tests/phpunit/ContentMigrationCorpusWiredMeasurementTest.php`.
  - Those functions build test doubles inside PHPUnit, not a site a person can open.
  - Asking the operator to hand-build fifteen page fields and four objects first would add setup friction to a measurement meant to capture migration friction.
- **The tool side has no procedure.** The comparison rule (protocol §6) says to count operator minutes on both sides. But nothing says what the tool-side operator does, in what order, or on which path (flag on or off). Without that, the second number is improvised on the day and is not comparable.

## 2. Phase A: the target site (agent)

**Goal:** one disposable LocalWP site whose post types, post meta and ACF field groups are generated from slot 1's `target-schema.v1.json`. Both stopwatch passes land in that same site.

The tool must not get a different target from the human. If it did, the comparison would measure the difference between the targets, not between the methods.

- **Generate, don't hand-author.** Move the schema-to-target translation out of the wired test into one shared function. Use it twice:
  1. The wired test calls it to build its ACF doubles, as it does now.
  2. A dev-only provisioner calls it to emit a mu-plugin for the disposable site. That mu-plugin registers the CPTs and post meta, and calls `acf_add_local_field_group()` for each group.

  One translation means the human, the tool and the paid wired measurement all see the same target.
- **Dev-only, with no product surface.** The provisioner is a script under the content-migration tests or `scripts/`, not a `wp dbvc` command, REST route or setting. It writes a mu-plugin into the named disposable site, and the matching teardown removes it.
  - If you find you need a public surface, stop. That is a contract change, and it is the owner's call under `docs/agents/MAINTENANCE.md`.
- **It never touches a site anyone cares about.** Target a LocalWP site created for this work. Refuse to run when the target site path or its `siteurl` matches a list of protected sites, starting with this repository's own dev site.
  - The disposable site needs ACF installed. Say so in the script's preflight; don't silently degrade to plain post meta.
- **Prove the two targets are the same.** This is the discriminating check:
  - On the provisioned site, run the shipped target field catalog service and put its output through the bridge to `corpus-target-schema.v1`.
  - Compare that with what the wired test's doubles produce for the same schema. They must be equal.
  - Add a behaviour-named test that pins the shared translation, for example `ContentMigrationCorpusTargetProvisioningTest` (never a slice number). Mutation-check it.
- **Verify the tool can reach it.** On the provisioned site, an ingest run of slot 1's `/contact/thank-you` with the model flag off should produce `mapping-recommendations.v2` against the provisioned objects. This is a smoke run, not a timed one.
- **Leave the site ready, not started.** Hand the owner:
  - the site URL and admin login (created with a generated test password and recorded in the site's own config, never pasted into chat);
  - which three pages each pass uses (§3);
  - the protocol sheet.

  The model API key is the owner's to place in that site's `wp-config.php`. The agent never sees or prints it.

## 3. Phase B: the tool-side procedure (for the owner; the agent writes it into the protocol)

Add this procedure to `unit-economics-baseline.md` as a new section, alongside the manual sheet, so both halves live in one instrument:

- **The path:** the model path (`dbvc_cc_flag_model_interpretation` on, default model), because that is what the product is for.
  - Optionally, also time the default flag-off path on the smallest page only. A default run escalates 20 of 21 items on home (§6.1d), so even one data point on the cost of the default install is worth recording.
- **What counts as operator minutes:**
  - Count: everything the operator does from "start the run" to "the entities exist with the right content". That means starting the run (capture or ingest), working the exception queue, approving, executing, and checking the result in wp-admin.
  - Record pipeline wait separately. A wait that is short enough to keep the operator at the screen (under about 2 minutes) counts as operator time. A longer wait is logged but not counted, and the sheet says which rule applied.
- **Order, and the familiarity bias.** One operator doing both passes will know the content the second time, which favours whichever pass comes second.
  - Run the **manual pass first** on every page. The protocol says not to practise, and a first pass is the honest manual number.
  - For the typical page, use **different pages of the same template** on each side: `/patient-info` by hand and `/services` by the tool, or the other way round. Record which was which.
  - `/contact/thank-you` and `/dental-team` have no twin, so the familiarity bias is unavoidable there. The sheet states it rather than hiding it.
- **The sheet:** the same columns as the manual sheet, plus *run path*, *pipeline wait (min)*, *items escalated*, *items auto-accepted* and *post-execute corrections*. Post-execute corrections means anything the operator fixed in wp-admin afterwards; that fixing is tool cost.
  - As a cross-check, the review service stamps each decision save with `reviewed_at` (`build_reviewer_meta()` in `v2/review/dbvc-cc-v2-recommendation-review-service.php`). It is a sanity check on the stopwatch, not a replacement for it. Saves are not continuous work.

Also bring the protocol's stale lines current. Its `/dental-team` row says timing it *"gives that slice a number to beat"*, but S1d has shipped (§6.1g). That page is now where the tool's set handling gets its first operator-minutes test, so reword the row as such. Leave everything else in the protocol as written.

## 4. Phase C: derive and record (agent, after the owner hands back both sheets)

- Compute the protocol's three metrics for both sides: minutes per page, per entity (`/dental-team` ÷ 5) and per field populated. Also compute the **delta per page**, and the **break-even**: the number of pages at which any fixed per-run tool overhead is paid back.
- **Report what the numbers do not establish.** One operator, one site (slot 1, Divi, dental), three pages, and the familiarity bias where it applies.
  - If the tool is slower on some page, that is the finding. The protocol says so in advance (§6), and it must be reported as plainly as a win would be.
- Record it in the guide as the next free §6.1 subsection, in the style of §6.1b–§6.1g: the measurement, method, date, and what it does and does not establish. Then:
  - Update §9.2's unit-economics row from "still unmeasured" to the figures. Strike it as P1, because the baseline is a one-time instrument.
  - Add a **per-slice operator-minutes line** to how §9.1 records future slices, so S3 onward reports a delta rather than claiming an improvement. That line is the §9.2 done-when's second half.
  - Update `docs/ROADMAP.md`'s Content Collection V3 row (per `AGENTS.md`).
- Keep the filled sheets as the raw record. They hold timing data, not client content, so commit them beside the protocol.

## 5. Done when

- [x] A disposable site generated from slot 1's pinned schema. A test proves its catalog equals the wired measurement's, and the tool's smoke run reaches it. *(2026-11-14: `dbvc-cm-target.local`, ACF Pro 6.6.2; equal bar the named `_acf_changed` exclusion; found D-27.)*
- [x] The tool-side procedure and sheet are in the protocol, and the stale `/dental-team` line is reworded. *(Protocol §7.)*
- [ ] **The owner's** manual and tool-side sheets are filled for three pages.
- [ ] Per-page, per-entity and per-field figures for both sides, with the delta, break-even and limits, recorded in the guide's §6 and §9.2 and in the roadmap.

The first two items can land as one PR before the stopwatch session. The last two land as a second PR after it, so the owner is never waiting on code to start timing.

## 6. Conventions

- **Name tests after the behaviour they protect** (F-4). **Measure, don't assume** (F-5): the catalog-equality check is a real comparison, not two code paths that merely read alike.
- **No behaviour change to the product.** Refactoring the translation out of the wired test must leave that test's output identical. Prove it with a cached-answer replay, which needs no paid call.
- **Full suite against the known baseline** of 4 long-standing failures (`BricksAddonPhase7Test`, `BricksAddonPhase11Test`, `CapabilityLandscapeTest`, `ProposalDiffContractTest`). Introduce no new failure.
- **Never print the API key.** The paid wired test runs through `--filter`, never a file path; the ACF doubles live in other test files.
- **Worktree setup:** symlink `vendor` and `tmp` from the main checkout, and never commit them. Never check out `master` in a worktree.
- **Archive this brief** to `docs/archives/content-migration-v3/` once the figures are recorded.
