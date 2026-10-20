# DBVC Content Collection V3 Implementation Guide

Date: 2026-10-10
Status: Proposed. No code written against this guide yet.
Author context: written after a live trial against a real prospect site, not from the V2 docs.

## 0. How to use this document

This is the planning entry point for the next direction of the Content Collection / Content
Migration add-on. It replaces `MIGRATION_MAPPER_V2_*` as the place to start. Those documents stay
in `addons/content-migration/docs/` as the historical record of V2 and remain accurate about what
V2 does; they are no longer the plan.

Read in this order:

1. Sections 1–3 — what was promised, what exists, where the two diverge.
2. Section 4 — the failure modes, because they will recur if the direction does not change.
3. Section 5 — the defect register. Each entry is reproducible.
4. Sections 7–9 — the V3 direction, acceptance criteria, and build sequence.
5. Section 11 — the decisions that belong to the product owner, not to an implementer.

Trial data backing every measurement in this guide:
`/Users/rhettbutler/Documents/LocalProjects/dbvc-content-migration-trials/mirceaolteanudds.com/2026-10-10/`

---

## 1. Why this document exists

V2 is, by count: 32 closed phases, 128 runtime files under `addons/content-migration/v2/`,
13 feature flags, 66 settings keys, ~30 PHPUnit suites, and roughly 11,600 lines of planning
documentation in the module's `docs/` folder alone.

Pointed at the first real client site it was asked to handle — a 2018 WordPress/Divi dental
practice site — **it cannot start.** URL discovery hard-fails because the site has no sitemap.
Nothing downstream of discovery ever executes.

That is not a small bug at the edge of a working system. It is the signature of a system whose
investment went almost entirely into the half of the pipeline nobody had validated demand for,
while the half that touches reality was assumed solved. This guide exists so V3 does not repeat
that, and so the substantial parts of V2 that *are* good are not thrown away with the rest.

## 2. The original vision — and the contradiction inside it

`MIGRATION_MAPPER_V2_OVERVIEW.md` states the product goal:

> crawl any client site for raw content / interpret and normalize that content automatically /
> align it to the current WordPress site's schema / transform it into target-ready values /
> validate the result / produce an import-ready package as the primary deliverable

and the central promise:

> `crawl source content -> automatically build a target-ready package -> only ask the user for
> help when confidence, policy, or ambiguity require it`

The vision is sound. The problem is three words deep in it.

The goal says **"any client site."** The stage specification that implements discovery
(Stage 2, "URL Discovery and Normalization") says only: *"parses sitemaps / expands nested
sitemaps / canonicalizes and deduplicates URLs."* No fallback, no link-graph crawl, no manual
list, no mention of what happens when a site has no sitemap — which describes a large share of
the exact market this tool targets: older small-business sites that a studio gets hired to replace.

The implementation followed the stage spec faithfully and thereby failed the product goal. Nobody
was wrong at the keyboard. The spec was wrong, and no acceptance test existed that would have
caught the difference.

**Lesson for V3:** a product goal that is not decomposed into falsifiable stage criteria does not
constrain anything. Section 8 fixes this with numbers.

## 3. What was actually built

Against the 20 canonical stages in the V2 overview:

| # | Vision stage | Status | Note |
|---|---|---|---|
| 1 | Domain intake + target schema sync | **Built, works** | `dbvc-cc-v2-schema-sync-service.php`, run context + settings snapshot |
| 2 | URL discovery and normalization | **Built, blocks on real sites** | Sitemap-only and hard-required. See D-1 |
| 3 | URL eligibility and scope | **Built, admits spam** | See D-4 |
| 4 | Page capture and raw artifact storage | **Built, lossy** | See D-6, D-7, D-8, D-15 |
| 5 | Privacy / scrub / source normalization | **Built** | Deterministic attribute scrub + policy hash. Genuinely good |
| 6 | Structured extraction and packaging | **Built, incomplete coverage** | See D-5, D-9 |
| 7 | **AI layer: context creation** | **Scaffolding only — no AI** | See D-2 |
| 8 | Target object inventory | **Built, works** | |
| 9 | **AI layer: initial classification** | **Scaffolding only — no AI** | See D-2 |
| 10 | Target field schema catalog | **Built, works well** | CPT + taxonomy + meta + ACF, with slot graph |
| 11 | **AI layer: mapping and indexing** | **Model-ranked since 2026-10-20** | Behind `dbvc_cc_flag_model_interpretation`, off by default; the lexical scorer is the per-item fallback. See D-20 |
| 12 | Parallel media candidate track | **Built, works** | Reads `page-artifact.v1#images[]`, role hints, policy/provenance |
| 13 | Pattern reuse and learning | **Built, wired, real** | 328 lines, consumed by mapping index + orchestrator |
| 14 | Target-value transformation | **Built** | `value_contract` validation is a strong piece |
| 15 | Target entity resolution preview | **Built, works** | create / update / blocked, 5-step deterministic match order |
| 16 | **AI layer: finalize recommendations** | **Deterministic consolidation** | See D-2 |
| 17 | Review by exception + overrides + rerun | **Built, extensive** | Exception queue, conflicts tab, bulk review, per-URL rerun |
| 18 | QA and package validation | **Built, works** | Benchmark gates, readiness blocking |
| 19 | Build import-ready package | **Built, works** | Signed manifest, records, media manifest, QA report, ZIP |
| 20 | Dry-run / plan / guarded execute / rollback | **Built, works, hardened** | Preflight token, journal tables, auto-rollback |

**Read the shape of that table.** Stages 8, 10, 12–20 — the destination half — are genuinely
finished, tested, and in several places better than they needed to be. Stages 2–6 — the
acquisition half — are the weakest code in the add-on. And stages 7, 9, 11, 16 — every stage the
overview labels "AI Layer" — contain no AI.

### 3.1 The headline finding: there is no AI in V2

`grep -rn "wp_remote_post\|call_openai\|openai" addons/content-migration/v2/` returns exactly one
hit: a settings read that copies a model name into an artifact.

The V1 service (`addons/content-migration/ai-mapping/dbvc-cc-ai-service.php`) does call OpenAI —
`call_openai_json()`, gated on an `openai_api_key` option. V2 does not call it, or anything else.

What V2's "AI layer" services actually do:

- build a `prompt_input` payload from the page evidence,
- stamp `prompt_version: "v1"` and `model: "gpt-4o-mini"` onto the artifact,
- **discard the prompt**,
- and score candidates with keyword/token matching.

The prompt is constructed and never sent. This single fact explains the entire 32-phase history.
The working-state document's current seam reads, in part:

> ...semantic slot-role inference for `wysiwyg` hero fields, post-object exclusion of option-page
> and taxonomy slots, raw sort-score candidate ordering, ACF `link` slot-role correction,
> section-body scoring against nested hero card or popup branches, page-description separation
> from hero section claims, structural competition groups for non-repeatable sibling slots...

Those are 32 phases of hand-tuning a keyword scorer to perform semantic interpretation — the job
the architecture had assigned to a language model. Each correction fixes one site's one pattern
and buys nothing general. It cannot converge, because the problem is not under-tuned heuristics;
it is the wrong tool.

V2's principle #2 was *"Deterministic extraction first, AI interpretation second."* Half of it
shipped. The determinism is excellent. The second half was never built, and the scaffolding built
to receive it — prompt builders, model fields, `fallback_mode` flags, confidence scores — made the
absence invisible, because every artifact looks like it came from a model.

---

## 4. Failure modes to design against

Named so they can be checked for, not to assign blame. All six are process, not competence.

**F-1 — The deferred core.** The hardest, highest-uncertainty component (model-based
interpretation) was architected, scaffolded, given a contract, and then indefinitely deferred,
while effort flowed to the tractable work around it. The scaffolding made deferral comfortable.
*Guard:* build the riskiest component first, end-to-end, badly, on day one.

**F-2 — Provenance that lies.** Artifacts record `model: "gpt-4o-mini"` for output no model
produced, and `confidence` scores from keyword overlap presented in the same field a model's
confidence would occupy. A reviewer calibrates trust against that number. It is not measuring
what its name says.
*Guard:* provenance fields must be written by the component that did the work, and must be
absent — not defaulted — when that component did not run.

**F-3 — Assumed-solved acquisition.** "Crawl the site" reads like the easy step. It is the step
with all the adversarial variance: no sitemap, page builders that put copy in divs, CSS background
images, JS rendering, index spam, redirect chains, stale `index.html` link graphs. V2 spent one
file (`dbvc-cc-v2-page-capture-service.php`, 483 lines) on it and 128 on everything after.
*Guard:* acquisition gets a test matrix of real sites before mapping gets another phase.

**F-4 — Phase-numbered identity.** Tests are named `ContentCollectorV2Phase12Test` …
`Phase32Test`. Flags are `dbvc_cc_flag_mapping_catalog_bridge`. Docs are `PHASE3_6_*`,
`PHASE3_7_*`, `W10.3`, `W11.14`. The build order became the vocabulary. After 32 phases nobody —
human or agent — can answer "which test guards the rule that a hero heading outranks a page
description?" without reading all of them.
*Guard:* name tests and flags after the behaviour they protect. Phase numbers belong in git
history.

**F-5 — QA against a moving target.** Accuracy was measured against a live LocalWP install and
the live VerticalFramework theme. When the theme's ACF arrays changed, "accuracy" changed, and
there was no way to tell a regression from a target drift. The benchmark is a single run id
(`ccv2_flourishweb-co_20260421T223255Z_0cc20e`).
*Guard:* a frozen fixture corpus with expected outputs, checked in. Section 8.

