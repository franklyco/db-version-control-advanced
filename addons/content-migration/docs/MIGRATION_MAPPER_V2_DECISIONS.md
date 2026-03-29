# Migration Mapper V2 Decisions

## Purpose

This file records the locked implementation decisions for V2.

Do not reopen these decisions unless a concrete implementation blocker appears.

## Runtime Gating

- Content Collector runtime gating is controlled from `DBVC -> Configure -> Add-ons`
- `dbvc_cc_addon_enabled` is the addon enable flag
- `dbvc_cc_runtime_version` selects the runtime version
- allowed runtime values are `v1` and `v2`
- runtime behavior is:
  - `disabled` => no addon runtime surfaces
  - `v1` => legacy Content Collector runtime stays active
  - `v2` => V2 runtime stays active and legacy V1 reviewer surfaces stay dormant

## Build and Bootstrap Naming

- V2 JS root entrypoint name: `content-collector-v2-app`
- V2 script handle: `dbvc-content-collector-v2-app`
- localized bootstrap object: `DBVC_CC_V2_APP`
- V2 root runtime path: `addons/content-migration/v2/`

## Route and Query Naming

- REST namespace: `dbvc_cc/v2`
- UI and REST should use:
  - `runId`
  - `pageId`
  - `packageId`
  - `panel`
  - `panelTab`
- `runId` maps to artifact `journey_id`

## Identifier Conventions

- `runId` format: `ccv2_{domain}_{timestamp}_{token}`
- `packageId` format: `pkg_{run_id}_{seq}`
- package builds are append-only within the domain-scoped package history

## Automation Policy Defaults

- `dbvc_cc_v2_auto_accept_min_confidence = 0.92`
- `dbvc_cc_v2_block_below_confidence = 0.55`
- `dbvc_cc_v2_resolution_update_min_confidence = 0.94`
- `dbvc_cc_v2_pattern_reuse_min_confidence = 0.90`
- `dbvc_cc_v2_require_qa_pass_for_auto_accept = true`
- `dbvc_cc_v2_require_unambiguous_resolution_for_auto_accept = true`
- `dbvc_cc_v2_require_manual_review_for_object_family_change = true`

## Resolution and Readiness Vocabulary

- target resolution modes:
  - `update_existing`
  - `create_new`
  - `blocked_needs_review`
  - `skip_out_of_scope`
- package or import readiness states:
  - `ready_for_import`
  - `needs_review`
  - `blocked`

## Runtime Architecture

- Add-ons configuration stays server-rendered
- V2 operational surfaces use the modular React workspace app
- strict domain isolation is required
- learned behavior, reviewer decisions, and pattern reuse must not spill across domains

## Delivery Policy

- V2 is package-first
- the import-ready package is the primary output of the pipeline
- dry-run and downstream import consumers should prefer the selected package as upstream input

## Reuse Policy

- V1 reuse is allowed through adapters and thin bridge services
- V2 runtime code should stay under `addons/content-migration/v2/`
- do not interleave new V2 runtime logic back into legacy V1 folders unless it is clearly shared infrastructure

## Crawl-Start Boundary

- the V2 crawl-start UI wraps `POST /dbvc_cc/v2/runs`
- `POST /dbvc_cc/v2/runs` is the canonical V2 run-create and crawl-start contract
- per-run crawl settings should flow through `crawlOverrides` on the V2 route
- do not revive the V1 collect tab, legacy collect-page JavaScript, or `admin-ajax` crawl handlers for V2
