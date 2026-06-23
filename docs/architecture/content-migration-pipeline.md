# DBVC Content Migration — Reimagined Pipeline Concept

## Purpose

This document proposes a more accurate and maintainable architecture for the DBVC Content Migration addon than a direct `crawl -> markdown -> entity JSON` pipeline. It is intended as a planning and implementation guide for Codex against the existing DBVC codebase.

The goal is to improve:

- mapping accuracy
- operator trust
- unresolved handling
- traceability and provenance
- compatibility with VerticalFramework ACF context structures
- package readiness for dry-run, preflight, and execute import

---

## Core Recommendation

The system should be reimagined as a **multi-layer structured pipeline** with a strict separation between:

1. **source understanding**
2. **target-aware field resolution**
3. **deterministic DBVC package shaping**

### Recommended direction

```text
crawl -> structured evidence model -> semantic page model -> target mapping plan -> DBVC entity package
```

### Not recommended as the canonical pipeline

```text
crawl -> markdown -> AI -> DBVC entity JSON
```

Markdown can still be generated as a **human-readable view** or **LLM-friendly rendering**, but it should not be the canonical system-of-record between stages.

---

# Why the current direction should evolve

The current V2 thinking is already moving in the right direction by introducing:

- page-level processing
- field-context awareness
- unresolved bias
- deterministic eligibility checks
- package QA gates

However, the biggest remaining issue is that the pipeline still risks becoming too **field-centric too early**.

The most expensive migration failures usually happen because the system gets one of these wrong before it ever picks a field:

- wrong target object
- wrong page intent/template
- wrong section family
- wrong grouping of repeated source content
- wrong assumption that content must map somewhere

A better system first asks:

- What kind of page is this?
- What content structures exist on this page?
- Which blocks belong together?
- What is signal vs noise?
- What target object and target section should this page map into?

Only after that should it ask:

- Which ACF fields are actually eligible?
- Which mappings are safe enough to assign?
- What should remain unresolved?

---

# High-Level Architecture

## Proposed layer model

### Layer A — Source acquisition and evidence capture
Capture the page with enough structure and provenance to support later decisions.

### Layer B — Whole-page semantic understanding
Interpret all extracted content on the page together, rather than forcing early field mapping.

### Layer C — Target-aware mapping and resolution
Compare semantic source units against actual available ACF / VerticalFramework target slots.

### Layer D — Deterministic package shaping
Build DBVC-ready entity JSON, related records, unresolved queues, media manifests, QA summaries, and import artifacts.

---

# Design Principles

## 1. Preserve structure before summarization
Do not flatten the page too early.

## 2. AI should understand the page before it understands the fields
Source understanding should be target-agnostic first.

## 3. Fields should constrain the final mapping more than the AI does
The target schema, contracts, and context metadata should narrow the final decision space.

## 4. Prefer abstention over plausible error
When in doubt, unresolved is better than a believable wrong auto-map.

## 5. Every output should be traceable back to source evidence
No value should appear in the package without provenance.

## 6. Human review should happen by exception, not by default
Operators should review edge cases, not everything.

---

# Why markdown should not be the canonical intermediary

Markdown is useful, but it is too lossy to be the main migration substrate.

## Problems with markdown as the primary intermediate

### Loss of ownership and hierarchy
Markdown does not reliably preserve:

- which heading owned which text blocks
- which button belonged to which CTA block
- which image sat beside which body copy
- which repeated items formed a true list/repeater

### Loss of placement context
Flattening content makes it harder to distinguish:

- hero vs intro vs CTA
- body vs sidebar vs footer
- primary content vs boilerplate

### Weaker repeatable structure handling
Repeaters, FAQs, cards, reviews, team lists, and CTA groups need structured grouping, not just rendered prose.

### Harder provenance and auditing
A flat markdown representation makes it harder to trace exactly:

- where a value came from
- how it was transformed
- why it was assigned

## Better role for markdown
Markdown should be generated as a:

- debugging view
- review export
- operator summary
- optional prompt rendering for LLM reasoning