**F-6 — Append-only documentation.** `MIGRATION_MAPPER_V2_WORKING_STATE.md` is a 302-line
"low-token resume anchor" whose Current Seam is a single 400-word sentence. The repo
`docs/ROADMAP.md` has table cells over 1,000 words. These are logs wearing the costume of
decision records. They grow monotonically, so the cost of correctly resuming work rises every
session — the opposite of their stated purpose.
*Guard:* a working-state file has a hard line cap and records *state*, not history. History goes
in a changelog.

---

## 5. Defect register

Each entry is independently reproducible. Severity is against the goal in Section 2.

| ID | Severity | Defect | Location |
|---|---|---|---|
| D-1 | ~~Blocker~~ **RESOLVED 2026-10-12 (S2a)** | URL discovery required a sitemap URL and returned `WP_Error` without one. The trial site serves no sitemap at `/wp-sitemap.xml`, `/sitemap_index.xml`, `/sitemap.xml`, `/sitemap-index.xml`, `/page-sitemap.xml`, and declares none in `robots.txt`. No link-graph fallback, no manual URL list, no external inventory. | `v2/discovery/dbvc-cc-v2-url-inventory-service.php:43` |
| D-2 | ~~Blocker~~ **RESOLVED 2026-10-20 (S1c-b)** | The four "AI Layer" stages made no outbound model call; interpretation was keyword/token scoring. The mapping stage now calls the interpretation boundary behind `dbvc_cc_flag_model_interpretation` and takes its ranking. The other three AI-layer stages (context creation, initial classification, recommendation finalization) remain deterministic by design — mapping is where the judgement is. | `v2/interpretation/dbvc-cc-v2-mapping-interpretation-service.php`; `interpretation/dbvc-cc-interp-model.php` |
| D-3 | **High** | Artifacts record `model: "gpt-4o-mini"`, `prompt_version`, and `prompt_input` for output produced by no model. False provenance; reviewers mis-calibrate against it. | `v2/ai-context/dbvc-cc-v2-context-creation-service.php:50`; `...initial-classification-service.php:52` |
| D-4 | ~~High~~ **RESOLVED 2026-10-14 (S4)** | Scope filter admitted injected `.shtml` spam as `eligible`. Three Japanese SEO-spam URLs remain in Google's index for the trial domain; `is_non_html_asset` does not cover `.shtml` and `is_utility_path` does not match their paths. A run would crawl them as client content. | `v2/discovery/dbvc-cc-v2-url-scope-service.php` |
| D-5 | **RETIRED PATH** (Q5), not a backlog item | Page-builder copy wrapped in `div`s is never extracted. The element selector set is `h1-h6\|p\|li\|blockquote\|a\|button\|figcaption\|label`. On the trial site this loses all four homepage service-card blurbs and **the opening bio paragraph of the About page**. | `content-context/dbvc-cc-element-extractor-service.php:46` |
| D-6 | **RETIRED PATH** (Q5), not a backlog item | No HTML or inline markup is retained anywhere. Every value is `normalize_text($node->textContent)`; only a `content_hash` of the body is stored. Emphasis, bold, nested list structure, and the hrefs of links inside body copy are destroyed. A content *migration* tool cannot reproduce the source. | `v2/capture/dbvc-cc-v2-page-capture-service.php` |
| D-7 | **RETIRED PATH** (Q5), not a backlog item | `collect_headings()` returns a flat, text-deduped list of strings. Heading level and document position are lost at the page-artifact layer. | `v2/capture/dbvc-cc-v2-page-capture-service.php` |
| D-8 | **RETIRED PATH** (Q5), not a backlog item | `extract_metadata()` reads only `<title>`, `meta[name=description]`, `og:*`, and JSON-LD. No canonical, `meta robots`, `twitter:*`, `hreflang`, `lang`, or `article:modified_time`. The last of these is free migration-planning data: the trial homepage was last modified 2023-08-01, `/dental-team` 2019-04-29. | `v2/capture/dbvc-cc-v2-page-capture-service.php` |
| D-9 | **Expressible 2026-10-14 (S4)**, boundaries unchanged | V2's segmenter opens a new section at **every** heading regardless of level, so nested structures split wrongly. On the trial About page, `Nicole Langford` (h2) becomes a heading-only section while her job title and bio land under `Registered Dental Hygienist` (h3). This mis-shapes every team page, service card, and FAQ. | `content-context/dbvc-cc-section-segmenter-service.php` — **unchanged.** The S2b emitter emits `parent_section_id`, verified on the team page: `Registered Dental Hygienist` now carries `parent=Nicole Langford`, so role and bio stay reachable from the person. V2's own segmenter still splits and still serves the in-WordPress capture path; fixing it there remains S4 |
| D-10 | Low | `elements[].media_refs` is declared in the contract and hard-coded to `[]`. Images reach mapping by a separate path (`page-artifact.v1#images[]`), so this is dead structure that implies a relationship the data does not carry. | `content-context/dbvc-cc-element-extractor-service.php` |
| D-11 | **Partly resolved 2026-10-14 (S4)** | Artifact directories were hardened with `Deny from all` only — Apache 2.2 syntax. No `Require all denied` for Apache 2.4 without `mod_access_compat`, and nothing at all for nginx. Artifacts live under `wp_upload_dir()`, so on a typical nginx host the crawled client content **and the built package ZIPs** are fetchable by URL. | `shared/dbvc-cc-helpers.php:32` |
| D-12 | ~~Medium~~ **RESOLVED 2026-10-14 (S4)** | `openai_api_key` is stored in `wp_options` via `sanitize_text_field` with no constant/env override and no masking on redisplay. | `settings/dbvc-cc-settings-service.php:81` |
| D-13 | ~~Medium~~ **RESOLVED 2026-10-14 (S4)** | Three QA fixture services are `require_once`d unconditionally in the production bootstrap, and expose dev-only REST routes. They self-gate on `WP_DEBUG` internally, but test scaffolding ships in the product and widens the REST surface. | `bootstrap/dbvc-cc-addon-bootstrap.php:26-28` |
| D-14 | ~~Medium~~ **RESOLVED 2026-10-14 (S4)** | No retention or pruning for crawled artifacts, and the plugin has **no uninstall handler at all** — no `uninstall.php` and no `register_uninstall_hook`. Third-party content accumulates in `wp-content/uploads` indefinitely and survives plugin removal. Combined with D-11 this is a real disclosure risk. | `collector/dbvc-cc-artifact-manager.php`; absence of `uninstall.php` / `register_uninstall_hook` |
| D-15 | **RETIRED PATH** (Q5), not a backlog item | No JavaScript rendering. `wp_remote_get` + `DOMDocument`. Adequate for Divi; fails on Webflow, Squarespace, Wix, and any React/Vue marketing site. | `v2/capture/dbvc-cc-v2-page-capture-service.php:50` |
| D-16 | Medium | Mapping quality is coupled to the VerticalFramework theme: the field-context provider slug defaults to `vertical-field-context` and the catalog reads a `vf_field_context` key out of ACF field arrays. The V2 docs additionally describe depending on a named theme transient. Behaviour on a client site built on any other framework is unknown and untested. | `v2/schema/dbvc-cc-v2-target-field-catalog-service.php:336,411,509` |
| D-18 | **RETIRED PATH** (Q5), not a backlog item | **Every captured image is discarded on a lazy-loading site.** `collect_images()` reads only `getAttribute('src')`. The trial site runs W3 Total Cache lazyload, so every `<img src>` is a `data:image/svg+xml` placeholder and the real URL sits in `data-src`/`data-srcset`. The placeholder is non-empty, so it reaches `canonicalize_url()`, whose scheme guard rejects `data:` and returns `''`, and the `if ($url === '') continue;` branch drops the image. Measured by the corpus evaluator: **0 of 1 images kept on the homepage, 0 of 7 on `/dental-team`** — and all 7 alt texts are lost with them, so the media candidate track receives nothing. Found by the S0 harness after two rounds of code reading had concluded the opposite. | `v2/capture/dbvc-cc-v2-page-capture-service.php` (collect_images); `v2/discovery/dbvc-cc-v2-url-scope-service.php` (canonicalize_url scheme guard) |
| D-19 | **High** | **One source URL cannot produce more than one target entity.** `mapping-recommendations.v2` carries a single `recommended_target_object`, and entity resolution resolves one target per page. Slot 1's `/dental-team` must yield **five** `team_member` entities; it cannot, no matter how good the interpreter is. The same shape blocks service listings, FAQ pages, location pages and any index that should become a set of records — a large share of what a studio migrates. Surfaced by authoring S1's ground truth against a pinned schema, which is what made the impossibility explicit instead of looking like low accuracy. | `v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php` (`recommended_target_object` is one object, not a list) |
| D-20 | ~~Blocker~~ **RESOLVED 2026-10-20 (S1c-b)** | **The interpretation boundary was not loaded by the plugin, and then was loaded but never called.** S1b built and measured it; `grep -rln "DBVC_CC_Interp" .` returned its own five files and the corpus evaluator, nothing else. S1c loaded it and built `DBVC_CC_V2_Interpretation_Bridge_Service`, the adapter that turns the live catalog and the captured artifacts into its contracts — and stopped at the seam, so a run's recommendations were still floor-produced and **D-2 was still true of the shipping product**. **S1c-b gave it a caller**: `DBVC_CC_V2_Mapping_Interpretation_Service` is invoked by the AI pipeline orchestrator's mapping stage, its `interpretation-result.v1` is persisted beside the mapping artifacts, and `DBVC_CC_V2_Mapping_Index_Service` takes the interpreter's ranking for the items it answered. Measured on the wired path with a real model call: **100% field accuracy, G-5 0% wrong auto-accepted**, against the product's own lexical scorer at 6.7% on the same run. See §6.1c. | `addons/content-migration/v2/interpretation/dbvc-cc-v2-mapping-interpretation-service.php`; `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php` (`finalize_item` seam) |
| D-21 | ~~Blocker~~ **RESOLVED 2026-10-16 (S2c)** | **Nothing could ingest an externally produced artifact set.** `page-artifact.v1` is written by exactly one thing — the capture service Q5 retired — and read by mapping, ai-context and media. `capture_page()` is its sole entry point, with no filter and no alternate constructor, so the acquisition skill's output has nowhere to go. The only handoff that exists, `start_run.php`, passes a **URL list** to a V2 run, which then re-captures every page with the retired path, reintroducing D-5 through D-8, D-15 and D-18 on the way. The consequence for the gates is worse than the consequence for the code: G-2 and G-3 were re-pointed at the skill's artifacts in S4b and now pass, certifying fidelity for a producer the product cannot consume. Introduced by S2b/S4b, which built the producer and moved the gates without building the joint. **Fixed by S2c**: `DBVC_CC_V2_External_Artifact_Ingest_Service` plus `FILTER_EXTERNAL_PAGE_ARTIFACTS` on the orchestrator, `ingestDir` on `POST /runs`, and `wp dbvc content-migration ingest validate|run`. Proven by a test asserting an ingest run makes **zero** HTTP requests. | `addons/content-migration/v2/ingest/dbvc-cc-v2-external-artifact-ingest-service.php`; `addons/content-migration/v2/capture/dbvc-cc-v2-capture-orchestrator-service.php` |
| D-17 | Low | Two complete implementations ship and are both `require_once`d at bootstrap. Which one registers surfaces *is* correctly gated by the `dbvc_cc_runtime_version` option — but **its default is `v1`**, so after 32 phases V2 is still opt-in, both codebases are maintained, and no retirement decision is recorded. 13 feature flags and 66 settings keys gate one add-on. | `v2/shared/dbvc-cc-v2-contracts.php:142,187`; `v2/bootstrap/dbvc-cc-v2-runtime-registrar.php:33-45` |

