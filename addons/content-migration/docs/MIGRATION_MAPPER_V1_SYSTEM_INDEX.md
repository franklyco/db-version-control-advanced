# Migration Mapper V1 System Index

## Purpose

This is the current-state index for the live `Migration Mapper V1` implementation under `addons/content-migration`.

It focuses on the operational surface area that matters for V2 planning:

- modules
- services
- public methods
- hooks and routes
- artifacts
- process vocabulary

Note:
- no custom WordPress filters are currently registered in the addon
- this index focuses on public and operational entry points rather than every private helper method

## Module Dictionary

`bootstrap`
- runtime wiring and module registration

`shared`
- contracts, helpers, module interface, service container

`settings`
- settings registration and sanitization

`schema-snapshot`
- target WordPress schema capture

`collector`
- crawl intake, sitemap parsing, raw capture, artifact storage, admin bootstrapping

`content-context`
- structured extraction, scrub rules, sections, context sidecars, ingestion packaging

`explorer`
- crawl tree inspection, content preview, sidecar inspection, audit views

`ai-mapping`
- AI queueing, page AI processing, status, fallback, legacy mapping suggestions

`mapping-catalog`
- domain-level target field catalog generation and transport

`mapping-workbench`
- review queue, candidate rebuild, mapping decisions, handoff transport

`mapping-media`
- media candidates and media decision persistence

`import-plan`
- handoff assembly and dry-run planning

`import-executor`
- executor dry-run, approval, guarded execute, run history, rollback

`exports`
- stub module boundary only

`observability`
- stub module boundary only

## Core Process Dictionary

`crawl`
- discover URLs and fetch page content into page artifacts

`deep capture`
- produce element-level structured capture in addition to the raw page artifact

`attribute scrub`
- apply deterministic keep, drop, hash, or tokenize rules to element attributes

`section segmentation`
- convert elements into deterministic section groupings

`section typing`
- assign section archetypes such as hero, content, contact, faq, cta, pricing

`context bundle`
- enriched structured context sidecar for later interpretation

`ingestion package`
- import-friendly structured content package built from sections and traces

`AI rerun`
- queue or rerun AI analysis for a page or domain subtree

`review queue`
- queue of low-confidence or conflicting AI mapping suggestions

`mapping candidates`
- deterministic section-to-field candidate mappings for one URL

`media candidates`
- deterministic media inventory and role-hint mappings for one URL

`mapping decision`
- saved reviewer choices for text or field mappings

`media decision`
- saved reviewer choices for media mappings

`handoff`
- combined mapping payload prepared for dry-run planning

`dry-run plan`
- abstract import operations prepared from approved mappings

`executor dry-run`
- deterministic operation graph and write preparation without writes

`preflight approval`
- approval token bound to the current dry-run fingerprint

`execute`
- guarded entity, field, and supported media writes

`rollback`
- recovery of journaled actions from a recorded run

## Public Class and Method Index

### Bootstrap and shared

`DBVC_CC_Addon_Bootstrap`
- `bootstrap()`
- `get_container()`

`DBVC_CC_Service_Container`
- `set()`
- `has()`
- `get()`
- `ids()`

`DBVC_CC_Contracts`
- `get_feature_flag_defaults()`
- `is_feature_enabled()`
- `get_settings_defaults()`
- `get_settings_keys()`
- `ensure_phase_zero_defaults()`

### Settings

`DBVC_CC_Settings_Service`
- `bootstrap()`
- `register_settings()`
- `get_options()`
- `sanitize_settings()`

`DBVC_CC_Settings_Module`
- `get_service_id()`
- `register()`

### Schema snapshot

`DBVC_CC_Schema_Snapshot_Service`
- `maybe_generate_initial_snapshot()`
- `generate_snapshot()`
- `get_snapshot_file_path()`

`DBVC_CC_Schema_Snapshot_Module`
- `get_service_id()`
- `register()`

### Collector and storage

`DBVC_CC_Artifact_Manager`
- `ensure_storage_roots()`
- `get_storage_base_dir()`
- `list_domain_keys()`
- `dbvc_cc_list_domain_relative_paths()`
- `get_domain_key()`
- `get_relative_page_path()`
- `canonicalize_url()`
- `get_slug_from_url()`
- `get_domain_dir()`
- `get_page_dir()`
- `get_json_file_path()`
- `prepare_page_directory()`
- `write_json_file()`
- `update_domain_index()`
- `update_redirect_map()`
- `log_event()`
- `sync_page_to_dev()`
- `is_dev_mode()`

