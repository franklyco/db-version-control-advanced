# Unit-economics baseline — manual migration timing

**Status:** instrument ready, not yet run. Open since 2026-10-10; co-P1 in the V3 guide §9.2.
**Target site:** `dbvc-cm-target.local` (LocalWP), provisioned 2026-11-14 and proved equal to the paid wired measurement's target. Both passes land there.
**Purpose:** the only number that says whether the Content Migration tool beats doing it by hand.
**Who runs it:** a person with a stopwatch. An agent cannot run this — it would measure agent time.
**Agent work around it:** [`content-migration-v3-unit-economics.md`](../../active/content-migration-v3-unit-economics.md) — building the target site, the tool-side procedure, and recording the result.

Every later V3 slice gets judged against this figure, so it is worth forty minutes of care in how
it is taken. A sloppy baseline is worse than none: it will be cited for a year.

---

## 1. What you are timing

You are timing **the same deliverable the tool produces**, and nothing more. The tool's output is
structured source content landed in the target's fields — not a built page (V3 guide §11.1 Q1). If
you time yourself building a Bricks layout, the comparison is rigged in the tool's favour and the
number is useless.

**In scope** — for one source URL, by hand:

- read the live page, identify its sections
- create the target entity (post / CPT record) in a local WP install whose CPT + ACF shape matches
  the corpus's pinned `target-schema.v1.json`. That install is provisioned for you
  (`scripts/content-migration-target-site.php`), from the same translation the paid wired
  measurement uses, so both passes land in the target the tool is scored against. The SEO keys
  have no screen of their own in WordPress, so the target adds a **Target meta fields** box on the
  edit screen for them.
- copy the page's actual copy into the correct fields, heading hierarchy preserved
- download each image, upload it to the media library, carry its alt text, assign it to the right
  field
- carry the SEO title, meta description and robots value across
- write down anything genuinely ambiguous rather than guessing — this is the manual equivalent of
  an escalation, and it is what you will compare the tool's exception queue against

**Out of scope** — stop the clock, these are not what the tool does:

- building or styling any layout
- rewriting, tightening or improving the copy
- design or IA decisions
- client emails, calls or questions
- fixing anything you find wrong on the source site

## 2. Which pages

Three, not one. One page is a sample of one and will not generalise.

| Page | Why | Expect |
|---|---|---|
| `/contact/thank-you` | Smallest real page. Establishes the floor — the irreducible per-page overhead of creating an entity and filling a few fields. | Fast |
| `/patient-info` *or* `/services` | A typical interior page. This is the number that matters most, because most pages are this. | Middling |
| `/dental-team` | Five `team_member` records from one URL. S1d (delivered 2026-11-12, guide §6.1g) taught the tool to build a set from one URL, so this page is the **first operator-minutes test of the tool's set handling**. | Slowest by far |

Source content for all eight pages is already captured at
`~/Documents/LocalProjects/dbvc-content-migration-trials/<trial-domain>/2026-10-12/` — but **work from the live site**, not from the capture. The
capture is the tool's output; using it would be timing yourself using the tool.

## 3. Stopwatch rules

1. **Wall clock, one page at a time.** No multitasking, no music-shopping, no Slack.
2. **Pause for interruptions and log them separately.** Do not silently include or exclude them.
3. **Do not practise first.** First-pass time is the honest number. If you do a second page of the
   same template, record both — the gap between them *is* the learning curve, and it is worth
   knowing.
4. **Record the ambiguities as they happen**, not from memory afterwards.
5. If a page takes more than 45 minutes, stop, record where you got to, and note it as unfinished.
   A truncated honest number beats a padded complete one.

## 4. The sheet

Fill this in. Minutes to one decimal place.

| Page | Minutes | Interrupted (min) | Sections | Fields populated | Images | Ambiguities | Notes |
|---|---|---|---|---|---|---|---|
| `/contact/thank-you` | | | | | | | |
| `/patient-info` or `/services` | | | | | | | |
| `/dental-team` | | | | | | | |

**Ambiguities log** — one line each, the thing you could not decide without asking someone:

```
page            | what was ambiguous                                  | what you did
----------------|-----------------------------------------------------|---------------
/dental-team    | heading and alt give one person two surnames        | recorded both
```