### 5.1 Corrections to assumptions made earlier in this investigation

Recorded because they were wrong in a plausible way and the next reader will make them too.

- **Images: this claim flipped twice; here is the verified chain.** `collect_images()` does read
  `src` and `alt`, and `DBVC_CC_V2_Media_Candidate_Service` does consume
  `page-artifact.v1#images[]`. An earlier draft concluded from that pair of facts that images were
  safe. They are not, on this site. The `src` it reads is a lazyload placeholder, the scheme guard
  in `canonicalize_url()` discards it, and the image never reaches the artifact — so there is
  nothing for the media track to consume. See **D-18**, which the S0 evaluator measured directly
  rather than inferring. Divi's CSS-background hero slides are a separate, genuinely
  unrecoverable case.
  The lesson is the one F-5 warns about: reading code answered "does it read `alt`?" correctly and
  "does an image survive?" wrongly. Only the fixture answered the second question.
- **The phone number and hero headline are extracted.** Each also appears elsewhere on the page
  inside a selected element. Only the `div`-wrapped instance is skipped. The genuinely missed copy
  is narrower than it first appeared, and named in D-5.
- **D-12's export worry was unfounded.** The Configuration Portability provider already marks
  `cc_openai_api_key` with `default_export: POLICY_EXCLUDE`, `environment_policy: POLICY_EXCLUDE`,
  `sensitive: true`, `requires_confirmation: true` and `STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED`. The
  key cannot leave in a portability package. That half of the concern was already handled before
  this review began.
- **D-12 was worse in a way the register missed.** The Configure page rendered the real secret into
  the `value` attribute of its password input on every load, so it sat in the page source, and in
  anything that reads or caches it, each time an administrator opened the tab. Storing it in
  `wp_options` was the recorded half; echoing it back was the unrecorded half.
- **D-13's fix is not "stop loading the files".** The fixture services are called from production
  REST controllers and a package build service, so they must remain loadable. What was actually
  wrong is that the two development-only routes were *registered* unconditionally and only refused
  inside the handler, which publishes them in REST discovery on production. Gating registration is
  the fix; the `require_once` calls stay.
- **A runtime gate does exist.** An earlier draft of this guide claimed V1 and V2 both register
  surfaces. They do not: `DBVC_CC_V2_Contracts::get_runtime_version()` gates registration
  correctly. The finding that survives is narrower and more interesting — the default is `v1`.
- **The plugin has no uninstall handler.** Not "no cleanup of add-on data" — no `uninstall.php`
  and no `register_uninstall_hook` anywhere. Verified, not inferred.
- **Directory hardening is applied, just Apache-only.** `ensure_directory()` does call
  `dbvc_cc_create_security_files()` on every artifact directory, which writes `index.php` and an
  `.htaccess`. D-11 is about that file's contents, not a missing call.
- **The V2 workspace is on `master`.** `codex/content-addon-v2` is an ancestor of `master`, which
  is 346 commits ahead. The working-state document's `Branch: codex/content-addon-v2` anchor is
  stale and will send a fresh session to the wrong tree.
- **A "Delivered" marker in Section 9 did not mean "reachable".** Seven slices were marked
  delivered, each accurately — the code exists, the tests pass, the measurements are real. Read as
  a sequence they implied a working chain, and there was none: the interpreter had no caller and
  the capture skill had no consumer. See **D-20** and **D-21**, both found by grepping for callers
  rather than by reading the slices again, which is the F-5 lesson a second time. The guide now
  separates §9.1 delivered from §9.2 remaining, and a slice is not done when its code works — it
  is done when something calls it. Both are now closed, and closing the second one produced the
  F-5 lesson a third time: the wired measurement in §6.1c found that the product silently drops
  interpreter answers it has no mapping item for, which no amount of reading the bench harness
  would have shown.

---

## 6. Evidence register

Measured 2026-10-10 against `https://mirceaolteanudds.com/` (WordPress 6.1.8, Divi Child 1.0).
Raw data and scripts: see Section 12.

| Measurement | Result |
|---|---|
| Sitemap available at any standard path | **No** — five paths checked, all 404; none declared in `robots.txt` |
| URLs found by sitemap discovery (DBVC) | **0** — hard error before inventory |
| Live content URLs on the site | **8**, each verified 200 on 2026-10-11 |
| URLs returned by `firecrawl_map` | **10** — 7 of the 8 real pages, plus 3 index-spam `.shtml` |
| `firecrawl_map` discovery recall | **87.5%** (7/8) — it misses `/contact`, which is live and in the main nav. **Below the G-1 threshold** |
| Internal link-graph recall (homepage) | **87.5%** (7/8) — finds `/contact`, misses `/contact/thank-you`, which is reachable only as a form-submit destination |
| Union of map + link graph | **100%** (8/8) — the only strategy that clears G-1 |
| Broken internal target | `/meet-the-team` returns **403** and is linked **5 times** from the homepage, including the hero CTA |
| Internal link form | every href is **relative** and targets `index.html` (`href="dental-team/index.html"`); both forms serve 200 with no canonical tag, so every page is live at two URLs |
| Homepage visible text reached by `p/li` + `h1-h6` | 1,845 / 2,583 chars — **71.4%** |
| Homepage visible text reached by the `elements.v2` selector set | 1,923 / 2,583 chars — **74.4%** |
| `/dental-team` visible text reached by `elements.v2` | 3,122 / 3,596 chars — **86.8%** |
| Named content missed on the homepage | all four service-card blurbs |
| Named content missed on `/dental-team` | the opening paragraph of Dr. Olteanu's bio |
| `<img>` tags on the homepage | **1** (the logo) — hero slides are CSS backgrounds |
| `<img>` tags on `/dental-team` | 7, all with `alt` |
| SEO head fields DBVC captures | title, description, `og:*`, JSON-LD |
| SEO head fields present but not captured | `robots`, `twitter:*` (1–3 per page), `lang`, `article:modified_time`; `canonical` genuinely absent sitewide |
| Firecrawl credit cost | 1 per page with `["markdown","links","images"]`; **5** when `json` extraction is added — and unnecessary, the free `metadata` block already carries the full head |

### 6.1 The trial also produced a working counter-example

A ~330-line converter (`tools/fc_to_dbvc.py` in the trial folder) takes a Firecrawl payload and
emits valid DBVC artifacts, porting the PHP identity rules (`canonicalize_url`, `build_page_id`,
`get_relative_page_path`, the element and section id algorithms, the segmenter, the
ingestion-package builder). From the same two pages it produced:

- `domain-url-inventory.v1` with `source.type: "external"` — 7 eligible, 3 spam correctly rejected
- `/dental-team`: 48 elements, 14 sections, 13 headings **with levels**, 7 images with `alt`,
  23 elements carrying inline markup, populated `media_refs`
- the full head metadata, including both `article:modified_time` values

This is the existence proof for Section 7: acquisition can be replaced without touching anything
downstream of it, and the artifact contracts V2 defined are good enough to accept better input.

### 6.1b The S1b model measurement (2026-10-15)

Measured on corpus slot 1's homepage, one model call each, same prompt, same ground truth, scored
by the evaluator that `corpus:verify-scorer` calibrates at 17/17 for a perfect interpreter.

