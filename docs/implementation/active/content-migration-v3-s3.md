# Content Migration V3 — Slice S3 implementation brief

**Lane:** Content Collection / Migration V3
**Slice:** S3. Widen the corpus to four profiles, fix what the new profiles break, then recalibrate G-4's threshold.
**Priority:** P1, alongside the unit-economics baseline. The two are independent; neither blocks the other.
**Written:** 2026-11-12. **Base branch:** `master`, tip `81f89a53` at the time of writing.

> **Canonical plan of record:** [`docs/implementation/proposed/content-collection-v3/README.md`](../proposed/content-collection-v3/README.md).
> That guide owns the V3 direction, the defect register (§5), the evidence register (§6), the corpus and gates (§8) and the build sequence (§9). **Read it before this brief, and defer to it on any conflict.** This file is the executable brief for one slice; it does not restate the guide.

Required reading order (per `AGENTS.md`):
1. `AGENTS.md` (repo root) and `docs/agent-entrypoints.md`
2. The V3 guide above, specifically:
   - **§8** (the corpus table and the gates);
   - **§6.1b–§6.1g** (every G-4/G-5 number so far, and why 0.92 is wrong);
   - **§5**: D-22's open tie-break and D-16;
   - **§9.2** (the S3 row).
3. `addons/content-migration/tests/corpus/README.md` and `.claude/skills/site-content-harvest/SKILL.md`
4. This brief

---

## 0. Decisions needed before any code (owner's call, not the implementer's)

S3 cannot start until the owner names three real sites. Choosing a site for the corpus is a business decision: whose content enters the measurement set, and on whose authority (§11.3 "Authorization record", "Explicit misuse boundary"). Record each choice in the slot's `profile.json`, including who authorized the capture and when.

| Slot | Profile (§8.1) | What the site must exercise | Suggested source |
|---|---|---|---|
| 2 | WordPress + Bricks | The shape a rebuild lands in, and re-platforming the studio's own work | A Bricks site the studio built. That avoids third-party copyright questions, and the studio's Bricks context library already documents its markup. |
| 3 | Classic-editor WordPress, no builder | Clean `<p>` content with no div wrapping. Proves the capture does not over-fit to builder markup. | Any client or prospect in the book still on the classic editor. |
| 5 | 150+ pages | URL discovery (G-1) at scale, and a mix of page templates | The largest site in the book that the studio has a reason to capture. |

**Spending decision.** S3 makes paid model calls: headless and wired runs per slot, plus any reruns.
- Before the first paid run, write the estimated number of calls and the provider into the PR description.
- Get the owner's yes to that estimate.
- Never print the key. It resolves from the `wp-config.php` constant or the environment.

## 1. The problem

Every G-4/G-5 number in the guide comes from **one site, and mostly from one page**. Slot 1's `expected-mappings.json` authors only `home` and `dental-team`.

§6.1b and §6.1c both found that G-4 fails for a mechanical reason: the inherited `autoAcceptMinConfidence` of 0.92 sits above where a calibrated model places its confidence. Across four wired runs:
- G-4 landed between 20% and 33%.
- Field accuracy was 100% every time.
- G-5 was 0% every time.

The obvious fix is to lower the threshold. That is also the trap the guide names: a threshold set from the page it was measured on works on exactly that page. S3 earns the right to move it by measuring three more profiles first.