`DBVC_CC_Crawler_Service`
- `__construct()`
- `sanitize_crawl_overrides()`
- `get_effective_crawl_options()`
- `process_page()`
- `parse_sitemap()`

`DBVC_CC_AJAX_Controller`
- `get_instance()`
- `get_urls_from_sitemap()`
- `process_single_url()`
- `dbvc_cc_trigger_domain_ai_refresh()`

`DBVC_CC_Admin_Controller`
- `get_instance()`
- `add_admin_menu()`
- `enqueue_scripts()`
- `render_admin_page()`
- `redirect_legacy_explorer_page()`
- `render_workbench_page()`

`DBVC_CC_Collector_Module`
- `get_service_id()`
- `register()`

### Structured extraction and context

`DBVC_CC_Element_Extractor_Service`
- `extract_artifacts()`

`DBVC_CC_Attribute_Scrub_Policy_Service`
- `get_policy()`
- `get_allowed_actions()`

`DBVC_CC_Attribute_Scrubber_Service`
- `scrub_attributes()`

`DBVC_CC_Section_Segmenter_Service`
- `build_artifact()`

`DBVC_CC_Section_Typing_Service`
- `build_artifact()`

`DBVC_CC_Context_Bundle_Service`
- `build_artifact()`

`DBVC_CC_Ingestion_Package_Service`
- `build_artifact()`

`DBVC_CC_Content_Context_Module`
- `get_service_id()`
- `register()`

### Explorer

`DBVC_CC_Explorer_Service`
- `get_instance()`
- `get_domains()`
- `get_tree()`
- `get_children()`
- `get_node()`
- `get_content_preview()`
- `dbvc_cc_get_content_context_payload()`
- `dbvc_cc_get_scrub_policy_preview_payload()`
- `dbvc_cc_get_scrub_policy_approval_status_payload()`
- `dbvc_cc_post_scrub_policy_approve_payload()`
- `get_node_audit()`

`DBVC_CC_Explorer_REST_Controller`
- `get_instance()`
- `register_routes()`
- `get_domains()`
- `get_tree()`
- `get_children()`
- `get_node()`
- `get_content()`
- `dbvc_cc_get_content_context()`
- `dbvc_cc_get_scrub_policy_preview()`
- `dbvc_cc_get_scrub_policy_approval_status()`
- `dbvc_cc_post_scrub_policy_approve()`
- `get_node_audit()`
- `permissions_check()`

`DBVC_CC_Explorer_Module`
- `get_service_id()`
- `register()`

### AI mapping

`DBVC_CC_AI_Service`
- `get_instance()`
- `queue_job()`
- `queue_branch_jobs()`
- `dbvc_cc_queue_domain_refresh()`
- `dbvc_cc_get_domain_ai_health()`
- `process_job()`
- `get_status()`
- `get_status_by_job_id()`
- `get_status_by_batch_id()`

`DBVC_CC_AI_REST_Controller`
- `get_instance()`
- `register_routes()`
- `queue_rerun()`
- `queue_branch_rerun()`
- `get_status()`
- `permissions_check()`

`DBVC_CC_AI_Mapping_Module`
- `get_service_id()`
- `register()`

### Mapping catalog

`DBVC_CC_Target_Field_Catalog_Service`
- `get_instance()`
- `build_catalog()`
- `get_catalog()`
- `get_catalog_file_path()`

`DBVC_CC_Target_Field_Catalog_REST_Controller`
- `get_instance()`
- `register_routes()`
- `build_catalog()`
- `refresh_catalog()`
- `get_catalog()`
- `permissions_check()`

`DBVC_CC_Mapping_Catalog_Module`
- `get_service_id()`
- `register()`

### Mapping workbench

`DBVC_CC_Workbench_Service`
- `get_instance()`
- `get_review_queue()`
- `dbvc_cc_get_domains()`
- `dbvc_cc_rebuild_domain_mapping_artifacts()`
- `dbvc_cc_rebuild_mapping_artifacts_for_path()`
- `get_suggestions()`
- `save_decision()`

`DBVC_CC_Section_Field_Candidate_Service`
- `get_instance()`
- `build_candidates()`
- `get_candidates()`

`DBVC_CC_Mapping_Decision_Service`
- `get_instance()`
- `get_decision()`
- `save_decision()`