But the canonical layer should remain structured JSON-like data.

---

# Recommended Pipeline

## Phase 1 — Run initialization
Purpose: create a reproducible migration run state.

### Suggested outputs
- run id
- crawler config snapshot
- provider/schema version snapshot
- target schema snapshot
- ruleset snapshot
- audit timestamps

### Notes
This lets packages remain auditable even if the target field provider or migration logic changes later.

---

## Phase 2 — Crawl and evidence capture
Purpose: discover and capture source pages while retaining provenance.

### Inputs
- start URL(s)
- crawl rules
- include/exclude rules
- render mode / browser mode

### Outputs
For each crawled page, capture:

- URL
- canonical URL
- page title
- meta description
- raw HTML snapshot or reference
- screenshot or capture reference if needed
- discovered headings
- extracted links
- extracted media references
- DOM segmentation / source block boundaries
- basic boilerplate classification
- crawl warnings / fetch warnings

### Important requirement
Do **not** flatten this phase directly into markdown.

---

## Phase 3 — Structured normalization
Purpose: convert raw page capture into typed evidence units.

### Suggested canonical output
`collected_page.json`

### This should include
- page metadata
- heading tree
- typed source blocks
- block ordering
- adjacency relationships
- likely section boundaries
- repeated-pattern clusters
- media adjacency
- raw text and normalized text
- provenance identifiers

### Example source unit types
- heading
- paragraph
- list
- CTA block
- button/link block
- FAQ question
- FAQ answer
- testimonial
- service card
- team/person block
- office info block
- address / contact block
- image / figure / gallery item
- legal / policy block
- nav/footer/noise

### Why this matters
This phase preserves the structure that later improves mapping accuracy.

---

## Phase 4 — Whole-page semantic understanding
Purpose: let AI evaluate all meaningful evidence on the page **together**.

This is where the system interprets the page as a whole and produces a structured semantic model.

### Suggested canonical output
`semantic_page_model.json`

### This layer should infer
- page intent/type
- likely target template family
- meaningful section families
- grouped/repeated structures
- content role of each source block
- likely section ownership
- signal vs noise
- ambiguity markers
- confidence scores

### Examples of section-level inference
- hero section
- intro section
- services/features section
- testimonial/reviews section
- FAQ section
- CTA section
- contact/business-info section
- gallery/media section

### Examples of page-type inference
- home page
- service page
- location page
- about page
- review/testimonial page
- contact page
- article/post page
- team page

### Key rule
This layer should not try to force exact field assignments yet.

It should answer:

- What does this page appear to be?
- What content structures are present?
- Which content units belong together?
- What should likely remain unmatched or low-confidence?

---

## Phase 5 — Target slot graph construction
Purpose: load and normalize the actual target field system into a mapping-safe model.

### Suggested output
`target_slot_graph.json`

### Build from VerticalFramework field context provider
Use actual target metadata such as:

- object type
- object scope
- object identity
- group key
- branch path
- field key
- field name
- field type
- clone context
- object context
- value contract
- writable status
- required/optional
- cardinality
- semantic role
- section role
- sibling family / competition group

### Important distinction
The mapping target should be modeled as **slots**, not just fields.

A slot is a field in context, not merely a field name.

---

## Phase 6 — Target-aware candidate retrieval
Purpose: generate plausible candidate mappings without assigning too early.

### Suggested output
`mapping_candidates.json`

### Candidate generation should consider
- page type compatibility
- section family compatibility
- source unit type compatibility
- lexical similarity
- embedding similarity
- structural similarity
- historical mapping priors
- object scope compatibility
- contract compatibility hints

### Important rule
This stage should over-retrieve reasonably, then rely on strict pruning.

---

## Phase 7 — Deterministic eligibility pruning
Purpose: shrink the decision space before any ranking or final recommendation.

### Suggested output
`eligible_candidates.json`

### Reject candidates when there is
- object mismatch
- page type mismatch
- section mismatch
- source type mismatch
- value contract mismatch
- clone context mismatch
- non-writable target
- cardinality overflow
- forbidden mapping rule
- sibling exclusivity conflict

