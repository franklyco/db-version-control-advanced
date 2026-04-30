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

## Deterministic Recovery QA

- deterministic rerun-follow-up QA uses a current-user-scoped fixture overlay, not manual edits to stored journey artifacts
- the recovery fixture helper is development-only by default:
  - enabled in PHPUnit
  - enabled in debug environments
  - filterable through `dbvc_cc_v2_enable_recovery_qa_fixture`
- the helper may overlay `actionSummary.rerunCandidates` for browser validation, but it must not change normal production runtime behavior unless explicitly enabled
- deterministic replay-follow-up QA should keep using the existing `POST /runs` transport
- when replay browser validation needs stable seeded data, the dev-only helper should piggyback on `POST /runs` through an explicit `qaReplaySourceRunId` request flag instead of introducing a second replay route
- deterministic historical-review browser QA should also stay on the existing `POST /runs` transport
- when historical review browser validation needs stable source-run data, use a dev-only synthetic fixture domain on the normal create-run contract instead of adding a second historical-review QA route
- the deterministic replay helper may clone page artifacts into a true same-URL overwrite chain for QA, but that behavior must remain development-only behind the existing recovery-fixture availability gate
- deterministic historical package execution-observability QA should not fire a real import by default
- when LocalWP lacks real historical import history, use a current-user-scoped dev-only package execute fixture overlay on the existing package surface instead of mutating stored package artifacts or triggering `POST /runs/{run_id}/execute`

## Historical Page Artifact Preservation

- active same-URL page artifacts still write to the canonical domain-scoped page path for the current run
- capture, AI pipeline, and review writes also preserve a per-run copy under `{page_dir}/_runs/{run_id}/`
- historical run readers should prefer the current page artifact only when its `journey_id` still matches the requested run
- when the current same-URL artifact belongs to a newer run, historical readers should fall back to the preserved `_runs/{run_id}/` copy before treating that artifact as missing
- historical review and rerun writes should target the preserved `_runs/{run_id}/` page artifact path whenever the current same-URL page belongs to a newer run

## Historical Package Conflict Filtering

- package QA and readiness should evaluate recommendation conflicts against the requested run's saved decision artifacts, not against the raw `recommendations.conflicts` list alone
- a conflict group remains active only while multiple active mappings survive or any pending/unresolved recommendation in that group still requires review
- once saved `approve`, `reject`, or `override` decisions collapse a conflict group to one active mapping with the rest rejected, that group should no longer block historical package preflight or execute readiness

## Vertical Field Context Selection And QA

- field-content recommendation finalization should use deterministic assignment and unresolved bias rather than trusting raw `target_candidates[0]`
- ambiguous section-level selections should default to `unresolved` until a reviewer confirms or overrides them
- utility and navigation fragments should be dropped before Field Context matching, and structured sections should prefer role-specific headline or body or CTA source units over one generic section blob when element evidence exists
- package QA should treat `field_context_provider` status `missing` or `unavailable` as blocking, while `degraded` or `legacy_only` remains warning-level until stricter provider thresholds are explicitly requested
- package QA should keep warning when reviewers approve mappings that still carry ambiguous deterministic selection evidence, so package readiness does not hide weak Field Context matches
- target transforms should validate definite `value_contract` mismatches against the slot graph before package readiness, and impossible writes such as text into URL or reference-only fields must block readiness rather than downgrade to a soft warning
- package QA should also compare recommendation-carried provider metadata against the current slot graph, and mismatched `source_hash`, `schema_version`, `contract_version`, or `site_fingerprint` must block readiness until the page is rerun or re-reviewed
- when the Vertical provider catalog is `missing` or `unavailable`, DBVC should preserve that status truthfully in provider trace metadata and may only use the current WordPress ACF runtime arrays as a purpose-only fallback for the enriched target catalog and slot graph; that fallback must not synthesize provider identity such as `key_path` or claim the provider is healthy
- DBVC same-runtime site catalog builds must query the Vertical provider with empty or object-scoped ACF criteria only; crawl-domain strings such as `flourishweb.co` are not valid field-group criteria and must not be passed into `acf_get_field_groups()` through the provider adapter
- post-object content mapping must exclude slots whose normalized `object_context` is scoped to `options_pages` or `taxonomies`; those slots are not valid page or CPT write targets even when their labels look semantically similar
- semantic slot-role inference should run before generic text-editor type fallbacks, so real Vertical fields such as `hero_h1` continue to classify as `headline` even when the stored ACF field type is `wysiwyg`
- ACF `link` fields must classify as `link` or `cta_url` slots, not generic `body` slots, so compact-popup and CTA link fields never compete with section body-copy recommendations
- low-margin unresolved items should preserve the raw top candidate in reviewer-facing recommendation payloads even when coherence scoring temporarily reorders the internal alternatives; unresolved review should show the frontier, not a hidden auto-reassignment
- candidate scoring and deterministic assignment must keep internal raw scores beyond the visible `0.99` display cap; ambiguity thresholds should evaluate the raw margin, not the rounded confidence shown in the UI
- page-level metadata description items should not consume section-specific hero fields ahead of real section-body units; page-description scoring should penalize `hero` section-family slots so the hero body unit can claim `hero_description` when that field is the best structural match
- target slot projections should expose a structural `competition_group` for non-repeatable sibling slots, and deterministic assignment should treat already-claimed competition groups as an explicit pressure signal rather than relying only on soft label similarity
- unresolved items should carry a typed `unresolved_class` plus stable `reason_codes` so review, QA, and benchmark reporting can distinguish missing eligible slots, ambiguous sibling competition, low evidence, and media-slot gaps
- routing should persist as a first-class `routing-artifact.v1` page artifact written inside the existing classification stage boundary, not as a second routing subsystem or a separate V2 stage
- the routing artifact should expose a stable `primary_route` plus per-section `section_routes`, and mapping may consume that artifact opportunistically for page intent and section-scope evidence while remaining backward-compatible with older runs that do not yet have the file
- benchmark release gating should stay inside the existing page QA, readiness, and package surfaces rather than adding a parallel benchmark screen
- benchmark release gating should block when a page quality score drops to `69` or lower, reviewed ambiguous mappings reach `3` or more, manual overrides reach `5` or more, or reruns reach `3` or more
- benchmark release gating should keep a page in benchmark review when quality score is `84` or lower or when any reviewed ambiguity, manual override, or rerun count remains above `0`, even if the base page readiness is otherwise import-ready

## Historical Execute Boundary

- real historical execute route fidelity is already sufficient on `dbvc-codexchanges.local`; rollback-specific historical execute QA is not required there by default
- `dbvc-codexchanges.local` remains the approved route-fidelity baseline only unless the user explicitly designates a different disposable target
- do not broaden to another LocalWP environment, disable guardrails, or reopen rollback-specific execute scope unless the user explicitly asks for that follow-up

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
