# Content Migration V3 — Slice S1d implementation brief

**Lane:** Content Collection / Migration V3
**Slice:** S1d — *one source URL → many target entities* (closes defect **D-19**)
**Priority:** P1 (the named next slice)
**Written:** 2026-10-24. **Base branch:** `master` (tip `d34de4d1` at writing).

> **Canonical plan of record:** [`docs/implementation/proposed/content-collection-v3/README.md`](../proposed/content-collection-v3/README.md).
> That guide owns the V3 direction, the defect register (§5), the evidence register (§6), the acceptance gates (§8), and the build sequence (§9). **Read it before this brief and defer to it on any conflict.** This file is the executable brief for one slice; it does not restate the guide.

Required reading order (per `AGENTS.md`):
1. `AGENTS.md` (repo root) and `docs/agent-entrypoints.md`
2. The V3 guide above — specifically **§5 (D-19, and D-22's still-open tie-break)**, **§6.1c (the "second finding" about the narrow item set)**, **§8 (gates G-4/G-5)**, and **§9.2 (the S1d row)**
3. `addons/content-migration/README.md`
4. This brief

---

## 1. The problem (D-19)

A V2 run resolves **one** target entity per source URL. `mapping-recommendations.v2` carries a single `recommended_target_object`, and `resolve_selected_target_object()` takes one `recommended_target`. On corpus slot 1, `/dental-team` must become **five** `team_member` records and structurally cannot — and the same shape blocks service listings, FAQ pages, location pages, and any index that should become a *set* of records, which is a large share of what a studio migrates.

Carry **§6.1c's second finding** into the same slice, because it is the same shape of change: the mapping index builds items from section *units* — one headline, one body, one CTA per section — and only title/description as head items. So it:
- drops interpreter answers it has nowhere to attach to (counted as `interpretation.unjoined_recommendation_items`), and
- can never reach a field like `meta:_seo_robots`, which has no item at all.

Widening the recommendation to a list and widening the item set are one unit of work.

**Why now:** it is a **contract change** to `mapping-recommendations.v2`. Doing it before S3 authors ground truth for corpus slots 2/3/5 means that ground truth is written against the new shape, not migrated to it.

## 2. Scope / done-when (from the guide's §9.2)

**Done when all hold:**
- `/dental-team` (corpus slot 1) produces **five** `team_member` entities, each with its own name, role, and bio.
- The built package commits them as **five posts**.
- `interpretation.unjoined_recommendation_items` is **zero** on slot 1's homepage.
- Gates **G-4 / G-5** do not regress (G-5 must stay passing — zero confidently-wrong auto-accepts; G-4 is still on the inherited 0.92 threshold and is *not* recalibrated here — that belongs to S3).
- The flag-off (lexical) path's byte behaviour is proven, not assumed, where the contract shape is unchanged for single-entity pages.

**Explicitly out of scope:** recalibrating `autoAcceptMinConfidence` (S3); corpus slots 2–5 (S3); flipping the runtime default (S6a); the D-22 six-row-cap / flat-0.84 tie-break (S3).

## 3. Entry points (verified 2026-10-24; confirm with your own recon)

| Concern | File | Change |
|---|---|---|
| Recommendation shape | `addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php` | `recommended_target_object` becomes a **list** of target objects per source URL (or an explicit single-element list), not one object. |
| Contract | `addons/content-migration/v2/shared/dbvc-cc-v2-contracts.php` | Version/extend `mapping-recommendations.v2` for the multi-entity shape. Keep it additive and backward-readable where possible; if the version bumps, update every reader below. |
| Item set + unjoined counter | `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php` | Widen the item set so repeated section paragraphs and all head fields (incl. `meta:_seo_robots`) get items; drive `interpretation.unjoined_recommendation_items` to zero on slot 1 home. |
| Entity resolution (stage 15) | `addons/content-migration/v2/package/dbvc-cc-v2-package-selection-service.php` (`resolve_selected_target_object`) + `addons/content-migration/v2/schema/dbvc-cc-v2-schema-presentation-service.php` (`resolve_target_object`) | Resolve a **set** of targets per source URL, preserving the 5-step create/update/blocked match order per member. |
| Review a set | `addons/content-migration/v2/review/dbvc-cc-v2-exception-queue-service.php`, `dbvc-cc-v2-recommendation-review-service.php`, and the admin-app exceptions surface under `v2/admin-app/.../exceptions/` | The exception queue + inspector must let a reviewer review a set of entities from one URL. |
| Package build | `addons/content-migration/v2/package/dbvc-cc-v2-package-build-service.php` | Must emit N records for N resolved entities; QA/readiness (`v2/package/dbvc-cc-v2-url-qa-report-service.php`) must count them correctly. |
| Readers to keep in sync | `v2/package/dbvc-cc-v2-url-qa-report-service.php`, any consumer of `mapping-recommendations.v2` (grep it). | Anything that assumed one target per URL. |

**Corpus / ground truth:**
- Slot 1 lives at `addons/content-migration/tests/corpus/slots/mirceaolteanudds-com/2026-10-10/` — `expected-mappings.json`, `expected-content.json`, `target-schema.v1.json`, `profile.json`.
- Author/extend the `/dental-team` ground truth for the five `team_member` entities. Note §6.1c's third finding: the pinned schema and the live catalog spell `target_ref` differently (`acf:<group>:<field>` flat + `meta:post:<subtype>:<key>`) — reconcile for scoring and say so.
- Run: `composer corpus:eval` (gates), `composer corpus:baseline` (no-fail), `composer corpus:eval-model` (model path; needs `DBVC_CC_OPENAI_API_KEY`), `composer corpus:self-test`, `composer corpus:verify-scorer`.

## 4. Project conventions to honour (from the guide's failure-mode guards)

- **Name tests and any new flags after the behaviour they protect — never a phase/slice number** (F-4). E.g. `ContentMigrationMultiEntityPerUrlTest`, not `...Phase33Test`.
- **Measure, don't assume** (F-5). A slice is done when something *calls* the new path and a run (or a transport-injected test) produces the number — not when the code reads correct. Prove the flag-off path byte-identical where the shape is unchanged.
- **Honest provenance** (F-2/D-3): a `model` key exists only when a model ran.
- **No threshold moves here.** Escalate, don't re-score (the pattern D-22/§6.1d/§6.1e established).
- Keep the change behind existing flags; **no behaviour change for a default install** unless the contract shape genuinely must change for single-entity pages too — if it does, prove it.
- Run the full suite and report against the known baseline (**4 long-standing failures**: `BricksAddonPhase7Test`, `BricksAddonPhase11Test`, `CapabilityLandscapeTest`, `ProposalDiffContractTest`); introduce no new failure.

## 5. Verification checklist (what the PR must show)

- [ ] `composer corpus:eval` — `/dental-team` yields 5 `team_member` entities; `unjoined_recommendation_items` = 0 on slot 1 home; G-5 passes; G-4 no worse than today.
- [ ] Package build commits 5 posts for `/dental-team` (shown, not asserted abstractly).
- [ ] Flag-off byte behaviour proven where the shape is unchanged.
- [ ] New behaviour-named PHPUnit; mutation-checked both directions.
- [ ] `vendor/bin/phpunit --filter "ContentMigration|ContentCollector"` green; full suite back to the 4-failure baseline.
- [ ] `composer agent-docs:check` passes **if** a public CLI/REST/admin/hook/setting/table/scheduled-hook/contract surface changed (follow `docs/agents/MAINTENANCE.md`).

## 6. Docs to update when it lands

- V3 guide §9.1 (add S1d to Delivered with its measurement) and §9.2 (strike S1d; promote the next P1/P2).
- Mark D-19 **RESOLVED** in the guide's §5 register with the file + evidence.
- `docs/ROADMAP.md` Content Collection V3 row (per `AGENTS.md`).
- Archive or supersede this brief once the slice is delivered.