`DBVC_CC_Mapping_Rebuild_Service`
- `get_instance()`
- `dbvc_cc_queue_domain_mapping_rebuild()`
- `dbvc_cc_get_batch_status()`
- `dbvc_cc_process_rebuild_batch_event()`

`DBVC_CC_Workbench_REST_Controller`
- `get_instance()`
- `register_routes()`
- `get_review_queue()`
- `dbvc_cc_get_domains()`
- `get_suggestions()`
- `save_decision()`
- `get_mapping_candidates()`
- `build_mapping_candidates()`
- `get_mapping_decision()`
- `save_mapping_decision()`
- `get_mapping_handoff()`
- `dbvc_cc_rebuild_mapping_domain()`
- `dbvc_cc_get_mapping_rebuild_batch_status()`
- `permissions_check()`

`DBVC_CC_Mapping_Workbench_Module`
- `get_service_id()`
- `register()`

### Mapping media

`DBVC_CC_Media_Candidate_Service`
- `get_instance()`
- `build_candidates()`
- `get_candidates()`

`DBVC_CC_Media_Decision_Service`
- `get_instance()`
- `get_decision()`
- `save_decision()`

`DBVC_CC_Media_REST_Controller`
- `get_instance()`
- `register_routes()`
- `get_candidates()`
- `build_candidates()`
- `get_decision()`
- `save_decision()`
- `permissions_check()`

`DBVC_CC_Mapping_Media_Module`
- `get_service_id()`
- `register()`

### Import plan

`DBVC_CC_Import_Plan_Handoff_Service`
- `get_instance()`
- `get_handoff_payload()`

`DBVC_CC_Import_Plan_Service`
- `get_instance()`
- `get_dry_run_plan()`

`DBVC_CC_Import_Plan_REST_Controller`
- `get_instance()`
- `register_routes()`
- `get_dry_run_plan()`
- `permissions_check()`

`DBVC_CC_Import_Plan_Module`
- `get_service_id()`
- `register()`

### Import executor

`DBVC_CC_Import_Run_Store`
- `get_instance()`
- `maybe_upgrade()`
- `table_name()`
- `create_run()`
- `update_run()`
- `create_action()`
- `update_action()`
- `get_run()`
- `get_run_actions()`
- `list_runs()`

`DBVC_CC_Import_Executor_Service`
- `get_instance()`
- `execute_dry_run()`
- `approve_preflight()`
- `get_preflight_status()`
- `get_run_details()`
- `list_runs()`
- `rollback_run()`
- `execute_write_skeleton()`

`DBVC_CC_Import_Executor_REST_Controller`
- `get_instance()`
- `register_routes()`
- `get_dry_run_execution()`
- `execute_write_skeleton()`
- `approve_preflight()`
- `get_preflight_status()`
- `get_run_details()`
- `list_runs()`
- `rollback_run()`
- `permissions_check()`

`DBVC_CC_Import_Executor_Module`
- `get_service_id()`
- `register()`

### Stubs

`DBVC_CC_Exports_Module`
- `get_service_id()`
- `register()`

`DBVC_CC_Observability_Module`
- `get_service_id()`
- `register()`

## Hook Index

### WordPress actions

- `init`
  - ensures storage roots via `DBVC_CC_Artifact_Manager::ensure_storage_roots()`

- `admin_init`
  - registers settings
  - triggers initial schema snapshot generation

- `admin_menu`
  - registers addon admin pages

- `admin_enqueue_scripts`
  - loads addon admin assets

- `rest_api_init`
  - registers explorer, AI, workbench, mapping catalog, media, import plan, and import executor routes

### AJAX actions

- `dbvc_cc_get_urls_from_sitemap`
- `dbvc_cc_process_single_url`
- `dbvc_cc_trigger_domain_ai_refresh`

### Cron hooks

- `dbvc_cc_ai_process_job`
- `dbvc_cc_mapping_rebuild_batch`

### Filters

- none currently registered

## REST Route Index

### AI

- `POST /dbvc_cc/v1/ai/rerun`
- `POST /dbvc_cc/v1/ai/rerun-branch`
- `GET /dbvc_cc/v1/ai/status`

### Explorer

