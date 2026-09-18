# R5.later-perf — TTFB investigation (resumption, 2026-09-17)

**Question the phase paused on (2026-09-07):** every request on the LocalWP dev site carried a uniform ~3.7 s server think-time, unexplained after ruling out Xdebug, `WP_DEBUG_LOG`, autoload bloat and object caching. The frontend audit's 8-scenario sweep could not produce meaningful numbers on top of it.

**Answer:** the floor is **not** environmental and **not** DBVC's. It is the **Bricks dynamic-data tag registry being rebuilt on every request at `init`** — ≈ 3.5 s on this site's ACF schema — made of two halves that both scale with schema size. It will be present on any host running this theme/schema combination, production included, unless page-cached.

Probes used are checked in under `R5-LATER-PERF-TTFB/` (WP-CLI `--require` files and one `eval-file`; all read-only). Numbers below are from the LocalWP site shell (PHP 8.4.4, WP-CLI 2.10.0), single runs unless stated; CLI runs compile PHP each time, so include-phase numbers are higher than PHP-FPM with a warm opcache — the `init` numbers are not affected by that.

## 1. Where the time is

Server-side timing with `curl` (no browser, no DBVC frontend involved):

| Request | TTFB |
|---|---|
| static file (`workspace-app.js`) | 13 ms |
| `/wp-json/` (WordPress boot, no theme render) | **4.06 – 4.09 s** |
| `/wp-login.php` | 3.86 s |
| `/` (homepage, anonymous, full Bricks render) | 13.6 – 14.9 s |

DNS/TLS are negligible (`/etc/hosts` entries present). So ~3.9–4.1 s is spent before any controller runs, on **every** request — including every Visual Editor REST call, since Bricks registers its providers for REST requests too (`bricks_is_rest_call()`).

WordPress boot stages (`wp-boot-stage-timer.php`, all plugins + theme):

| Stage | Δ | Notes |
|---|---|---|
| → `muplugins_loaded` | 1 136 ms | WP core + WP-CLI (CLI compile) |
| plugin file includes | 1 327 ms | DBVC 829 ms cold-compile; ≈ 65–156 ms with a warm opcache (see §4) |
| `plugins_loaded` listeners | 64 ms | |
| theme includes → `after_setup_theme` | 878 ms | Bricks + Vertical `functions.php` |
| `after_setup_theme` listeners | 591 ms | Vertical `vf_maybe_boot_dynamicqr_feature` 447 ms, `vf_maybe_boot_gardenai_v2_feature` 102 ms |
| **`init` listeners** | **3 874 – 4 010 ms** | see below |
| `wp_loaded` | 1 ms | |

`init` listeners by cost (`wp-hook-listener-profiler.php`, inclusive):

| ms | Listener |
|---|---|
| **3 517** | `Bricks\Integrations\Dynamic_Data\Providers::register_tags` (`themes/bricks/includes/integrations/dynamic-data/providers.php:213`) |
| 165 | `Bricks\Elements::init_elements` |
| 89 | `BricksExtras\Plugin::bricksextras_init` |
| 48 | `Bricksable_Settings` closure |
| 27 | `Providers::register_providers` |
| < 20 each | everything else — every DBVC listener included |

## 2. Root cause, in two halves

`Provider_Acf::register_tags()` = `get_fields()` + one `register_tag()` per field. On this site the ACF schema (registered by the Vertical theme) is **49 field groups, 525 top-level fields (284 `group`, 147 `tab`, …), 3 288 nested sub-fields → 3 561 field loads → 2 700 dynamic-data tags**.

**(a) Cold ACF field materialisation — ≈ 2.0 s.** `acf_get_fields()` for every group, cold, is 1 964 – 2 012 ms (`acf-cold-field-load-profiler.php`); warm it is 126 ms. The cost is ACF's own per-field pipeline (`acf/load_field` × 3 561 with `acf_field__group::load_field` recursing into sub-fields, `acf/validate_field`, four theme/plugin `acf/load_field` listeners at ≤ 23 ms each). ACF keeps no cross-request cache for PHP-registered field definitions, so this repeats on every request. Note: ACF PRO is loaded from **Bricks Advanced Themer's bundled copy** (`plugins/bricks-advanced-themer/plugins/acf-pro/`).

**(b) Bricks' nested-group lookup is quadratic — ≈ 1.5 s.** For every nested sub-field, `Provider_Acf::get_nested_parent_group_field_data()` walks *every* registered tag and *its* sub-fields to find the parent group's tag (`provider-acf.php:81–96`), recursing per nesting level. With 2 700 tags and 3 288 nested sub-fields that is the bulk of a warm `register_tags()`: **1 549 – 1 674 ms** on a warm instance.

Proof for (b): `bricks-acf-provider-index-probe.php` builds a runtime subclass whose lookup answers from a `sub-field key → tag name` index maintained at the two `$this->tags[ $name ] = $tag;` sites (the stock `register_tag()` body is read from the installed theme file and reused verbatim). Result, three runs:

```
Bricks 2.3.8 | stock register_tags: 1549–1674 ms | indexed: 51–53 ms
tags 2700 vs 2700 | same keys + order: yes | differing tag records (strict): 0 | loop tags identical: yes
```

**30× faster, byte-identical output.** Swapping the live provider instance between Bricks' `init@10000` (`register_providers`) and `init@10001` (`register_tags`) via reflection was also validated in the real boot sequence: the swapped provider produced the identical registry and `init` dropped from ≈ 4.0 s to ≈ 2.5 s — the remaining ≈ 2.0 s being half (a).

## 3. What was ruled out (this pass)