**Friction notes** — free text. What was tedious, what was fiddly, what you got wrong and had to
redo. This is the part that tells you *which* slice to build next, and it is the part you will
forget by tomorrow.

## 5. The metrics

Derive these three:

- **operator-minutes per page** — the headline figure.
- **operator-minutes per entity** — `/dental-team` divided by 5. This is the number S1d changes.
- **operator-minutes per field populated** — the most transferable across sites, because field
  count is the thing that actually scales.

## 6. The comparison rule — read this before citing any result

When you measure the tool against this baseline, **count operator minutes on both sides, not
pipeline runtime.**

Reviewing an escalated item is operator time. A run that takes 4 minutes of compute and then 25
minutes of someone working the exception queue costs **25 operator-minutes**, not 4. On slot 1's
homepage a default run currently auto-accepts 1 of 21 items and escalates 20 — so today's honest
tool cost is close to "review almost every field", which may well be *slower* than doing it by
hand. That would be a real finding, and it is exactly the kind of thing this baseline exists to
expose rather than hide.

Comparing manual minutes against pipeline runtime is the single easiest way to get a flattering
number that falls apart the first time someone else runs the tool.

## 7. The tool-side pass

The second number. Same site, same deliverable (§1), timed by the same rules (§3), so the two can be
compared. An agent sets the site up; **a person times it**.

**The path.** The model path: `dbvc_cc_flag_model_interpretation` on, the default model, the key in
the site's `wp-config.php` (placed by the owner; an agent never handles it). That is what the
product is for. Optionally, also time the default install, with the flag off, on `/contact/thank-you`
only: a default run escalates 20 of 21 items on the homepage (guide §6.1d), so one data point on what
a default install costs an operator is worth having.

**What counts as operator minutes.** Everything the operator does from "start the run" to "the
entities exist with the right content":

- starting the run (an ingest of the slot-1 capture; capture is the acquisition skill's work, logged
  as pipeline wait, not counted);
- working the exception queue: every escalated item is read and decided;
- approving, building the package, and executing it;
- checking the result in wp-admin, and fixing anything wrong there (**post-execute corrections**:
  that fixing is tool cost).

**Pipeline wait** is logged in its own column. A wait short enough to keep the operator at the
screen, **under about 2 minutes**, counts as operator time, because nothing else gets done in it. A
longer wait is logged and not counted. Say on the sheet which rule each wait fell under.

**Order, and the familiarity bias.** One person doing both passes knows the content the second
time, which favours whichever pass comes second.

- **Do the manual pass first** on every page. It is the honest first-pass number (§3.3).
- For the typical page, use **different pages of the same template** on each side: `/patient-info`
  by hand and `/services` with the tool, or the reverse. Write down which was which.
- `/contact/thank-you` and `/dental-team` have no twin, so the bias is unavoidable there. The
  sheet states it rather than hiding it.

**The sheet.** The §4 columns, plus five:

| Page | Run path | Minutes | Pipeline wait (min) | Wait rule | Interrupted (min) | Items escalated | Items auto-accepted | Post-execute corrections | Fields populated | Images | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `/contact/thank-you` | model | | | | | | | | | | |
| `/services` or `/patient-info` | model | | | | | | | | | | |
| `/dental-team` | model | | | | | | | | | | |
| `/contact/thank-you` (optional) | flag off | | | | | | | | | | |

**A cross-check, not a replacement.** The review service stamps every decision save with
`reviewed_at` (`build_reviewer_meta()` in `v2/review/dbvc-cc-v2-recommendation-review-service.php`).
The spread of those stamps is a sanity check on the stopwatch. It is not the operator time, because
saves are not continuous work.

## 8. Where the result goes

1. Fill in §4 and §7 and keep the filled copies as the raw record. Commit it, or keep it alongside the slot-1 capture — it holds timing data, not client content, so either is fine.
2. Add the derived figures to the V3 guide's evidence register as a new subsection of §6
   (`README.md` beside this file), following the style of §6.1b
   through §6.1f — the measurement, the method, the date, and what it does and does not establish.
3. Update the §9.2 unit-economics row from "still unmeasured" to the figure.

Once §6 carries a number, every later slice can state its operator-minutes delta instead of
claiming an improvement.