### Key principle
The AI should compare only candidates that are already structurally and contractually plausible.

---

## Phase 8 — Mapping plan generation
Purpose: compare plausible candidates and produce a target-aware recommendation plan.

### Suggested canonical output
`mapping_plan.json`

### This layer may use AI, but it should not freely invent mappings
The AI’s job here should be to:

- compare plausible candidate slots
- explain why one is stronger than another
- detect ambiguity
- identify missing evidence
- recommend abstention when needed

### The AI should not be the final authority
It should support a final constrained assignment process, not replace it.

### Mapping plan should include
- source unit or source group id
- candidate target slots
- ranked recommendations
- rationale
- confidence
- required transform type
- unresolved reason when abstaining
- reviewer notes channel

---

## Phase 9 — Deterministic assignment and transformation
Purpose: take the mapping plan and shape values into field-safe outputs.

### Suggested outputs
- `resolved_assignments.json`
- `unresolved_assignments.json`
- `transformed_values.json`

### This stage should perform
- section-aware assignment
- sibling conflict resolution
- repeater grouping
- transform shaping
- HTML/text normalization
- media reference shaping
- field-specific formatting

### Important rule
Prefer unresolved over forcing a weak assignment.

### Recommended unresolved reason types
- unresolved_missing_object
- unresolved_missing_scope
- unresolved_ambiguous_section
- unresolved_ambiguous_siblings
- unresolved_contract_mismatch
- unresolved_low_evidence
- unresolved_extraction_noise
- unresolved_transform_risk
- unresolved_needs_operator_seed

---

## Phase 10 — Contract validation and QA
Purpose: ensure that proposed values are safe for packaging and import.

### Suggested outputs
- `qa_report.json`
- `readiness_summary.json`

### Validation should check
- value contract compliance
- required field expectations
- type safety
- enum/select validity
- media/link validity
- repeater row integrity
- object/section consistency
- provider drift
- benchmark thresholds

### Suggested QA status buckets
- ready
- ready_with_warnings
- blocked
- needs_review

---

## Phase 11 — DBVC package build
Purpose: convert validated results into DBVC import-ready package artifacts.

### Suggested outputs
- `manifest.json`
- `records.json`
- `media-manifest.json`
- `unresolved.json`
- `qa-report.json`
- `summary.md`
- package ZIP

### The package should include provenance
Every packaged record should be traceable back to:

- source page
- source unit(s)
- mapping decision
- transform step
- review overrides if any

---

## Phase 12 — Import bridge
Purpose: preserve the existing DBVC import discipline.

### Flow
- dry run
- preflight review / approval
- execute import
- post-import validation or diff

### Notes
The proposed pipeline does not replace the import bridge. It improves the quality and clarity of what reaches it.

---

# Canonical Intermediate Artifacts

The new system should prefer explicit intermediate artifacts instead of collapsing everything too early.

## 1. `collected_page.json`
The normalized evidence package for one crawled page.

### Example high-level shape
```json
{
  "page_id": "...",
  "url": "...",
  "canonical_url": "...",
  "title": "...",
  "meta": {},
  "headings": [],
  "source_units": [],
  "relationships": [],
  "repeated_clusters": [],
  "capture_provenance": {}
}
```

## 2. `semantic_page_model.json`
The whole-page interpretation.

### Example high-level shape
```json
{
  "page_id": "...",
  "page_type": "service_page",
  "section_models": [],
  "content_roles": [],
  "grouped_items": [],
  "noise_units": [],
  "ambiguities": [],
  "confidence": {}
}
```

## 3. `mapping_plan.json`
The target-aware mapping recommendation plan.

### Example high-level shape
```json
{
  "page_id": "...",
  "target_object": {},
  "target_template_family": "...",
  "recommendations": [],
  "unresolved": [],
  "transform_hints": [],
  "assignment_notes": []
}
```

## 4. `dbvc_entity_package.json`
The final import-ready record payload.

### Example high-level shape
```json
{
  "manifest": {},
  "records": [],
  "media": [],
  "qa": {},
  "provenance": {}
}
```