| interpreter | recs | right | wrong | field accuracy | probes | G-4 | G-5 |
|---|---|---|---|---|---|---|---|
| deterministic floor | 29 | 1 | 20 | **4.8%** | 16/17 | fail 0% | no denominator |
| `gpt-4o` | 12 | 7 | 3 | 70.0% | 9/17 | fail 0% | no denominator |
| `gpt-5.6-luna` | 21 | 17 | 2 | 89% | — | — | — |
| **`gpt-5.6-terra`** | 18 | **17** | **0** | **100%** | **17/17** | fail 38.9% | **pass 0%** |
| `gpt-6-luna` | 19 | **17** | **0** | **100%** | **17/17** | fail 52.6% | **pass 0%** |

**The direction is answered: go.** A capable model maps this page perfectly where lexical overlap
managed one field in twenty-one. G-5 passes for the first time — zero confidently-wrong mappings.

Two findings worth keeping separate from that headline:

- **`gpt-4o`'s shortfall was capability, not prompting.** It declined card titles and offer text
  that the field list plainly covers, and the decline reasons read like prompt defects
  ("no suitable field for action-oriented heading"). A better model mapped them with no prompt
  change. An hour was nearly spent revising a prompt that was already correct.
- **G-4 now fails for a purely mechanical reason.** Both perfect models are right about everything
  and still miss the 80% auto-resolve bar, because the 0.92 threshold inherited from V2's keyword
  scorer sits above where they place their confidence. `gpt-6-luna`'s entire 19-recommendation set
  is zero-wrong at the 0.90 tier. The remaining gap is threshold calibration, not capability — and
  it must not be set from the same page it was measured on.

The OpenAI default is `gpt-5.6-terra`: indistinguishable from `gpt-6-luna` on correctness at
1,341 output tokens against 2,577. Measured on one page; re-measure across slots 2-5 before
trusting it on harder layouts.

### 6.1c The S1c-b wired measurement (2026-10-20)

§6.1b measured the interpreter on a bench: the evaluator reads the pinned `target-schema.v1.json`
off disk and builds the model's input from markdown with its own parser, so it exercises nothing
of the product between the model and the reviewer. This is the same page measured through the
shipping pipeline — ingest seam, live target field catalog, the bridge's `elements.v2` input
builder, one real `gpt-5.6-terra` call, the mapping index's candidate seam, and the assignment
service's gates — and scored against the same `expected-mappings.json`.

| path | recs | G-4 auto-resolve | G-5 wrong accepted | field accuracy |
|---|---|---|---|---|
| V2's lexical scorer (flag off) | 21 | 2/21 = **9.5%** fail | 1/2 = **50%** fail | 1 right / 14 wrong = **6.7%** |
| `gpt-5.6-terra` (flag on) | 18 | 6/18 = **33.3%** fail | 0/6 = **0%** **pass** | 14 right / 0 wrong = **100%** |

Reproduce with
`DBVC_CC_CORPUS_MEASURE=1 DBVC_CC_OPENAI_API_KEY=... vendor/bin/phpunit --filter ContentMigrationCorpusWiredMeasurementTest`.

**The headline holds on the wired path.** 100% field accuracy, zero wrong fields, G-5 passing —
the same result §6.1b found headless, now produced by a run rather than by an evaluator. G-4 still
fails for the mechanical reason §6.1b named: the inherited 0.92 `autoAcceptMinConfidence` sits
above where a calibrated model places its confidence. It is deliberately **not** recalibrated here,
because setting it from the one page it was measured on is how you get a threshold that works on
exactly that page. Across four live runs G-4 landed between **20% and 33%** — the model's own
confidences move run to run — while field accuracy was 100% and G-5 was 0% in every one.

Three findings the headless harness could not produce:

- **The product's own lexical scorer auto-accepts a wrong mapping.** §6.2 records the compact
  headless floor auto-accepting nothing. V2's full scorer, with the slot graph and pattern memory
  behind it, auto-accepts 2 of 21 and one of those two is wrong. A confidently wrong mapping is
  shipping today, and only the wired path shows it.
- **The mapping index's item set is narrower than the interpreter's input set.** The interpreter is
  offered every non-chrome element; the mapping index builds items from section *units* — one
  headline, one body, one CTA per section — so a section with two paragraphs drops the answer for
  one of them. Two of nineteen answers had no mapping item to attach to, which the artifact now
  counts as `interpretation.unjoined_recommendation_items`. It is also why the wired run reaches
  14 of 17 ground-truth probes where the bench reached 17: `Extensive knowledge and skill…` and
  `Senior citizens 55 years and older…` are correctly mapped by the model and then dropped, and
  `meta:_seo_robots` can never be reached at all because the mapping index surfaces only title and
  description as head items.
- **The pinned corpus schema and the live catalog do not spell a target_ref the same way.** The
  schema authors a repeater sub-field as `acf:page_cards:items.card_title` and a meta field as
  `meta:_seo_title`; the product writes `acf:<group_key>:<field_key>` flat and
  `meta:post:<subtype>:<key>`. The measurement reconciles the two for scoring only and says so; the
  divergence is real and belongs to whichever slice next authors ground truth (S3).

### 6.2 The S0 baseline

`composer corpus:baseline`, 2026-10-11, against corpus slot 1. This is the number every later
slice is measured against.

| Gate | Result | Detail |
|---|---|---|
| **G-1** Discovery | ✅ **PASS** (re-measured 2026-10-12, after S2a/S2b) | **100%**. `build_inventory()` now accepts an external list, so the union a caller supplies is the shipped ceiling, and `discover.py` produces it. Pre-S2a sitemap-only recall was **0.0%** — that was the blocker. The evaluator reads which basis applies from the source literal, so the pass cannot self-grade |
| **G-2** Capture fidelity | ✅ **PASS** (re-pointed at the skill, 2026-10-14) — superseded figure below | body text reached by `elements.v2`: **74.5% min, 90.5% mean, 99.4% max**; only **4 of 8** pages clear 98%. Content probes **24/30**. Heading levels: not preserved on any page. **Images kept: 0 of 19** — every `<img src>` on the whole site is a lazyload placeholder, so 19 alt texts are lost with them |
| **G-3** Head metadata | ✅ **PASS** (re-pointed at the skill, 2026-10-14) — superseded figure below | **42.9%** on every page. `meta_robots`, `lang`, `source_modified` and `twitter:*` are present in source and dropped; `canonical` is genuinely absent sitewide and must stay `null` |
| **G-4** Auto-resolve rate | **FAIL** | deterministic floor: **0 of 45** recommendations auto-accepted = **0.0%** against an 80% threshold. Nothing clears `autoAcceptMinConfidence` (0.92) on lexical evidence |
| **G-5** Mapping precision | no denominator | the floor auto-accepts nothing, so there is no auto-accepted set to score. That is the G-4 result restated, not a gap in the harness |
| *diagnostic* | — | the floor finds the right **source item** 16/17 times (**94.1%**) but picks the right **target field** 1 time in 19 (**5.3%**) |

Named content the evaluator proves is missing, which is the part that matters more than the
percentages:

```
homepage       "Providing quality dental care at an affordable price..."   (service card 1)
               "Your smile is in great hands at Mircea Olteanu DDS..."    (service card 2)
               "Your questions and concerns are important..."             (service card 3)
               "Receive the highest quality care every time you see us..."(service card 4)
/dental-team   "Dr. Olteanu is always looking out for the best interest..." (lead bio paragraph)
```

One finding only re-measurement could produce: **a single discovery tool's recall is not
stable.** `firecrawl_map` missed `/contact` on 2026-10-10 and returned it on 2026-10-12, and the set
of injected spam URLs it surfaces drifts between runs. The corpus ground truth now records this as
an unstable blind spot rather than a fixed one, which strengthens the union requirement: treat any
single strategy's recall as a sample, not a constant. Only the union has been 100% on every date
measured.

The 2026-10-12 live run surfaced two more things worth carrying into the product: a linked
`New-patient-packet.pdf`, which is **client content** a migration must transfer and which a
plain asset-extension filter would have binned as noise, and `/440.746.9070` returning 404 — a fax
number written as an href without `tel:`.

Two diagnostics worth keeping an eye on:

- **Text duplication in `elements.v2` is 1.17x on the homepage, 1.09x on `/dental-team`.** The
  selector set matches a `<p>` and an `<a>` nested inside it, so both become elements and the same
  characters appear twice. Harmless for coverage (the evaluator counts each character once) but it
  inflates element counts and feeds the mapper redundant candidates.
- **Slot 1 now covers 8 of 8 content URLs** (captured 2026-10-12). The per-page spread is the
  useful part: the four pages built from ordinary `<p>` content score 99%, while the four that lean
  on Divi's div-wrapped modules score 74-87%. V2's extractor is not uniformly lossy, it is lossy
  exactly where a page builder is used — which is most of the market.
- **A capture is also a client report.** The run surfaced a wrong address on `/contact/thank-you`
  (8193 Avery Rd against 7500 Town Centre Dr everywhere else), a hero CTA pointing at a 403, a
  malformed `tel:` href, drifting index spam, an alt text naming the wrong person, and three pages
  last modified in 2018-2019. These are recorded in the slot's `client_findings` so a later run can
  check they were reported rather than silently carried across.

---

## 7. V3 direction

**One architectural decision, from which everything else follows.**

Split the add-on along three boundaries, by what kind of correctness each needs:

