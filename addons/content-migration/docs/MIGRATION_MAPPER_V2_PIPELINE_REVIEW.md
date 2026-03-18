# Migration Mapper V2 Pipeline Review

## Purpose

This document reviews the rough V2 outline against the clarified product vision:

- minimal manual actions
- strong automation
- review by exception
- the current WordPress site as the target schema authority
- an import-ready package as the final deliverable

The goal is not to make the pipeline smaller internally. The goal is to make it feel smaller to the user.

## The Core Alignment

Yes, the vision makes sense.

The addon is moving toward a workflow that behaves like a `source-to-target content compiler`:

1. crawl raw source content
2. interpret and normalize it
3. align it to the current site's schema
4. transform it into target-ready values
5. validate the output
6. build an import-ready package

That means recommendations are an intermediate product, not the final product.

## What Needed To Change From The Earlier V2 Framing

### 1. User-facing simplicity had to be made explicit

The earlier V2 framing was accurate internally, but it still read like an engineering pipeline.

The corrected user-facing workflow is:

1. choose source crawl and target site schema
2. run automated package build
3. review only flagged exceptions
4. approve package or dry-run import
5. import or export the package

### 2. The target site schema needed to move earlier

The current WordPress site is the target system. Its CPTs, taxonomies, meta, ACF fields, and field shapes are not just later-stage references. They are part of the package build context from the beginning.

### 3. The final deliverable needed to become explicit

The earlier V2 framing still leaned too much on recommendations and handoff.

The corrected V2 end state is:
- `import-ready package`

### 4. Review needed to become exception-based

The operator should not inspect every URL by default.

High-confidence mappings should move forward automatically. The UI should mainly surface:
- blocked items
- low-confidence items
- stale items
- policy-sensitive items
- manually rerun or overridden items

## What The Initial Outline Was Missing

### 1. Domain intake and run setup

Needed because:
- V1 already has async jobs, multiple artifacts, and rerun behavior
- V2 needs a stable journey and run context from the start

### 2. URL eligibility and migration scope filtering

Needed because:
- not every discovered URL is worth mapping
- removing ineligible URLs early reduces noise and manual review

### 3. Source-normalization transformations before AI

Needed because:
- scrub and normalization already matter in V1
- AI should consume normalized and privacy-safe inputs

Examples:
- boilerplate suppression
- attribute tokenization
- text cleanup
- structure stabilization

### 4. Target-value transformations after mapping

Needed because:
- mapping alone does not produce import-ready values
- the system must shape data to the current site's real field structures

Examples:
- field-shape adaptation
- repeater and group shaping
- taxonomy normalization
- SEO/meta shaping
- media target shaping

### 5. Parallel media processing

Needed because:
- V1 already has meaningful media logic
- media should stay visible as its own track, not disappear into generic mapping

### 6. Pattern reuse and learning

Needed because:
- this is one of the strongest ways to reduce manual work across similar pages
- sibling URLs often share repeatable content and field patterns

### 7. Target entity resolution preview

Needed because:
- the reviewer should know whether a package likely creates, updates, or blocks on a target object
- field mapping alone is not enough for confident approval

This is one of the most important additions.

### 8. QA and package validation

Needed because:
- the final output is a package
- package quality should be measured before import

Checks should include:
- unresolved required fields
- blocked object resolutions
- missing media
- stale schema dependencies
- unsupported field shapes
- package readiness score

### 9. Manual overrides and per-URL reruns as first-class controls

Needed because:
- automation should be strong, but operators still need control
- per-URL control has to include target object type override, not just field-level edits
- rerunning only one automated layer on one URL is far better than reprocessing a whole domain

## Recommended Revised V2 Pipeline

### User-facing flow

1. Choose source crawl and target site schema
2. Run automated package build
3. Review only flagged exceptions
4. Approve package or run dry-run import
5. Import or export package

### Internal automated flow

1. Domain intake and target schema sync
2. URL discovery and normalization
3. URL eligibility and migration scope check
4. Page capture and raw artifact storage
5. Privacy, scrub, and source-normalization transforms
6. Structured extraction and content packaging
7. AI context creation
8. Target object inventory
9. AI initial classification
10. Target field schema catalog
11. AI initial data mapping and indexing
12. Parallel media candidate and mapping track
13. Pattern reuse and learning layer
14. Target-value transformation layer
15. Target entity resolution preview
16. AI finalize recommended mappings
17. Review by exception, manual overrides, and per-URL reruns
18. QA and package validation
19. Build import-ready package
20. Downstream dry-run and import consumers

## What V1 Most Strongly Suggests

### Simplicity should come from automation, not from hiding complexity

If V2 hides:
- stale states
- rerun behavior
- transformation logic
- create-versus-update behavior

then it will feel confusing even if the stage list is shorter.

### The biggest risk is still the middle layer

V1 already shows that the weakest part of the system is the interpretation and recommendation middle layer.

That is why V2 should keep these distinct:
- context creation
- classification
- mapping and indexing
- target-value transformation
- recommendation finalization

### The package must become the organizing concept

The current planning should be read through this lens:

- crawl and extraction support the package
- AI interpretation supports the package
- mapping supports the package
- QA supports the package
- review supports the package

The package is the product.

## Recommendation

The best V2 framing is:

`automate everything possible, learn from patterns, surface only exceptions, preserve override and rerun controls, and produce a target-adapted import-ready package as the default end result`