---

# AI Responsibilities by Layer

## AI Layer A — Semantic source understanding
This layer should evaluate the page holistically.

### Responsibilities
- interpret page intent
- detect section families
- group related source units
- identify repeated structures
- classify signal vs noise
- explain ambiguity

### Should not do
- direct field assignment into ACF
- package shaping into DBVC import format
- override hard field contracts

## AI Layer B — Target-aware mapping assistance
This layer should work against the available target slot graph.

### Responsibilities
- compare semantic source groups against eligible slots
- rank plausible targets
- explain mapping rationale
- recommend unresolved when confidence is weak

### Should not do
- invent unavailable targets
- bypass eligibility checks
- bypass value contracts
- directly finalize package records without deterministic validation

## Deterministic layers should own
- slot eligibility
- contract validation
- cardinality rules
- package shaping
- readiness gates
- import safety

---

# Recommended Operator Review Model

The reimagined pipeline should support **review-by-exception** rather than full manual intervention.

## Operators should primarily review
- unresolved items
- ambiguous section assignments
- sibling conflicts
- contract failures
- low-confidence target object decisions
- transform-risk items

## Operators should not need to review by default
- high-confidence section-safe mappings
- deterministic formatting transforms
- obviously invalid rejected candidates

---

# How this fits the existing codebase direction

This proposal should be treated as an architectural evolution of the current Content Migration V2 addon, not a total discard of existing work.

## Preserve from the current direction
Keep and expand the following:

- run-based execution model
- schema sync at run start
- package-first output approach
- unresolved bias
- deterministic eligibility checks
- provider drift checks
- dry-run -> preflight -> execute import flow
- QA and readiness gating

## Replace or refactor
The largest refactor should happen around the middle of the pipeline.

### Replace field-centric early mapping with:
- structured evidence normalization
- whole-page semantic interpretation
- target slot graph resolution
- mapping-plan stage before final package shaping

---

# Recommended Implementation Strategy

## Phase 1 — Introduce new artifacts without breaking current packaging
Add intermediate artifacts first:

- `collected_page.json`
- `semantic_page_model.json`
- `mapping_plan.json`

Use them alongside the current package build flow.

## Phase 2 — Move field assignment to target-aware slot resolution
Refactor any early loose matching logic to work against:

- target object context
- section family
- slot eligibility
- contracts

## Phase 3 — Make DBVC entity generation consume mapping plans
Do not let entity package shaping consume raw markdown or loose page summaries directly.

## Phase 4 — Keep markdown as a generated view
Generate markdown from structured artifacts for:

- QA
- debugging
- operator review
- model prompt convenience

But keep JSON-like artifacts canonical.

---

# Practical Rule of Thumb

Use this rule throughout implementation:

> AI should understand the page before it understands the fields.

And then:

> The target schema should constrain the final mapping more than the AI does.

That balance should guide the architecture.

---

# Proposed Summary for Codex

## The intended architecture

Rebuild the crawl-to-package pipeline so that it no longer depends on a flattened markdown handoff as the primary migration substrate.

Instead, implement a layered system that:

1. captures crawled pages as structured evidence
2. interprets each page holistically into a semantic page model
3. resolves that semantic model against a target slot graph derived from VerticalFramework ACF context metadata
4. deterministically transforms validated assignments into DBVC import-ready entity packages

## The key benefit

This should improve:

- mapping precision
- unresolved quality
- repeatable structure handling
- operator efficiency
- auditability
- package safety

## The core shift

Move from:

- field matching
- flattened page summaries
- early mapping decisions

Toward:

- source evidence modeling
- semantic section understanding
- target-aware constrained slot resolution
- deterministic package shaping

---

# Suggested Next Step

Use this document to guide a codebase audit and identify where the current addon already has:

- crawl capture hooks
- extraction normalization
- field catalog / provider logic
- package builders
- QA/readiness gates

Then insert the missing stages:

- semantic page model
- target slot graph
- mapping plan

before final DBVC entity shaping.
