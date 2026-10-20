# Content Collection corpus

The frozen measurement set for the Content Collection add-on. It exists because V2 measured
"accuracy" against a live LocalWP install and a live theme, so a regression and a target drift were
indistinguishable, and "accuracy is improving" could never be falsified.

Plan and gate definitions: `docs/implementation/proposed/content-collection-v3/README.md`.

## Run it

```bash
composer corpus:baseline     # report everything, always exit 0 - for recording a baseline
composer corpus:eval         # exit 1 if any measurable gate fails - for CI
composer corpus:eval-json    # machine-readable
```

```bash
php addons/content-migration/tests/corpus/eval/run.php --slot=mirceaolteanudds-com
DBVC_CORPUS_FIXTURES=/path/to/fixtures composer corpus:eval
```

## What is and is not committed

**Committed:** assertions, ground truth, and profile metadata. Small, reviewable, diffable.

**Not committed:** the source HTML. Corpus fixtures are third parties' copyrighted website content,
collected to rebuild their own sites. It does not belong in plugin git history. Each slot declares
where its bytes live (`fixtures.default_root`, overridable with `DBVC_CORPUS_FIXTURES`) and pins
them by `raw_html_sha256`. When the bytes are absent the evaluator reports G-2 and G-3 as
`not_measured` with the reason, and still reports G-1.

A fixture whose hash no longer matches is flagged in the report rather than silently measured.

## How the evaluator stays honest

- **It measures the shipped code, not a model of it.** Tier A parses fixtures with the same
  `DOMDocument`/`DOMXPath` V2 uses, driven by the same selector literals.
- **It fails loudly on drift.** `eval/selectors.php` holds every rule next to the source file and
  the exact literal it came from, and re-greps all of them on each run. If V2's extraction changes,
  the run aborts with exit 2 instead of reporting a stale score.
- **Coverage is an ancestor test over text nodes, never a sum of element `textContent`.** The
  selector set matches a `<p>` and an `<a>` nested inside it, so summing would double-count
  link-dense regions and exceed 100%. Every character is counted once. The duplication is reported
  separately as `elements_v2_text_duplication_factor`.
- **Unmeasurable is not estimated.** G-4 and G-5 need a target CPT/ACF schema and a WordPress
  bootstrap, so they report `not_measured` with the reason until that harness exists.

## Slot layout

```
slots/<domain-key>/<capture-date>/
  profile.json           platform, why the slot earns its place, where the fixtures live, coverage
  expected-urls.json     the URL ground truth for G-1
  expected-content.json  per-page ground truth for G-2 and G-3
  expected-mappings.json per-page target mappings for G-4 and G-5 (not authored yet)
```

### profile.json

Declares the CMS and builder, whether JS is needed for content, `why_this_slot_matters` (the
specific defects this slot exercises — a slot that exercises nothing new is noise), the fixture
root and required files, and an honest `coverage` block. A slot covering 2 of 8 pages says so.

### expected-urls.json

- `content_urls` — every live content URL, with its verified HTTP status and which discovery
  strategies found it.
- `must_reject` — index spam, WordPress leftovers, and script artifacts. 100% rejection is required.
- `must_report_not_crawl` — broken internal targets. These must surface as issues, not be crawled as
  content and not be silently repointed.
- `discovery_strategy_recall` — per-strategy recall. This is what proves a gate is achievable, and
  on slot 1 it is what showed that no single strategy clears 95%.

### expected-content.json

Per page: the fixture and its `raw_html_sha256`, `visible_text_chars`, `required_text` probes,
the `heading_tree` with levels, `images` with alt and the attribute the real URL actually lives in,
and `head_fields` with `null` where a field is genuinely absent.

`required_text` is the part that matters. Percentages move for uninteresting reasons; a named
missing paragraph does not. Choose probes that discriminate — the copy a page would be broken
without. On slot 1 those are the homepage service-card blurbs and the lead paragraph of the About
page, both invisible to V2.

## Adding a slot

1. Capture the site. Keep the raw HTML outside the repo, in a dated folder.
2. Verify the URL inventory yourself — HTTP status per URL, plus a resolved internal link graph.
   Do not trust one discovery tool; on slot 1 `firecrawl_map` missed a live page that is in the
   main nav, and the link graph missed one the map found.
3. Derive `expected-content.json` from the raw HTML, never from a tool's rendered output. The bytes
   are authoritative.
4. Write `profile.json`, and in `why_this_slot_matters` name the defects the slot exercises.
5. Run `composer corpus:baseline` and commit the resulting scores in the slice that adds the slot.

Target profiles are listed in the V3 guide, section 8.1. Slot 1 is WordPress + Divi with no
sitemap; slots 2-6 (Elementor, Bricks, a JS-rendered non-WordPress site, a React marketing site,
and a 150+ page site) are still needed.

## Privacy and use

Fixture content belongs to the site owner. Use it to measure this tool. Do not publish it, do not
use it as training or example material, and delete a slot when its engagement ends — removing the
external fixture folder is enough to make its gates report `not_measured` without breaking the run.