```
┌─ ACQUISITION ──────────────┐  ┌─ INTERPRETATION ───────┐  ┌─ COMMITMENT ─────────────┐
│ outside WordPress          │  │ a model, under a       │  │ PHP, in WordPress        │
│ an agent skill + Firecrawl │─▶│ versioned contract     │─▶│ deterministic, guarded   │
│ / Chrome                   │  │                        │  │                          │
│ discovery, render, extract │  │ classify, map, explain │  │ validate, package,       │
│ normalize to artifacts     │  │ emit confidence        │  │ dry-run, write, roll back│
│                            │  │                        │  │                          │
│ correctness = fidelity     │  │ correctness = judgment │  │ correctness = safety     │
│ iterate freely            │  │ iterate by eval        │  │ change rarely, test hard │
└────────────────────────────┘  └────────────────────────┘  └──────────────────────────┘
```

Each boundary is a file contract, not a function call. That is what makes them independently
replaceable and independently testable.

The two arrows in that diagram were the part that did not exist. When this was written, all three
boundaries were built and neither joint was: acquisition wrote artifacts nothing read,
interpretation had no caller, and the only handoff in the tree passed URLs back to the capture path
Q5 retired. A file contract is only independently replaceable once something reads the file.

**Both arrows now exist.** The first since 2026-10-16 — S2c, `FILTER_EXTERNAL_PAGE_ARTIFACTS` —
and the second since 2026-10-20: S1c-b routes the mapping stage through the interpretation
boundary and persists what it returned. See **D-21** and **D-20**, both resolved, and §6.1c for
the first G-4/G-5 numbers produced by a run rather than by an evaluator.

### 7.1 Acquisition moves out of PHP

A Claude Code skill owns discovery and capture, and writes DBVC's existing artifact contracts.

Why out of PHP: the work is adversarial pattern-matching against every page builder on the
market. In an agent skill, a new builder idiom is a prompt and a recipe edit. In PHP it is a
release. The trial proved a 330-line converter beats 483 lines of hand-written DOM traversal on
the first site either was pointed at, and it gets JS rendering, non-sitemap discovery, and the
full head metadata for free.

What it does: discovery is the **union of three strategies**, then one `firecrawl_scrape` per page
with `formats: ["markdown","links","images"]` and `excludeTags: ["nav","footer"]`, Chrome DevTools
for screenshots, emitting `domain-url-inventory.v1` / `page-artifact.v1` / `elements.v2` /
`sections.v2` / `ingestion-package.v2`.

The union is not belt-and-braces; S0 measured that **no single strategy clears G-1**:

| Strategy | Recall on slot 1 | Misses |
|---|---|---|
| sitemap (what V2 requires) | 0% | everything — no sitemap exists |
| `firecrawl_map` | 87.5% | `/contact` — live, in the main nav |
| internal link graph | 87.5% | `/contact/thank-you` — only reachable via form submit |
| **all three unioned** | **100%** | — |

An earlier draft of this section proposed `firecrawl_map` plus a `site:` search alone. That fails
the gate on the very first corpus slot. Two further requirements fell out of the same measurement:
resolve **relative** hrefs against the page base and normalise the legacy `index.html` form (while
keeping it for the redirect map), and harvest hrefs only from `<a>` elements outside `<script>` —
inline JS template strings otherwise contribute junk paths like `/' + file + '`.

Carry over from `/lead-site-audit`: its discovery recipe, `site_probe.sh`, and the
evidence-or-silence discipline. Do **not** fork it — its per-page artifact is an audit summary
(title, word count, links, prose notes), not content, so a new output contract is needed either
way.

### 7.2 Interpretation gets connected, behind an eval harness

This is the component to build **first**, because it is the one V2 deferred and the one the
product promise rests on.

- One service, one versioned prompt contract, one place that makes an outbound call.
  **Built and measured in S1b**, **loaded and adapted in S1c** via
  `DBVC_CC_V2_Interpretation_Bridge_Service`, and **called by the mapping stage since S1c-b** via
  `DBVC_CC_V2_Mapping_Interpretation_Service` — behind `dbvc_cc_flag_model_interpretation`, off by
  default. §12.3 has the bench measurement and §6.1c the wired one.
- The deterministic scorer stays — demoted from "the mapper" to the **floor**: it runs when the
  model is unavailable, over budget, or returns an invalid shape, and it labels itself honestly.
  Two refinements the wiring forced, both deliberate. The fallback is **per item**: an item the
  interpreter answered takes the interpreter's candidates, an item it explicitly declined takes
  none and becomes a typed `declined_by_interpreter` unresolved item, and an item it never
  answered keeps the lexical candidates unchanged. And when the *whole* model call fails, the
  floor that answers is **V2's own 1,330-line scorer, not the boundary's compact one** — the
  boundary's floor is the 4.8% scorer of §6.2, so demoting the better floor on a model failure
  would be a regression dressed as a fallback.
- Provenance is written by whichever component produced the value. `model` absent when no model
  ran. Fixes D-3.
- Every change is measured by an eval against the corpus (Section 8), never by eyeballing one run.
- Provider choice is a Section 11 decision. Do not re-adopt the V1 default of `gpt-4o-mini`
  without revisiting it.

**S1a measured the floor, and the result is the clearest evidence in this document.** On slot 1's
homepage the deterministic floor locates the right source item 94.1% of the time and then assigns
it to the right target field **5.3%** of the time, auto-accepting nothing. It is not
under-confident — it is wrong, and correctly refuses to act on being wrong.

That is the thesis of this guide, measured instead of argued. Lexical overlap between a client's
copy and an ACF field's label and purpose carries almost no signal: *"Creating Healthy & Happy
Smiles"* shares no token with *"Hero heading / the large headline in the hero band"*, so it falls
through to `core:post_title` at 0.02 confidence. No amount of tuning closes a gap of that shape,
which is why 32 phases could not. Matching page copy to a field by meaning is a semantic task.

One caveat, stated plainly: 5.3% is **this** floor — a compact scorer using the same signal family
as V2's (token overlap against label and purpose). V2's 1,330-line mapping index has more signals
(slot graph, section family, provider purpose text, pattern reuse) and would score higher. The
number is evidence that the *signal family* is weak and a lower bound for the model to beat, not a
measurement of V2's scorer, which cannot run headless.

### 7.3 Commitment is kept, mostly untouched

Keep as-is: target field catalog, slot graph, target object inventory, media candidate track,
pattern learning, `value_contract` validation, entity resolution, exception queue and inspector,
package build + QA + signed manifest, dry-run, preflight approval token, guarded execute,
journal tables, auto-rollback, run history. This is months of correct, tested work and the
reason not to start over.

Changes needed here are small and listed in Section 9.

---

## 8. Acceptance criteria

V2's unfalsifiable "accuracy is improving" is replaced by numbers against a frozen corpus.

### 8.1 The corpus

`addons/content-migration/tests/corpus/<domain>/<date>/` — checked-in artifacts plus a
hand-authored `expected-mappings.json` per page. Not live sites. Not the live Vertical theme.

Seed it with sites covering the variance that actually breaks things. A slot that exercises nothing
new is noise, so each one has to name the defect class it covers.

