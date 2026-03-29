# Migration Mapper V2 Crawl Reuse Audit

## Purpose

This audit records which existing Content Collector and crawl primitives are still actively reused by V2, which legacy operator surfaces are intentionally dormant, and which reuse boundaries were established before the dedicated V2 crawl-start UI landed.

Use it to avoid re-reading both the V1 and V2 runtime trees when confirming crawl reuse boundaries or extending run-start behavior.

## Audit Summary

Most of the backend crawl and collection primitives already align with V2.

The current V2 runtime already reuses:

- crawl settings defaults and override sanitization
- sitemap parsing
- domain or page artifact storage, pathing, and directory hardening
- CSS selector and URL normalization helpers
- deterministic extraction and ingestion packaging primitives
- target schema snapshot and field-catalog primitives
- package-first import planning and execution guardrails

What V2 does not reuse, and should not revive, is the legacy V1 operator transport:

- the V1 `Collect` tab
- the V1 `admin-ajax` crawl loop
- the legacy collect-page JavaScript

That boundary is now implemented: the V2 `runs` workspace owns run-start, backed by the existing V2 `/runs` REST contract and shared crawl primitives, without reviving the V1 UI stack.

## Confirmed Active Reuse

### 1. Crawl settings and per-run override primitives

Actively reused by V2:

- `DBVC_CC_Settings_Service::get_options()`
- `DBVC_CC_Crawler_Service::sanitize_crawl_overrides()`
- `DBVC_CC_Crawler_Service::get_effective_crawl_options()`

Current role in V2:

- V2 still starts from the shared Content Collector defaults registered in Configure.
- V2 capture and discovery services already consume the same crawl override model that the V1 collect flow used.
- `POST /dbvc_cc/v2/runs` now accepts `crawlOverrides`, so the reusable override plumbing is no longer trapped below the V2 REST layer.

### 2. Sitemap discovery and crawl-entry primitives

Actively reused by V2:

- `DBVC_CC_Crawler_Service::parse_sitemap()`

Current role in V2:

- `DBVC_CC_V2_URL_Inventory_Service` uses the shared sitemap parser for recursive sitemap expansion.
- V2 then applies its own URL normalization, dedupe, and scope decisions on top of those discovered URLs.

### 3. Artifact storage, pathing, and hardening primitives

Actively reused by V2:

- `DBVC_CC_Artifact_Manager::ensure_storage_roots()`
- `DBVC_CC_Artifact_Manager::get_storage_base_dir()`
- `DBVC_CC_Artifact_Manager::prepare_page_directory()`
- `DBVC_CC_Artifact_Manager::get_page_dir()`
- `DBVC_CC_Artifact_Manager::get_slug_from_url()`
- `DBVC_CC_Artifact_Manager::write_json_file()`
- `dbvc_cc_create_security_files()`
- `dbvc_cc_path_is_within()`

Current role in V2:

- V2 journey, inventory, page, and package artifacts all still rely on the shared deterministic storage layout and path safety model.
- This reuse is healthy and should continue.

### 4. Low-level selector and URL helpers

Actively reused by V2:

- `dbvc_cc_css_to_xpath()`
- `dbvc_cc_convert_to_absolute_url()`

Current role in V2:

- V2 page capture uses the same selector conversion and absolute-URL handling primitives used by legacy crawl logic.
- This keeps focus/exclude selector behavior aligned across runtimes even though V2 writes a different artifact family.

### 5. Deterministic extraction and ingestion primitives

Actively reused by V2:

- `DBVC_CC_Element_Extractor_Service::extract_artifacts()`
- `DBVC_CC_Section_Segmenter_Service::build_artifact()`
- `DBVC_CC_Ingestion_Package_Service::build_artifact()`

Current role in V2:

- V2 raw page capture is its own service, but extraction, sectioning, and ingestion packaging already reuse the mature deterministic primitives instead of reimplementing them.

### 6. Target schema and downstream import primitives

Actively reused by V2:

- `DBVC_CC_Schema_Snapshot_Service`
- `DBVC_CC_Target_Field_Catalog_Service`
- `DBVC_CC_Import_Plan_Service`
- `DBVC_CC_Import_Executor_Service`
- `DBVC_CC_Import_Run_Store`

Current role in V2:

- V2 already bridges into the shared schema snapshot/catalog, dry-run, preflight, execute, journaling, and rollback stack.
- This confirms the landed crawl-start UI did not need to invent a new downstream execution path.

## Intentionally Dormant V1 Surfaces

These existing surfaces are not the correct reuse boundary for V2:

- `DBVC_CC_Admin_Controller`
- `collector/views/tabs/dbvc-cc-tab-collect.php`
- `collector/assets/dbvc-cc-crawler-admin.js`
- `DBVC_CC_Ajax_Controller`
- V1 `dbvc_cc_get_urls_from_sitemap`
- V1 `dbvc_cc_process_single_url`
- V1 `dbvc_cc_trigger_domain_ai_refresh`

Why they should stay dormant:

- They are shaped around the legacy tabbed admin UI and `admin-ajax` request loop.
- V2 already has a cleaner run-based REST contract.
- Reusing these V1 transport layers would reintroduce a second operator model and split runtime behavior.

## Confirmed Misalignment and Operator Gaps

### Closed in this tranche

- The V2 run-creation route previously did not expose crawl overrides even though the shared sanitizer and effective-option resolver were already reused below the route layer.
- That gap is now closed by accepting `crawlOverrides` on `POST /dbvc_cc/v2/runs`.

### Closed by later phases

- V2 now provides a first-class in-app crawl-start form in the `runs` workspace.
- Operators no longer need a direct REST call to start a crawl-backed run.
- The V2 UI now provides request lifecycle, progress, and error presentation for run creation.

### Current follow-on focus

- Crawl-start reuse is no longer the main operator gap.
- The current blocker has shifted to reviewability: human-readable schema labels, conflict-first exception handling, explicit recommendation decisions, and clearer readiness-to-review shortcuts.

### Not considered a blocker

- V2 page capture does not reuse `DBVC_CC_Crawler_Service::process_page()` directly.
- This is acceptable because V2 writes a different artifact family and has a different orchestration model.
- The healthy reuse boundary is low-level crawl helpers and deterministic extraction primitives, not the legacy V1 page-artifact writer.

## Landed Crawl-Start Boundary

The V2-native crawl-start surface follows these boundaries:

- place the UI in the V2 `runs` workspace
- call `POST /dbvc_cc/v2/runs`
- use the existing payload shape:
  - `domain`
  - `sitemapUrl`
  - `maxUrls`
  - `forceRebuild`
  - `crawlOverrides`
- prefill advanced controls from the shared Configure defaults
- keep Add-ons and Configure settings server-rendered
- do not revive the V1 collect tab, V1 AJAX handlers, or V1 collect-page JavaScript
- keep V2 orchestration behind the existing schema-sync -> capture -> AI pipeline route

## Implementation Result

Answer to the Phase 9 audit question, as confirmed by later implementation:

- most of the existing Content Collector and crawl backend functionality still aligns and already works with V2
- most of the legacy V1 crawl operator UI does not align with V2 and should remain dormant
- the correct next step was a dedicated V2 crawl-start UI tranche, not a reactivation of the legacy collect flow
- that V2-native crawl-start surface is now in place, so follow-on work should stay focused on reviewability and operator actionability instead of reopening crawl transport decisions
