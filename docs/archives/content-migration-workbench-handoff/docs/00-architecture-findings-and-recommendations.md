# DBVC Content Migration — Architecture Findings and Recommendations

This document captures the earlier strategic findings that shaped the workbench recommendation. It is intended to give Codex and future contributors the broader architectural reasoning behind the UI and workflow.

## The main improvement

The core improvement is to stop thinking of the addon as mapping raw content directly to fields, and instead treat it as **resolving source evidence into constrained target slots**.

That means the system should be:

- object-first
- slot-based
- evidence-driven
- abstention-heavy
- globally constrained rather than locally greedy

The most common migration errors usually start earlier than field selection. They usually come from choosing the wrong target object, wrong page intent, wrong section/branch scope, or forcing weak evidence into a nearby field instead of leaving it unresolved.

A better framing is:

**Source evidence -> target object -> target section/branch -> eligible slots -> constrained assignment -> transform -> contract validation**

Not:

**Page content -> AI guess -> field mapping**

## What I would change

### 1) Add a formal routing layer before field mapping

Introduce a distinct routing stage that determines:

- object type
- object identity
- content scope
- page/template intent

If the system routes a block of content to the wrong object, downstream field-context matching can still look valid while being semantically wrong.

### 2) Replace a plain field catalog with a true slot graph

Each destination should be represented as a slot with explicit identity and constraints, including:

- object type
- object scope
- object key
- group key
- branch path
- field key
- field type
- cardinality
- writable state
- clone context
- value contract
- semantic role
- section role
- repeatability
- sibling competition group

This is important for ambiguous sibling fields such as hero heading vs intro heading, or summary text vs CTA copy.

### 3) Model the source side more rigorously

Extraction should produce typed evidence units with provenance, not just generic blocks of text. Useful attributes include:

- URL / canonical URL
- page type guess
- DOM region
- heading path
- block type
- adjacent media
- repetition index
- template signal
- inclusion/exclusion flags
- raw text
- normalized text
- extraction method
- provenance hash

And semantic types such as heading, paragraph, CTA, FAQ, testimonial, hours, service card, review item, person bio, and contact block.

### 4) Move to a two-stage candidate system

Use:

#### Stage A: broad retrieval
- lexical similarity
- embedding similarity
- heading/section similarity
- page-type priors
- DOM position priors
- schema/type hints
- template priors
- historical migration priors

#### Stage B: strict pruning
- object mismatch
- scope mismatch
- contract mismatch
- non-writable target
- clone constraints
- unsupported source type
- invalid page-type-to-field mapping
- sibling exclusivity
- cardinality overflow

Only after pruning should an AI step compare surviving candidates.

### 5) Do not let AI choose the field directly

The model should not make the final slot assignment by itself. Instead, it should provide:

- evidence summary
- reasons candidate A is plausible
- reasons alternatives are weaker
- missing evidence
- ambiguity flags
- abstain recommendation

The deterministic system should make the final assignment.

### 6) Add explicit abstention classes, not just `unresolved`

Prefer typed unresolved reasons such as:

- unresolved_missing_object
- unresolved_missing_scope
- unresolved_ambiguous_siblings
- unresolved_contract_mismatch
- unresolved_low_evidence
- unresolved_extraction_noise
- unresolved_needs_operator_seed
- unresolved_transform_risk

This makes the exception queue more actionable.

### 7) Use global assignment rather than per-field greedy ranking

Assignments should be solved at the object/page level so the system can:

- maximize total confidence
- minimize collisions
- enforce sibling exclusivity
- enforce cardinality
- preserve section coherence
- prefer unresolved when candidates are close

### 8) Add section/template inference as a first-class signal

The system should explicitly infer likely sections such as:

- hero
- intro
- services
- reviews
- FAQ
- CTA
- business/contact info

Then field assignment should happen inside that section family rather than across the entire schema.

### 9) Strengthen `value_contract` into a fuller transform contract

Use the contract to govern:

- accepted source types
- transform family
- allowed lossiness
- normalization rules
- length limits
- HTML policy
- formatting rules
- enumeration constraints
- media requirements
- repeater packaging rules
- null/empty semantics
- merge vs replace behavior

### 10) Benchmark calibration per vertical

Measure quality against labeled examples for different verticals such as dentists, contractors, manufacturers, and other service businesses. Suggested metrics:

- section-level precision / recall
- slot-level precision / recall
- abstention quality
- false-positive rate
- override rate
- rerun success rate
- operator time saved
- package rejection reasons

## A more accurate architecture

A better framing for the addon is:

**Constrained content routing + slot resolution + contract-safe packaging**

Suggested revised pipeline:

1. Run initialization
2. Crawl and capture
3. Structured extraction
4. Object routing
5. Target slot graph build
6. Candidate retrieval
7. Eligibility pruning
8. Evidence comparison
9. Global assignment solve
10. Transform + contract enforcement
11. Exception triage
12. Package QA
13. Package build
14. Import bridge

This architecture shifts the system away from ad-hoc field matching and toward deliberate, explainable resolution.

## What to keep from your current design

The current V2 direction already has the right instincts in several places. Keep these:

- unresolved bias
- deterministic eligibility checks before AI ranking
- contract validation
- review-by-exception workflow
- provider drift checks
- package-first output
- dry-run -> preflight -> execute import flow

These should remain foundational.

## What is still missing

Main gaps identified at the strategy level:

1. No explicit object routing layer
2. No strong distinction between source evidence and extracted value
3. No sibling competition model
4. No global assignment solver
5. No typed abstention reasons
6. No benchmark calibration framework
7. No section/template inference layer

These are the major opportunities to improve precision, operator trust, and scale.

## A practical rule of thumb

**Never auto-map because something is similar. Only auto-map when the object, section, slot, and contract all agree.**

If any one of those is weak, abstain.

This is conservative by design, but usually the correct tradeoff for DBVC because wrong auto-maps are more expensive than unresolved items.

## The concise answer

The better and more accurate implementation is to evolve Content Migration V2 from a field-matching pipeline into a **constrained slot-resolution system** with:

- explicit object routing
- typed source evidence units
- formal target slots
- strict deterministic pruning
- AI used for evidence comparison, not final field choice
- global assignment solving
- richer abstention reasons
- stronger benchmark calibration

That gives you a more accurate migration engine and a cleaner review surface for operators.

## Why this matters to the Workbench UI

The Workbench UI spec in this package assumes the architecture above. In particular, the interface is built around:

- source evidence blocks rather than loose text snippets
- section-aware review rather than field-card review
- constrained destination slots rather than freeform mapping
- typed warnings and abstention reasons
- page-level / section-level readiness instead of blind confidence scores

The UI will work best when the underlying migration engine exposes these concepts directly.