- `GET /dbvc_cc/v1/explorer/domains`
- `GET /dbvc_cc/v1/explorer/tree`
- `GET /dbvc_cc/v1/explorer/node/children`
- `GET /dbvc_cc/v1/explorer/node`
- `GET /dbvc_cc/v1/explorer/content`
- `GET /dbvc_cc/v1/explorer/content-context`
- `GET /dbvc_cc/v1/explorer/scrub-policy-preview`
- `GET /dbvc_cc/v1/explorer/scrub-policy-approval-status`
- `POST /dbvc_cc/v1/explorer/scrub-policy-approve`
- `GET /dbvc_cc/v1/explorer/node/audit`

### Workbench and mapping

- `GET /dbvc_cc/v1/workbench/domains`
- `GET /dbvc_cc/v1/workbench/review-queue`
- `GET /dbvc_cc/v1/workbench/suggestions`
- `POST /dbvc_cc/v1/workbench/decision`
- `GET /dbvc_cc/v1/mapping/candidates`
- `POST /dbvc_cc/v1/mapping/candidates/build`
- `GET /dbvc_cc/v1/mapping/decision`
- `POST /dbvc_cc/v1/mapping/decision`
- `GET /dbvc_cc/v1/mapping/handoff`
- `POST /dbvc_cc/v1/mapping/domain/rebuild`
- `GET /dbvc_cc/v1/mapping/domain/rebuild/status`

### Mapping catalog

- `POST /dbvc_cc/v1/mapping/catalog/build`
- `POST /dbvc_cc/v1/mapping/catalog/refresh`
- `GET /dbvc_cc/v1/mapping/catalog`

### Mapping media

- `GET /dbvc_cc/v1/mapping/media/candidates`
- `POST /dbvc_cc/v1/mapping/media/candidates/build`
- `GET /dbvc_cc/v1/mapping/media/decision`
- `POST /dbvc_cc/v1/mapping/media/decision`

### Import plan

- `GET /dbvc_cc/v1/import-plan/dry-run`

### Import executor

- `GET /dbvc_cc/v1/import-executor/dry-run`
- `POST /dbvc_cc/v1/import-executor/execute`
- `POST /dbvc_cc/v1/import-executor/preflight-approve`
- `GET /dbvc_cc/v1/import-executor/preflight-status`
- `GET /dbvc_cc/v1/import-executor/run`
- `GET /dbvc_cc/v1/import-executor/runs`
- `POST /dbvc_cc/v1/import-executor/rollback`

## Artifact Index

### Domain-level artifacts

- `.cc-index.json`
- `redirect-map.json`
- `_logs/events.ndjson`
- `_schema/dbvc_cc_schema_snapshot.json`
- `_schema/dbvc_cc_target_field_catalog.v1.json`

### Page-level primary artifacts

- `{slug}.json`
- `{slug}.analysis.json`
- `{slug}.analysis.status.json`
- `{slug}.sanitized.json`
- `{slug}.sanitized.html`
- `{slug}.mapping.suggestions.json`
- `{slug}.mapping.review.json`

### Page-level Phase 3.6 artifacts

- `{slug}.elements.v2.json`
- `{slug}.attribute-scrub-report.v2.json`
- `{slug}.sections.v2.json`
- `{slug}.section-typing.v2.json`
- `{slug}.context-bundle.v2.json`
- `{slug}.ingestion-package.v2.json`

### Page-level Phase 3.7 and Phase 4 bridge artifacts

- `{slug}.section-field-candidates.v1.json`
- `{slug}.mapping-decisions.v1.json`
- `{slug}.media-candidates.v1.json`
- `{slug}.media-decisions.v1.json`

## Feature Flag Index

- `dbvc_cc_flag_collector`
- `dbvc_cc_flag_explorer`
- `dbvc_cc_flag_ai_mapping`
- `dbvc_cc_flag_mapping_workbench`
- `dbvc_cc_flag_import_plan`
- `dbvc_cc_flag_import_execute`
- `dbvc_cc_flag_export`
- `dbvc_cc_flag_deep_capture`
- `dbvc_cc_flag_context_bundle`
- `dbvc_cc_flag_ai_section_typing`
- `dbvc_cc_flag_attribute_scrub_controls`
- `dbvc_cc_flag_mapping_catalog_bridge`
- `dbvc_cc_flag_media_mapping_bridge`

## Main V1 Planning Takeaway

V1 is not a small crawler anymore. It is already a layered system with:

- deterministic capture
- structured extraction
- AI interpretation
- review tooling
- target schema introspection
- import planning
- guarded execution

That means V2 should not start from zero. It should reuse the stable deterministic layers, replace the confusing middle recommendation layer, and formalize the missing observability and reference material around them.