| Slot | Profile | Covers | Status |
|---|---|---|---|
| 1 | WordPress + Divi, no sitemap, index spam | div-wrapped copy, lazyload placeholders, CSS backgrounds, absent sitemap, index spam | **have it** — 2026-10-12 capture, 8 pages |
| 2 | WordPress + Bricks (the studio's own target stack) | the shape a rebuild lands in, and re-platforming the studio's own work | needed |
| 3 | Classic-editor WordPress, no builder at all | clean `<p>` content with no div wrapping, which proves the capture does not over-fit to builder markup | needed |
| 4 | Non-WordPress, JavaScript-rendered | content that does not exist until JavaScript runs, so `capture.py` must fail its `--require-text` guard and hand off to the API path | **deferred by design** — filled from the next real prospect rather than chosen for the corpus |
| 5 | A large site — 150+ pages | budget ceiling, runtime, resumability (G-8) | needed |

**Dropped 2026-10-15 at the owner's direction:** a WordPress + Elementor slot and a Webflow/React
slot. Neither reflects the studio's book of business.

One limitation stays stated rather than hidden, and slot 3 only partly addresses it. A
classic-editor site proves the capture does not *over-fit* to builder markup — valuable, and cheap
to obtain. It does not prove the capture generalises to a *different* builder. Slot 1's hardest
findings (copy inside divs, lazyload `data-src`, CSS background imagery) exist in some form in every
builder, but their details are Divi's, and Divi remains the only builder the gates certify against.
Treat "the skill handles page builders" as supported by one builder until a second one is captured —
which is a reasonable risk to carry if the sites the studio takes over are overwhelmingly Divi or
builder-free, and a cheap one to retire the first time a different builder turns up in the book.

Slot 4 being deferred is deliberate, not a gap left open: filling it from a real lead means the
fixture is a site the studio genuinely needs captured, not one picked to satisfy the corpus.

### 8.2 Gates

A slice does not land unless it holds across the whole corpus:

| Gate | Threshold |
|---|---|
| G-1 Discovery | ≥95% of a site's real content URLs found; 100% of index spam and utility URLs rejected |
| G-2 Capture fidelity | Measured on the **acquisition skill's** `page-artifact.v1` and `elements.v2` (Q5). Every `required_text` probe present; every non-chrome ground-truth heading present at the right level; every non-chrome ground-truth image present, with `alt` where the source has it. The raw-percentage clause was dropped: its denominator is raw-HTML visible text including chrome, which the skill deliberately collects once into site-level data, so it could never reach 100% however good the capture. The probes are the discriminating measure and always were |
| G-3 Head metadata | Measured on the skill's `page-artifact.v1` metadata block (Q5). Every head field the source carries survives capture, an absent field stays `null`, and inventing one for an absent field fails the gate |
| G-4 Auto-resolve rate | ≥80% of sections mapped with no reviewer touch (the operational meaning of "review by exception") |
| G-5 Mapping precision | ≤2% of auto-accepted mappings wrong on review — a wrong confident mapping costs more than an escalation |
| G-6 Determinism | byte-identical artifacts on rerun of the deterministic stages; model stages stable in *shape*, with drift reported not asserted |
| G-7 Net-zero | execute → rollback returns the target site byte-identical, proven on a disposable clone |
| G-8 Budget | a 60-page run completes inside a declared credit and token ceiling, with the estimate shown before the run starts |

G-4 and G-5 are the two numbers that say whether the product works. Measure them from phase one,
publish them per slice, and stop when they hold — not when the phase list is exhausted.

---

## 9. Build sequence

Deliberately ordered so the riskiest thing is proven first (guard F-1).

### 9.1 Delivered

| Slice | Scope | Done when |
|---|---|---|
| **S0** | ✅ **Delivered 2026-10-11.** Corpus harness at `addons/content-migration/tests/corpus/`: slot 1 (the trial site) with verified URL and content ground truth, and an evaluator that measures G-1…G-3 against the shipped code with no WordPress runtime. `composer corpus:baseline`. `expected-mappings.json` is **not** authored — it needs a pinned target schema, which S1 supplies. | Done. The runner prints the baseline in §6.2, and it found D-18. |
| **S1a** | ✅ **Delivered 2026-10-11.** The interpretation boundary, WordPress-independent by design: `addons/content-migration/interpretation/` with the two boundary contracts, honest provenance (a `model` key exists only when a model ran — the D-3 fix, guarded by `composer corpus:self-test`), and the deterministic floor. Plus the pinned `target-schema.v1.json`, hand-authored `expected-mappings.json`, and G-4/G-5 measurement in the evaluator. | Done. Floor baseline in §6.2; found D-19. |
| **S1b** | ✅ **Delivered and measured 2026-10-15.** `dbvc-cc-interp-prompt.php` (one versioned prompt), `dbvc-cc-interp-model.php` (provider-agnostic, Anthropic default, injectable transport, budget ceiling, key from constant/env not the database — the D-12 fix), `dbvc-cc-interp-service.php` (try model → validate strictly → fall back to the floor with a *named* reason, response caching). Every branch is proven by `composer corpus:self-test` with a fake transport, and `composer corpus:verify-scorer` calibrates the scorer against a perfect interpreter. | Measured: see §6.1b. `gpt-5.6-terra` maps slot 1's homepage at **100% field accuracy, 17/17 probes, zero wrong**, against the floor's 4.8%. **G-5 passes.** G-4 still fails on an inherited 0.92 threshold that sits above where a calibrated model places its confidence. |
| **S2a** | ✅ **Delivered 2026-10-12.** `build_inventory()` accepts an external URL list; `POST /dbvc_cc/v2/runs` advertises `urls`; `source.type` is sitemap / external / sitemap+external and every row records `discovery_reason`. A supplied URL is still subject to every scope rule. **Resolves D-1.** `ContentMigrationExternalUrlInventoryTest` — 5 tests, 33 assertions, and the first coverage this repository has ever had for `build_inventory`. | Done. **G-1 now PASSES at 100%**, with the basis read from the source literal so it cannot self-grade. |
| **S2b** | ✅ **Delivered 2026-10-12.** `.claude/skills/site-content-harvest/` — union discovery (`discover.py`, run live against the client site: 8/8 content URLs, 3 spam rejected, 1 client document found, 2 broken targets reported), the capture recipe, the artifact emitter (`emit_artifacts.py` → `page-artifact.v1` / `elements.v2` / `sections.v2` / `ingestion-package.v2` / `interpretation-input.v1`), and `start_run.php` for the handoff. Addresses D-5, D-6, D-7, D-8, D-15; bypasses D-9 and D-18 on the new path. | Done for 3 of 8 pages (home, dental-team, faq). **Remaining: capture the other 5**, then re-score G-2/G-3 across all eight. |
| **S4** | ✅ **Delivered 2026-10-14.** D-4 scope rejects server-parsed template artifacts behind a filterable pattern, while a legacy `index.html` page stays eligible. D-9 section artifacts carry additive `parent_section_id` and `heading_path`. D-11 `.htaccess` now carries Apache 2.4 `Require all denied` beside the 2.2 syntax, upgrades an existing weaker file, and ships `dbvc_cc_storage_http_exposure_probe()`. D-12 the key resolves from a constant then the environment before the option, and the Configure page no longer echoes it. D-13 both development-only QA routes are no longer registered outside development. D-14 retention window + `wp dbvc content-migration storage prune` (dry-run default) + an opt-in `uninstall.php`. | Done, with one gap stated: **D-11 is not verified on a real nginx host.** `ContentMigrationSafetyHardeningTest` (18 tests) and `ContentMigrationSectionNestingTest` (5 tests), named for the behaviour they protect, not the slice. |
| **S4b** | ✅ **Delivered 2026-10-14.** Q5 executed: sitemap capture declared best-effort, the admin run form gained a URL-list field so a run can start without an agent and without a sitemap, the emitter folds browser-resolved CSS backgrounds into `page-artifact.v1#images[]`, and G-2/G-3 now measure the skill's artifacts. Image and heading ground truth gained an objective `is_site_chrome` flag computed from cross-page frequency, so the gate can require page imagery without demanding the logo. | **G-1, G-2 and G-3 all pass.** The retired path's figures are still printed, labelled and not gating. |

| **S2c** | ✅ **Delivered 2026-10-16.** The ingest seam (D-21). `DBVC_CC_V2_External_Artifact_Ingest_Service` stages a directory, matches each run row to a bundle by slug then by URL, and supplies `raw_artifact` + `elements` + `sections` + `ingestion_package`; `FILTER_EXTERNAL_PAGE_ARTIFACTS` on the orchestrator, `ingestDir` on `POST /runs`, `wp dbvc content-migration ingest validate\|run`. Validation runs at stage time so a malformed capture refuses the run rather than half-populating it. REST is closed by default behind `DBVC_CC_INGEST_ROOT`. | Done. 22 tests, 70 assertions; the discriminating one is that an ingest run makes **zero** HTTP requests. Found two latent defects: levelled headings crashed three consumers, and the run profile never persisted `urls`. |
| **S1c-a** | ✅ **Delivered 2026-10-16.** The boundary is loaded by the add-on bootstrap and `DBVC_CC_V2_Interpretation_Bridge_Service` is the adapter §7.2 describes: live target field catalog → `corpus-target-schema.v1` in the same `target_ref` vocabulary the finalizer parses, captured artifacts → `interpretation-input.v1` with kinds read from `elements.v2` rather than guessed, site chrome excluded, head metadata offered. Behind `dbvc_cc_flag_model_interpretation`, off by default. | Done. 13 tests, 64 assertions, every branch with no network and no key. It did **not** close D-20: nothing called the adapter. |
| **S1c-b** | ✅ **Delivered and measured 2026-10-20.** The caller (D-20). `DBVC_CC_V2_Mapping_Interpretation_Service` runs in the orchestrator's mapping stage when the flag is on, persists `interpretation-result.v1` beside the mapping artifacts, and `DBVC_CC_V2_Mapping_Index_Service` gained a candidate-ranking seam in `finalize_item()` — a boundary, not a rewrite of 1,330 lines — so the interpreter supplies the candidates for the items it answered. A declined item becomes a typed `declined_by_interpreter` unresolved item; an unanswered one keeps the lexical candidates; a failed call keeps V2's scorer and records a **named** reason. The `dbvc_cc_v2_interpretation_options` filter injects a transport for tests and cannot carry an `api_key`. | Done. **G-4/G-5 measured on the wired path** (§6.1c): model 100% field accuracy and **G-5 pass at 0% wrong**, against the product's own lexical scorer at 6.7% on the same run; G-4 fails at 20–33% on the un-recalibrated 0.92 threshold. Flag-off behaviour **proven** byte-identical, not asserted. 10 behaviour-named tests; the discriminating ones are the transport-injected no-network run, the validation failure that names `invalid_response_shape`, and the `model` key that exists only when a model ran. |

**S1b was the decision point, and it resolved in the product's favour.** The test was whether a
connected model beats the §6.2 floor on G-4 and G-5 by a margin worth the cost. It does, by a
distance that is not close: 100% field accuracy against 4.8%, zero wrong fields, G-5 passing for
the first time. Had it gone the other way, the right answer would have been to stop calling this
an automation-first product and reposition it as an assisted mapping workbench — a legitimate
outcome, and much cheaper to discover at S1 than at phase 64. It is recorded here because the
question was real when it was asked, and because the next person should see that the sequence was
built to be able to fail at slice two.

### 9.2 Remaining, in priority order

**Re-ordered 2026-10-16.** The original sequence put S3 — more corpus slots — next. That was
wrong, and D-20 and D-21 say why: neither of the two boundaries S1 and S2 built is connected to
anything. More slots would measure a chain that does not exist end to end, so every number they
produced would describe parts rather than a product, and G-4's threshold would be calibrated
against a path no run can take.

The binding constraint moved. It was *"does a model beat the floor?"*, which S1b answered. It is
now *"can anything reach the model?"*

**Status, 2026-10-20:** both joints are closed. S2c joined acquisition — the skill's artifacts
reach the pipeline and an ingest run fetches nothing — and S1c-b joined interpretation: a run with
the flag on produces model-authored recommendations, and G-4/G-5 now have numbers from a run
rather than from an evaluator (§6.1c). The binding constraint moves again: it was *"can anything
reach the model?"*, and it is now *"is the product's own pipeline wide enough to carry the model's
answers?"* — §6.1c's second finding, where two of nineteen answers had no mapping item to attach
to and `meta:_seo_robots` has no item at all. That is **S1d** territory, not a new slice.

Priorities are what blocks what, not what is most interesting:

| | Slice | Scope | Done when |
|---|---|---|---|
| **P0** | **S4c** | **Prove G-7.** Execute → rollback, byte-identical, on a disposable clone. Rollback is unit-covered today — 58 assertions in `ContentMigrationPhase4ImportExecutorTest` — but no test and no run has shown a real site returning to its prior bytes. Follow the Connected Environments live-verify recipe, which proved exactly this shape of claim against a real fleet. | A clone takes a full Olteanu import and a rollback, and a byte comparison of posts, meta and media shows net zero. **This lands before client content is written to any site anyone cares about.** |
| **P1** | **S1d** | **One URL → many entities** (D-19). `mapping-recommendations.v2` carries a single `recommended_target_object`; slot 1's `/dental-team` must yield five `team_member` records and structurally cannot. Make the recommendation a list, teach entity resolution to resolve a set per source URL, and give the exception queue a way to review a set. A contract change, so it is cheaper before slots 2–5 author ground truth against the current shape. **Carry §6.1c's second finding into this slice**: the mapping index builds one headline, one body and one CTA per section and only title/description as head items, so it drops interpreter answers it has nowhere to put (counted as `interpretation.unjoined_recommendation_items`) and can never reach a field like `meta:_seo_robots`. Widening the item set is the same shape of change as widening the recommendation. | `/dental-team` produces five `team_member` entities, each with its own name, role and bio, and the package commits them as five posts, and `unjoined_recommendation_items` is zero on slot 1's homepage. |
| **P1** | — | **The unit-economics baseline.** Time one migration by hand, then measure operator minutes per page per slice. Still unmeasured, and it is the only number that says whether any of this beats copy-paste. An afternoon's work, and it sets the bar every later slice is judged against. | A recorded per-page manual cost, and a per-slice operator-minutes figure to compare against it. |
| **P2** | **S3** | **Corpus slots 2, 3 and 5** per §8.1, then fix what they break — and only then recalibrate G-4's threshold. The inherited 0.92 sits above where a calibrated model places its confidence, but setting it from the one page it was measured on is how you get a threshold that works on exactly that page. | G-1…G-5 hold on four profiles, with G-4's threshold justified from at least three of them. |
| **P2** | **S6a** | **Flip the runtime default** (D-17). `OPTION_RUNTIME_VERSION` still defaults to `RUNTIME_V1`, so after 32 phases V2 is opt-in and two complete implementations are maintained. Flip it, freeze V1, then delete it. | A fresh install runs V2. V1 is gone, not dormant. |
| **P3** | **S8** | **The migration-planning artifacts** from §11.2, which are missing scope rather than defects: the redirect map (old URL → new URL — the cheapest high-visibility item on this list, and everything needed is already captured), `migration-plan.v1` with a `port \| merge \| rewrite \| drop \| redirect` disposition per URL authored *before* mapping runs, SEO continuity as a reviewable set, and the source-drift re-crawl report. Also connect reviewer overrides to pattern learning. | A run emits a redirect map and a migration plan, and a re-crawl reports which source pages changed since import. |
| **P3** | **S5** | Scale and budget: corpus slot 5 (150+ pages), a per-run budget ceiling with a pre-run estimate and a hard stop on breach, concurrency, resumability. | G-8 holds at 150+ pages, and G-6 determinism is measured rather than assumed. |
| **P3** | **S6** | Cleanup: collapse the flag set, rename tests by behaviour (F-4), cap and rewrite the working-state doc (F-6), fix the stale branch anchor, populate or delete the dead `media_refs` plumbing (D-10). | Flag count in single digits; no test named after a phase number. |

**S2c and S1c were one unit of work, and landed as one.** Neither was independently testable: an
ingest seam with nothing behind it feeds a keyword scorer, and a wired interpreter with no ingest
path reads artifacts from the capture service Q5 retired. Landing them together is what made
§6.1c's numbers mean something about the product rather than about parts of it.

---

## 10. Keep / freeze / delete

| Verdict | Items |
|---|---|
| **Keep and protect** | Target field catalog, slot graph, target object inventory, media candidate track, pattern learning, `value_contract` validation, entity resolution, exception queue + inspector + conflicts tab, package build/QA/signed manifest/ZIP, dry-run, preflight token, guarded execute, journal tables, auto-rollback, run history, the attribute scrub policy with its policy hash, the `_source` runtime guard, the artifact traceability block |
| **Replace** | URL discovery, page capture, element extraction, section segmentation, head-metadata extraction — all of acquisition |
| **Connect** | The four AI-layer services. They have the right seams; they have no engine |
| **Freeze then delete** | The V1 runtime and its OpenAI path, once S1 supersedes it |
| **Delete** | QA fixture services from the production bootstrap; dead `media_refs` plumbing or populate it |
| **Rewrite** | `MIGRATION_MAPPER_V2_WORKING_STATE.md` under a line cap; phase-numbered test names; the `docs/ROADMAP.md` Content Migration row |
| **Demote to history** | `MIGRATION_MAPPER_V2_*` as a planning entry point — accurate about V2, no longer the plan |

---

## 11. Decisions that belong to the product owner

These are not implementation details. Each one changes what gets built, and an implementer who
guesses will guess wrong. Recommendations are given, but the call is the owner's.

### 11.1 The four that block planning

**Q1 — What is the deliverable of a "migration"?**
Text landed in ACF fields is not a rebuilt page. Someone still has to construct the Bricks layout.
If that someone is a human working afterwards, then this product's output is *structured source
content*, not a finished site — and the whole pipeline should be honest about that, in its naming,
its UI, and its acceptance criteria. If instead the goal is a rendered page, a layout-generation
stage is missing from all 20 of V2's stages and is a larger body of work than everything described
here.
*Recommendation:* declare it "structured content + a migration plan", explicitly not a built page.
Rename accordingly. Layout generation becomes a separate product question.

**Q2 — Internal studio tool, or a product you sell?**
V2's 32 phases, reviewer workspace, bulk-review controls, and run-history UI are product-grade
investment. The actual usage is a handful of client onboardings a year, operated by the person who
commissioned it. Those imply very different builds: a tool leans on the CLI and the operator's
judgment and needs almost none of the reviewer UI; a product needs user docs, a support story, an
update channel, and D-16 (framework coupling) becomes a blocker rather than a note.
*Recommendation:* internal tool for 12 months. Build the CLI out, stop extending the React
workspace, revisit once G-4/G-5 hold on six corpus sites.

**Q3 — Is crawling the primary path or the fallback?**
For WordPress-to-WordPress work — most of the studio's book — admin or DB access beats crawling on
every axis: real `post_content`, the actual media library, the SEO plugin's stored meta, revision
history, menu structure. Crawling is what you do when access is refused or the source is not
WordPress. Positioned as the primary path, this product competes with its own cheaper alternative.
*Recommendation:* make a WP-native path (export / direct read) the default when credentials exist,
and the crawl path the explicit fallback. Add the decision to the run-start flow so the operator
answers it once, consciously.

**Q4 — Who operates a run, and what do they see when it fails midway?**
Page 31 of 60 returns a 403. Does the operator resume, accept a partial package, or restart? V2 has
partial/resume markers in the artifacts but no coherent operator narrative on top of them.
*Recommendation:* define the three failure UX cases (unreachable page, model over budget, target
schema changed mid-run) before S2, and make partial-success the default with an explicit gap report.

**Q5 — Should V2's own capture path be repaired, or retired?** ✅ **DECIDED 2026-10-14: retired as primary, kept as a declared best-effort fallback.** The gates were re-pointed at the acquisition skill with thresholds unchanged. The reasoning below stands as the record; the decisive fact is that G-2 requires every image with its alt text, and CSS background imagery — 8 of 18 images on the measured site, including all three homepage hero slides — exists in no HTML, no `srcset` and no `data-src`. No amount of PHP reaches it, so the in-WordPress path could never pass G-2 at any effort. Repair would have won G-3 and most of G-2's text clause and still failed the gate.
This one surfaced while finishing S4 and it is not answered anywhere in this guide. G-2 and G-3
measure V2's in-WordPress extractors, and they cannot pass while D-5, D-6, D-7, D-8 and D-18 stand.
But those five defects are **in no slice**: S4 deliberately covered safety and correctness debt, not
extraction fidelity, and §7.1 argues acquisition belongs outside PHP — which implies V2's capture
path should be *retired*, not repaired.

Repairing it means reimplementing in PHP what the acquisition skill already does: div-wrapped copy,
inline markup retention, heading levels, the full head set, lazyload `data-src`, and CSS
backgrounds that PHP cannot reach at all. That is the duplication §7 exists to avoid, and the last
of those five is not fixable in PHP under any effort.

*Recommendation:* retire it. Mark `POST /runs` with a `sitemapUrl` as deprecated-but-supported,
make the external-inventory path the documented one, and **re-point G-2 and G-3 at the acquisition
skill's output** rather than at V2's extractors. The gates would then measure the path the product
actually uses. Keep D-5 through D-18 in the register as a description of the retired path, not as a
backlog. If instead the in-WordPress path must keep working — because a run should be startable
from the admin with no agent involved — then those five defects need a slice of their own and the
duplication is the price.

Until this is answered, G-2 and G-3 are measuring something the product may be about to stop
using, and the 74.5% figure should not be read as a work item.

### 11.2 Scope that is missing entirely

Not defects — things nobody specified, which a migration product needs.

| Gap | Why it matters | Recommendation |
|---|---|---|
| **Redirect map** | The only part of a migration a client reliably notices going wrong is lost traffic. Old URL → new URL, with slug preservation where possible, is not among V2's 20 stages — and the tool already captures everything needed to generate it. | Add as a first-class package artifact. Cheap, high-visibility. |
| **Per-URL editorial disposition** | A rebuild rarely wants 1:1 content. It wants: port this, merge these two, rewrite this, drop that, 301 the rest. That decision currently has nowhere to live, so every page gets mapped whether or not it should survive. | A `migration-plan.v1` artifact with `port \| merge \| rewrite \| drop \| redirect` per URL, authored before mapping runs. Arguably more valuable than the mapping itself, and far cheaper. |
| **SEO continuity** | Captured meta titles/descriptions should be carried over or deliberately rewritten, not silently dropped. D-8 means they are not even collected today. | Fold into the package as a reviewable set. |
| **Source-drift reporting** | A rebuild takes weeks; the client keeps editing the old site. `_dbvc_cc_source_hash` exists for convergence but nothing reports "11 source pages changed since import". | A re-crawl diff report. This is the difference between a script and a tool. |
| **Reviewer decisions feeding pattern learning** | Pattern learning is real and wired, but operator overrides are the highest-quality training signal in the system and it is unclear they reach it. | Connect overrides to pattern memory. Per-client improvement is the actual moat. |
| ~~**Model output caching**~~ **RESOLVED 2026-10-15 (S1b)** | A rerun should not re-pay for unchanged pages. | `DBVC_CC_Interp_Service` caches on prompt version + resolved provider + resolved model + payload. Keying on the *resolved* model matters: an earlier cut keyed on the raw option, which is empty when the caller takes the default, so every default-model run collided on one entry and reported a stale score. Self-test assertion 12 pins it. |

### 11.3 Risk, cost, and compliance

| Item | Position to take |
|---|---|
| **Unit economics** | Nobody has timed a manual migration. Without that baseline there is no way to say this tool saves anything. *Time one by hand before S1*, then measure operator minutes per page per slice. If a 60-page site still costs six review hours, the tool has not yet beaten copy-paste. |
| **Run budget** | Declare a credit and token ceiling per run, show the estimate before starting, hard-stop on breach (G-8). Firecrawl's free tier is 1,000 credits/month — one careless large run spends a month. |
| **Authorization record** | Crawling a client's own site on their instruction is unremarkable; the tool keeps no record of the instruction. Store a per-run attestation (who authorized, when, which domain) in the run profile. Cheap insurance. |
| **Explicit misuse boundary** | The obvious misuse is pointing this at a competitor's site and importing their content. Write the prohibition into the add-on's docs and the run-start copy. It is a copyright problem, not a technical one. |
| **PII and retention** | Crawled artifacts hold staff names, emails, phone numbers, testimonials, form structures. Today: flagged by count, not redacted; stored under `uploads`; web-reachable on nginx (D-11); never pruned (D-14); survive uninstall. Set a retention window with hard delete, redact emails and phones in *stored artifacts* by default, and fix D-11 before any client data is collected on a production host. |
| **Adversarial sources** | Cloudflare, bot protection, login walls, geo-blocks. Firecrawl has proxy modes; decide the policy rather than discovering it per client. Never bypass a paywall or an auth wall — if the content is gated, ask the client for access. |
| **Multi-client isolation** | Artifacts are keyed by domain inside one WordPress install. Decide whether the toolbox site is separate from every client production site. It should be. |
| **Package portability** | The signed manifest + ZIP is nearly a portable, documented format. Finishing and versioning it independently of DBVC is a trust asset — a client can take their content elsewhere. |

### 11.4 If you only answer three

**Q1** (what the deliverable is), **Q2** (tool or product), **Q5** (repair or retire V2's capture path — it decides what G-2 and G-3 even measure), and the **unit-economics baseline**.
Those three determine whether S1 through S6 is the right sequence at all. Everything else in this
section can be decided during the build.

---

## 12. Appendix

### 12.1 Trial data

`/Users/rhettbutler/Documents/LocalProjects/dbvc-content-migration-trials/mirceaolteanudds.com/2026-10-10/`

| Path | Contents |
|---|---|
| `source-html/` | Raw HTML as served (`home.html` 206KB, `team.html`). Authentic bytes; basis for every `content_hash` |
| `firecrawl/` | `map.json` (discovery with no sitemap), `<slug>.md`, `<slug>.meta.json` |
| `dbvc-artifacts/` | Generated `domain-url-inventory.v1` + per page `page-artifact.v1`, `elements.v2`, `sections.v2`, `ingestion-package.v2` |
| `tools/fc_to_dbvc.py` | Firecrawl payload → DBVC artifacts; ports the PHP identity rules |
| `tools/coverage.py` | Measures what DBVC's DOM selectors reach, with a real parser |
| `measurements/` | Recorded selector-coverage output |
| `FINDINGS.md` | The decision write-up the trial produced |
| `README.md` | Layout, reproduce command, provenance and use restrictions |

Kept outside this repository deliberately: it is a third party's copyrighted content and does not
belong in plugin git history. Promote it to `addons/content-migration/tests/corpus/` at S0 only in
the reduced, artifact-only form the eval harness needs.

The markdown in `firecrawl/` was transcribed from the MCP response, so its text hashes are not
byte-identical to a fresh scrape. The HTML in `source-html/` is authentic.

### 12.2 Running the corpus evaluator

```bash
composer corpus:baseline     # report everything, always exit 0
composer corpus:eval         # exit 1 if a measurable gate fails - CI form
composer corpus:eval-json    # machine-readable
```

Fixture bytes are not committed. Point the evaluator at them with `DBVC_CORPUS_FIXTURES`, or rely
on the slot's `fixtures.default_root`. With the bytes absent, G-2 and G-3 report `not_measured` and
G-1 still runs. Contract and instructions for adding a slot:
`addons/content-migration/tests/corpus/README.md`.

### 12.3 Measuring the model (S1b)

```bash
export DBVC_CC_ANTHROPIC_API_KEY=...        # or define it in wp-config.php; never in wp_options
composer corpus:eval-model                  # G-4/G-5 for the model
composer corpus:baseline                    # the floor, for comparison
```

Other entry points:

```bash
composer corpus:self-test       # 12 assertions: D-3 guards, the model path, all five fallbacks, caching
composer corpus:verify-scorer   # pushes a perfect interpreter through the real scorer; calibrates the harness
php .../run.php --interpreter=model --provider=openai --model=gpt-4o
```

The bench above measures the boundary. To measure **the wired path** — the same page through the
ingest seam, the live catalog, the bridge, the mapping index's seam and the assignment gates, which
is what §6.1c reports:

```bash
DBVC_CC_CORPUS_MEASURE=1 DBVC_CC_OPENAI_API_KEY=... \
  vendor/bin/phpunit --filter ContentMigrationCorpusWiredMeasurementTest --do-not-cache-result
```

It runs the flag-off lexical scorer and the model over the same page and prints both, so the
comparison is same-run. It is skipped without `DBVC_CC_CORPUS_MEASURE=1` because it costs money,
and skipped without the corpus fixture bytes, which are not committed.

Defaults and deliberate choices:

- **Provider:** Anthropic, `claude-sonnet-5-5`. Not V2's `gpt-4o-mini`, which was never revisited
  across 32 phases. OpenAI is a first-class adapter; pass `--provider=openai`.
- **Key handling:** a PHP constant, then the environment. A `wp_options` value is last resort —
  D-12 was a plaintext key in the database with no override.
- **Strict validation:** a hallucinated `field_ref` or an unknown `item_id` rejects the *whole*
  response and the floor runs. Partial trust in a model response is how wrong mappings reach a
  package.
- **Named fallbacks:** `no_api_key`, `over_budget`, `transport_error`, `invalid_response_shape`.
  V2's single `fallback_mode` flag could not distinguish them, and its artifacts claimed a model
  ran either way.
- **Caching** keyed on prompt version plus payload, so a rerun is free and a prompt change never
  reuses an old answer.
- **Budget ceiling** refuses an oversized prompt before spending anything (G-8).

### 12.4 Reproducing the measurements

```bash
cd /Users/rhettbutler/Documents/LocalProjects/dbvc-content-migration-trials/mirceaolteanudds.com/2026-10-10
python3 -I tools/coverage.py source-html/home.html=homepage source-html/team.html=meet-the-team
python3 -I tools/fc_to_dbvc.py . ./dbvc-artifacts-rebuild \
  ccext_mirceaolteanudds-com_20261010T000000Z_pilot mirceaolteanudds.com
```

### 12.5 Verifying the Section 3.1 finding yourself

```bash
grep -rn "wp_remote_post\|call_openai\|openai" addons/content-migration/v2/
```

One hit, in `v2/shared/dbvc-cc-v2-contracts.php` — a settings read that copies a model name into
an artifact. No outbound model call exists anywhere in the V2 runtime.

### 12.6 Related documents

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_OVERVIEW.md` — the original vision. Still the
  best statement of intent; Section 2 explains the contradiction in its Stage 2.
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CONTRACTS.md` — artifact contracts. Accurate,
  and V3 writes to them.
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md` — V2's resume anchor. Its
  branch reference is stale (see 5.1).
- `docs/reference/import-authoring/` — the agent-facing import contract, unaffected by this guide.