- Not DNS / TLS / nginx: static files 13 ms.
- Not DBVC listeners: none above 20 ms on any boot hook.
- Not the plugin-count myth: skipping any single third-party plugin changes boot by < 0.5 s. (Skipping DBVC *appeared* to save 6 s, but only because the Vertical theme fatals without DBVC and the run aborts at `setup_theme` — that measurement is void.)
- Not ACF local JSON parsing: the groups are PHP-registered; `acf_get_field_groups()` is 3–7 ms.

## 4. Second-order findings

- **DBVC main-file include: 829 ms in cold CLI**, 4× the next plugin (ws-form-pro 219 ms). With a warm opcache (approximated via `opcache.file_cache`) it drops to 65–156 ms, so on PHP-FPM it is real but small. Worth a later look at what `db-version-control.php` executes at include time (127 bootstrap includes, 16 initializers), not a blocker.
- **Vertical theme** `vf_maybe_boot_dynamicqr_feature` 447 ms + `vf_maybe_boot_gardenai_v2_feature` 102 ms on `after_setup_theme` — cross-repo (Vertical), noted for the theme owner.
- Bricks sprays PHP warnings from `bricks/includes/elements/base.php` per request (pre-existing observation); unrelated to the timing.

## 5. Consequences for R5.later-perf and R5.later-cache

- Every `dbvc.ve.api.*` span in the 2026-09-07 captures sits on a ≥ 3.9 s floor that has nothing to do with the Visual Editor. The frontend sweep as designed (8 scenarios × 5 runs) would measure Bricks + ACF, not the drawer. **Do not run the sweep until the floor is < ~0.5 s on the measuring host.**
- R5.later-cache's premise ("drawer hydration rounds are expensive, cache them client-side") is partially answered: the rounds are expensive because *each is a WordPress boot*, not because of payload or client work. A client cache would hide the floor for repeat views but not fix it; the floor must be fixed first, then the cache question re-asked against real numbers.
- Production relevance: the Vertical theme ships the same schema, so any production host without full-page caching for logged-in editors pays the same floor on every editor request. Worth measuring `curl -w '%{time_starttransfer}' https://<prod>/wp-json/` while logged out (page cache bypass not needed for `/wp-json/`).

## 6. Options (maintainer decision)

| # | Option | Gain on this site | Where it lives | Risk |
|---|---|---|---|---|
| A | **Report (b) upstream to Bricks** with the indexed lookup (patch is ~40 lines in `provider-acf.php`; the probe is the proof) | −1.5 s | Bricks | none for us; timing unknown |
| B | **DBVC opt-in shim for (b)** — `R5.later-perf-a`: the Bricks addon swaps `Providers::$providers['acf']` for the indexed subclass between `init@10000` and `@10001` via reflection; version-pinned to tested Bricks versions, kill switch default off, self-check on activation (registers both, compares strictly, refuses to stay on if they differ) | −1.5 s per request site-wide | DBVC Bricks addon | relies on Bricks private statics — mitigated by the version pin + self-check + no-op fallback |
| C | **Cache the finished tag registry** (half (a) + (b) together) — a transient of the ACF provider's tags keyed on a schema fingerprint (field-group keys + `modified`, theme version, Bricks/ACF versions) | −3.5 s per request | DBVC shim or upstream | invalidation must be exact for PHP-registered fields (theme deploy); payload several MB; design-first |
| D | **Reduce what Bricks registers** — the undocumented `bricks/acf/filter_field_groups` filter, scoped to field groups actually referenced by `{acf_*}` tags in Bricks templates (the DBVC Bricks addon already parses templates) | up to −3.5 s depending on real usage | Vertical / DBVC | breaks rendering if the inventory misses a used group — needs a verifier |
| E | Full-page/edge cache for editors | hides it | host | not a fix |

**Outcome (2026-09-17):** A + B landed as R5.later-perf-a — `releases/R5.LATER-PERF-A-BRICKS-ACF-TAG-INDEX.md`, upstream draft `R5-LATER-PERF-TTFB/UPSTREAM-BRICKS-REPORT.md`. C landed as R5.later-perf-b — `releases/R5.LATER-PERF-B-BRICKS-ACF-FIELDS-CACHE.md` (`init` 4.99 s → 0.53 s with both). D/E not pursued. The frontend sweep then ran with both switches on (E-162) — `R5-LATER-PERF-AUDIT-REPORT.md`: half (a) resurfaces on the control-center path (the Vertical provider's `acf_get_field()` calls re-materialise ACF's store per request), which is what perf-c must address.

**Recommendation (as made):** A + B together (B ships the same code A proposes, behind a flag, so the site benefits now and can drop the shim when Bricks lands it), then re-measure `/wp-json/` TTFB; if ≈ 2.5 s is still too high for the audit, design C. Only then resume the frontend sweep (`R5-LATER-PERF-MEASUREMENT-RECIPE.md`) — its recipe and instrumentation remain valid.

## 7. Reproduce

Inside the LocalWP site shell (Local → site → Open site shell), from the site root:

```bash
wp eval '1;' --require=wp-content/plugins/db-version-control-main/docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-TTFB/wp-boot-stage-timer.php
wp eval '1;' --require=wp-content/plugins/db-version-control-main/docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-TTFB/wp-hook-listener-profiler.php
wp eval '1;' --require=wp-content/plugins/db-version-control-main/docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-TTFB/acf-cold-field-load-profiler.php
wp eval-file wp-content/plugins/db-version-control-main/docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-TTFB/bricks-acf-provider-index-probe.php
```

`wp-plugin-include-timer.php` attributes the plugin-include phase (CLI compile included). All probes are read-only and print to STDERR.