There is a second reason. Slot 1 is Divi, and Divi is the only builder the gates certify against (§8.1's stated limitation). A Bricks slot retires that risk for the studio's own target stack. A classic-editor slot shows whether the capture over-fits to builder markup.

## 2. Scope and done-when (from the guide's §9.2)

**Done when all of these hold:**
- **G-1 through G-5 hold on four profiles** (slots 1, 2, 3 and 5), measured on the wired path where a gate reads the pipeline.
- **G-4's threshold is justified from at least three of those profiles**, and validated on a slot it was not fitted to (§5.3).
- **G-5 stays at or below 2% on every slot**, on both the model path and the flag-off lexical path. It is 0% on slot 1 today.
- **The flag-off path does not regress on slot 1**: G-4 stays at 4.8% and G-5 at 0%, unless a fixed defect explains a change and the report says so.

**If a gate cannot hold, report it and stop. Never move a gate.** If no threshold gives G-4 ≥ 80% with G-5 ≤ 2% across the slots:
- that is the finding;
- record the curve and the best achievable pair;
- leave the bar where §8.2 puts it.

The guide's sequence was built to be able to fail, as it could have at S1b.

**In scope:**
1. Capture, ground truth and a pinned target schema for slots 2, 3 and 5 (§3).
2. Generalise the measurement harness from slot 1 to every slot (§4).
3. Fix what the new slots break. Each fix gets a defect-register entry (§5.1).
4. D-22's still-open tie-break (§5.2).
5. Reconcile the two `target_ref` spellings, which §6.1c handed to "whichever slice next authors ground truth" (§4).
6. Re-measure the default model across the slots before trusting it on harder layouts. §6.1b asked for this.
7. Recalibrate the threshold (§5.3).

**Explicitly out of scope:**
- **G-8 budget, the per-run ceiling, concurrency, resumability, and a full 150-page run.** These are S5. Slot 5 enters the corpus here for G-1 and as a sampled source of ground truth; S5 runs it whole.
- **Slot 4** (JavaScript-rendered), which stays deferred by design.
- **Flipping the runtime default** (S6a).
- **The S1d gaps.** Media is not joined to records, and the within-record rules apply only on set pages. Fix one only if a new slot measurably fails on it, and log it as a defect.
- **Authoring more slot 1 pages.** Allowed, but not required. `faq` and `services` are the useful candidates if a slot falls short of ground truth (§5.3).

## 3. The new slots

Follow the corpus README's "Adding a slot" and match slot 1's layout: `slots/<domain-key>/<capture-date>/` holding `profile.json`, `expected-urls.json`, `expected-content.json`, `target-schema.v1.json` and `expected-mappings.json`.

- **Capture with the acquisition skill** (`.claude/skills/site-content-harvest/`: `discover.py`, `capture.py`, `emit_artifacts.py`). G-2 and G-3 measure the skill's artifacts (Q5), not V2's retired capture path.
- **Fixtures stay out of git.** The raw HTML and the skill's output live in a dated folder outside the repository, pinned by `raw_html_sha256`, exactly as slot 1 does. Follow the README's privacy section; it belongs in each `profile.json`.
- **Verify the URL inventory by hand**: HTTP status per URL plus the resolved internal link graph. On slot 1 no single discovery tool cleared 95%.
- **Derive `expected-content.json` from the raw bytes, never from a tool's rendered output.** Choose `required_text` probes that discriminate, meaning copy the page would be broken without.
- **Pin a target schema per slot.** The schema is the rebuild target, so it describes the shape the studio would rebuild the site into, not the source site. Keep it small, committed and VerticalFramework-shaped without being VerticalFramework, as slot 1's `dental-practice-rebuild` is. Declare per-entity cardinality (`one_per_person` and so on) wherever the site has sets, because the floor and the D-19 path both read it.
- **Author `expected-mappings.json` per page**, using the shape S1d settled:
  - `mappings`, `expected_null`, `expected_unmapped` and `must_not_map`;
  - for set pages, `expected_entities` and `expected_entity_count`.
  - Key expectations on distinctive source text probes, never on generated ids.
  - Mark alt-text sentences that the capture lifts into paragraphs as `expected_unmapped`. §6.1g found that leaving them unmarked hides a G-5 failure.
- **Slot 5: sample, don't exhaust.**
  - G-1 runs against the full URL inventory.
  - Ground truth for G-2 through G-5 covers a stratified sample with at least one page per distinct template, roughly 10–15 pages.
  - `profile.json`'s `coverage` block says it is a sample and how the sample was chosen.
- **Each `profile.json` names the defects or risks its slot exercises** in `why_this_slot_matters`. A slot that exercises nothing new is noise (§8.1).

Commit each slot's baseline scores with the slot (`composer corpus:baseline`), before any fix lands. Later numbers are compared against that baseline.

## 4. Entry points (verified 2026-11-12; confirm with your own recon)

| Concern | File | What S3 does there |
|---|---|---|
| Headless evaluator | `addons/content-migration/tests/corpus/eval/run.php` | Already iterates every slot under `slots/*/*` (`dbvc_corpus_discover_slots`) and reports gates per slot. Add a **cross-slot summary**: G-4 and G-5 per slot and pooled, so the "four profiles" claim is one printed table and not four reports read side by side. |
| Wired measurement | `tests/phpunit/ContentMigrationCorpusWiredMeasurementTest.php` | Hard-coded to slot 1 (`$domain = 'mirceaolteanudds.com'`, `slot_dir()`). Generalise it to any slot with mapping ground truth, so each slot measures through the shipping pipeline. Keep the paid-run gate (`DBVC_CC_CORPUS_MEASURE=1`). **Run it through `--filter`, never a file path**: the ACF test doubles live in other test files (§6.1g). |
| `target_ref` spelling | `normalise_target_ref()` in the wired test | The pinned schema writes `meta:_seo_title` and `acf:page_cards:items.card_title`; the live catalog writes `meta:post:<subtype>:<key>` and a flat `acf:<group>:<field>`. Move the reconciliation into **one** shared, unit-tested function used by both the headless and wired scorers. New ground truth keeps the schema's `field_ref_format`. This is a scoring decision, not a product change; record it in §6. |
| Candidate cap and tie-break (D-22) | `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`, the `uasort` plus `array_slice(…, 0, 6)` in candidate finalisation | All catalog-pattern matches share a flat 0.84 and tie-break alphabetically, so the six-row cap cut `meta:post:page:_seo_title` while an image field survived. See §5.2. |
| Threshold | `DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE` (`v2/shared/dbvc-cc-v2-contracts.php`), read in `v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php` (two sites) and `v2/ai-context/dbvc-cc-v2-initial-classification-service.php` | One option currently serves both confidence scales. See §5.3 before changing anything. |
| Corpus docs | `addons/content-migration/tests/corpus/README.md` | Stale: it still lists the Elementor and React slots that the owner dropped on 2026-10-15 (§8.1). Bring it in line with §8.1 when the slots land. |

## 5. The work, in order

### 5.1 Measure first, then fix what breaks

For each new slot, in this order:
1. Flag-off lexical baseline.
2. Headless floor.
3. Headless model (`composer corpus:eval-model`).
4. Wired model.

Read the results before changing code. Every break becomes a numbered defect (D-27 onward) in the guide's §5, with location and evidence, the way D-18 and D-22 were recorded. A break is not "the model got a field wrong"; it is a reproducible cause.

**Fix rule, the same as D-22 and S1d: escalate, don't re-score.**
- A fix that makes a confident wrong answer reach a reviewer is in scope.
- A fix that raises a confidence so a G-4 number improves is not.
- That changes only in §5.3, and only by the threshold.

D-16 (mapping quality coupled to the VerticalFramework theme) gets its first real evidence here. If a non-dental target schema exposes the coupling, record it.

### 5.2 D-22's tie-break

The fix makes the ground-truth-correct field survive the cap on slot 1's home page without hard-coding that field:
- prefer an evidence-based tie-break, or a per-item-type cap;
- do not prefer a larger flat cap that merely pushes the cliff further out.

A behaviour-named test pins it, and mutating the tie-break back must turn the test red. Prove that the flag-off G-5 does not regress on any slot.

### 5.3 Recalibrate, last

Only once slots 2, 3 and 5 are measured and their breaks fixed.

- **One threshold currently serves two scales, and that is the first thing to settle.** The lexical scorer's candidates have fixed confidences (slot graph 0.88, catalog pattern 0.84, pattern memory 0.96, structural defaults above 0.92). Lowering a shared threshold to where the model sits would let lexical candidates auto-accept on the flag-off path, which is the exact failure D-22 fixed. §6.1e already leaves interpreter-ranked items "to their own confidence scale".
  - **Recommended:** a threshold that applies only to interpreter-ranked recommendations. The lexical 0.92 stays as it is.
  - If you choose a single threshold instead, prove the flag-off G-5 holds on every slot.
- **Fit on some slots and validate on another**, for example leave-one-slot-out, as long as the held-out slot is never used to choose the value. Record:
  - the model-path confidence distribution per slot;
  - the G-4/G-5 curve over candidate thresholds;
  - the chosen value, with its margin to the first threshold at which G-5 breaks on any slot.
- **Run-to-run drift is real.** G-4 moved 20–33% across four identical runs (§6.1c). Base the choice on more than one run per slot, or on cached answers replayed against varied thresholds, and report the spread.
- **The default ships behind the existing option.** A changed default is a settings-contract change, so follow `docs/agents/MAINTENANCE.md`.

### 5.4 Re-measure the default model

`gpt-5.6-terra` was chosen on one page (§6.1b). Report its field accuracy and G-5 on every slot. Measure a second model only if the default fails G-5 on some slot; that is a decision for the owner, with its own cost estimate.

## 6. Project conventions to honour

- **Name tests and any new flags after the behaviour they protect, never a slice number** (F-4).
- **Measure, don't assume** (F-5). Numbers come from a run or a transport-injected test, never from reading the code. A slot is done when its scores are printed, not when its JSON is valid.
- **Honest provenance** (D-3): a `model` key exists only when a model ran.
- **No behaviour change for a default install** except the threshold, and only per §5.3. Prove the flag-off path on slot 1 is unchanged, as S1c-b and S1d did.
- **Full suite against the known baseline**: 4 long-standing failures (`BricksAddonPhase7Test`, `BricksAddonPhase11Test`, `CapabilityLandscapeTest`, `ProposalDiffContractTest`). Introduce no new one.
- **Worktree test setup:** symlink `vendor` and `tmp` from the main checkout and never commit them. Do not check out `master` in a worktree.
- **`npx wp-scripts build content-collector-v2-app` deletes the other bundles in `build/`.** Restore them with `git checkout` if you rebuild.

## 7. Verification checklist (what the PR must show)

- [ ] Owner's site choices and authorization recorded in each `profile.json`; spend estimate approved before the first paid run.
- [ ] Slots 2, 3 and 5 committed with baseline scores captured before any fix.
- [ ] Cross-slot table: G-1 to G-5 per slot and pooled, flag-off and model path.
- [ ] Every new defect in the guide's §5 with location and evidence; each fix pinned by a behaviour-named test that is mutation-checked.
- [ ] D-22 tie-break fixed and pinned; flag-off G-5 unchanged or better on every slot.
- [ ] Threshold: per-slot distributions, the curve, the fit/validate split and the chosen value with its margin. Or, if no value satisfies both gates, the curve and the best achievable pair, with the gates left alone.
- [ ] Default model's field accuracy and G-5 on every slot.
- [ ] `composer corpus:self-test` and `corpus:verify-scorer` pass, with the oracle at 100% on every slot.
- [ ] `vendor/bin/phpunit --filter "ContentMigration|ContentCollector"` green; full suite back to the 4-failure baseline.
- [ ] `composer agent-docs:check` passes if a setting default or any other public contract changed.

## 8. Docs to update when it lands

- V3 guide:
  - §8.1: mark slots 2, 3 and 5 as having fixtures;
  - a new §6.1h for the measurement;
  - §9.1: add S3 to Delivered;
  - §9.2: strike S3 and re-order the rest;
  - §5: new defects, plus D-22's tie-break marked resolved.
- `addons/content-migration/tests/corpus/README.md`: slot list and the sampling rule for large slots.
- `docs/ROADMAP.md`: the Content Collection V3 row (per `AGENTS.md`).
- Archive this brief to `docs/archives/content-migration-v3/` once the slice merges, as S1d's was.
