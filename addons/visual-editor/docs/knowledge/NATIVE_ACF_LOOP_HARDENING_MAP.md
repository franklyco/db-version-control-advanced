# Native ACF Loop Hardening Map

## Purpose

Map the recent Visual Editor hardening patches for native Bricks ACF loops back to the concrete code paths and functions that own them.

This is not a changelog duplicate.

Use this document to:
- understand which failure class each patch addressed
- see where the current behavior lives in code
- separate foundational handling from transitional recovery logic
- plan how to fold the current hardening into true universal nested-path handling

## Scope

This reference covers the recent failure family around:
- Bricks native ACF repeater loops
- Bricks native ACF relationship and post-object loops
- nested ACF group descendants inside repeater and flexible rows
- wrong child field keys from Bricks provider metadata
- wrong row identity during final render verification

Related planning docs:
- [DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md](../enhancements/DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md)
- [DBVC_VISUAL_EDITOR_PHASES.md](../enhancements/DBVC_VISUAL_EDITOR_PHASES.md)
- [DATA_CONTRACTS.md](./DATA_CONTRACTS.md)
- [RESOLVER_REGISTRY.md](./RESOLVER_REGISTRY.md)
- [TEST_LOG.md](../qa/TEST_LOG.md)

## Reading Guide

### Foundational handling

These are the patches we should keep and strengthen:
- canonical native query classification
- canonical container selector rebinding
- descriptor path/source enrichment
- row reads and writes by canonical selector + row + group path
- group traversal by field key path
- row-aware source/sync grouping

### Transitional handling

These are safe but should become fallback-only over time:
- render-time row rebinding from a unique visible-value match

## Patch Map

| Failure class | Current code ownership | Core methods | Current role | Consolidation target |
| --- | --- | --- | --- | --- |
| Native Bricks ACF query roots were not first-class | [`src/Bricks/NativeAcfQueryResolver.php`](../../src/Bricks/NativeAcfQueryResolver.php), [`src/Bricks/LoopContextResolver.php`](../../src/Bricks/LoopContextResolver.php) | `NativeAcfQueryResolver::resolve()`, `resolveFieldDefinition()`, `getFieldPathIndex()`, `mapFieldTypeToLoopKind()`, `LoopContextResolver::resolve()`, `mapLoopObjectToEntity()`, `export()` | Classifies `query.objectType` roots like `acf_process_section_process_steps`, `acf_related_faq_groups`, and `acf_office_manager` into a real loop kind plus owner-aware context | Make native query metadata the only accepted root for native ACF loop path identity instead of relying on suffix heuristics |
| Loop owner identity was too weak for queried post/term/user cases | [`src/Bricks/LoopContextResolver.php`](../../src/Bricks/LoopContextResolver.php), [`src/Bricks/ElementInstrumentationService.php`](../../src/Bricks/ElementInstrumentationService.php) | `LoopContextResolver::supportsRelatedPostEditing()`, `hasConcreteOwner()`, `hasConcretePostOwner()`, `mapLoopObjectToEntity()`, `ElementInstrumentationService::allowsLoopOwnedPostEntity()` | Prevents VE from surfacing loop-owned fields unless Bricks exposes a concrete owner entity | Fold owner identity into a stricter descriptor contract so loop-owned paths never depend on downstream guardrails alone |
| Native child tags could point at the wrong field key or shortened parent alias | [`src/Bricks/AcfFieldContextResolver.php`](../../src/Bricks/AcfFieldContextResolver.php) | `resolve()`, `rebindContainerScopedTagField()`, `resolveContainerFieldDefinition()`, `findContainerSubFieldDefinition()`, `normalizeTagGroupPath()`, `resolveNativeQueryFieldSelector()` | Rebinds Bricks child tags against the actual container field definition so duplicate or shortened tags resolve to the correct subfield | Promote this from repair logic to the canonical field-binding path for all native ACF loop descendants |
| Nested descriptors did not carry enough canonical path data | [`src/Resolvers/ResolverRegistry.php`](../../src/Resolvers/ResolverRegistry.php) | `classifyAcfField()` | Stores `field_selector`, `leaf_field_name`, `leaf_field_key`, `parent_field_selector`, `row_index`, `layout_key`, `layout_name`, `group_path`, and `group_key_path` on the descriptor source | Treat these fields as required nested-path contract inputs, not optional metadata |
| Group descendants inside native repeater/flexible loops lost row support | [`src/Bricks/AcfFieldContextResolver.php`](../../src/Bricks/AcfFieldContextResolver.php) | `resolve()`, `rebindContainerScopedTagField()`, `findContainerSubFieldDefinition()` | Lets grouped descendants inherit repeater or flexible root context when the actual container definition proves they belong there | Keep inheritance, but make the inherited container identity explicit and complete before any resolver read/write step |
| Repeater/flexible reads and writes needed the full native root selector, not the child alias | [`src/Resolvers/AbstractAcfResolver.php`](../../src/Resolvers/AbstractAcfResolver.php) | `getRawRepeaterSubfieldValue()`, `writeRepeaterSubfieldValue()`, `getRawFlexibleSubfieldValue()`, `writeFlexibleSubfieldValue()`, `resolveParentFieldReadIdentifier()` | Reads and writes rows through the full parent selector like `process_section_process_steps`, not a shorter duplicate child parent such as `process_steps` | Keep this as the only allowed nested-row read/write entry path |
| Grouped row payloads were keyed by field keys, not group names | [`src/Resolvers/AbstractAcfResolver.php`](../../src/Resolvers/AbstractAcfResolver.php) | `extractRowFieldValue()`, `replaceRowFieldValue()`, `resolveGroupedRowContainer()`, `resolveGroupedRowSegmentKey()` | Traverses nested groups by `group_key_path` first, then name fallback, so raw ACF row arrays keyed by field keys still resolve safely | Make key-path traversal the default, and leave name fallback as compatibility only |
| Grouped direct saves could fall back to ambiguous leaf names | [`src/Resolvers/AbstractAcfResolver.php`](../../src/Resolvers/AbstractAcfResolver.php) | `getFieldIdentifier()`, `shouldPreferFieldIdentifierWrite()` | Prefers full selector-based writes for grouped fields instead of ambiguous leaf-name writes | Keep selector-first writes as the permanent grouped-field rule |
| Same-named nested leaves could cross-sync after save | [`src/Bricks/ElementInstrumentationService.php`](../../src/Bricks/ElementInstrumentationService.php) | `buildSourceGroup()`, `buildSyncGroup()` | Hashes sync/source grouping with selector, leaf identity, container type, row index, layout identity, and group ancestry | Preserve this as the stable live-update identity model for nested paths |
| Bare numeric row indices could be mistaken for concrete related post owners in native repeater loops | [`src/Bricks/LoopContextResolver.php`](../../src/Bricks/LoopContextResolver.php) | `mapLoopObjectToEntity()`, `NativeAcfQueryResolver::resolve()` | Prevents native `repeater` and `flexible_content` loops from treating loop indices like `3` as real related post IDs just because a WordPress post with that ID exists | Keep native loop kind gating in owner mapping so only relationship/post-object style loops can derive concrete owners from loop objects |
| Native loop origin was implicit in generic loop-owned descriptors | [`src/Resolvers/ResolverRegistry.php`](../../src/Resolvers/ResolverRegistry.php), [`src/Bricks/ElementInstrumentationService.php`](../../src/Bricks/ElementInstrumentationService.php), [`src/Presentation/DescriptorSummaryBuilder.php`](../../src/Presentation/DescriptorSummaryBuilder.php), [`src/Save/MutationContractService.php`](../../src/Save/MutationContractService.php) | `ResolverRegistry::classifyAcfField()`, `ElementInstrumentationService::buildPathDescriptor()`, `buildMutationDescriptor()`, `DescriptorSummaryBuilder::buildSourceSummary()`, `MutationContractService::buildSummary()` | Carries native loop kind/selector/objectType through source, path, mutation, panel summary, and save-contract detail so native repeater vs relationship vs post-object paths stay distinguishable | Keep native query provenance explicit across the contract layer so future relationship/post-object hardening does not get buried inside generic loop-owned handling |
| Final render verification could strip valid markers when the descriptor had the wrong row index | [`src/Bricks/ElementInstrumentationService.php`](../../src/Bricks/ElementInstrumentationService.php), [`src/Resolvers/AbstractAcfResolver.php`](../../src/Resolvers/AbstractAcfResolver.php) | `verifyDescriptor()`, `attemptUniqueRowRebind()`, `applyRowDescriptorRebind()`, `resolveDisplayPayload()`, `AbstractAcfResolver::getRowCandidateValues()` | Safe recovery step: if one unique row candidate matches the rendered text, VE rebinds the descriptor to that row instead of stripping the marker | Reduce how often this is needed by making upstream row identity correct; keep it as a narrow fallback for damaged provider metadata or odd loop state |

## Current Hardening by Code Path

### 1. Native query-root classification

Code:
- [`src/Bricks/NativeAcfQueryResolver.php`](../../src/Bricks/NativeAcfQueryResolver.php)
- [`src/Bricks/LoopContextResolver.php`](../../src/Bricks/LoopContextResolver.php)

Key methods:
- `NativeAcfQueryResolver::resolve()`
- `NativeAcfQueryResolver::resolveFieldDefinition()`
- `NativeAcfQueryResolver::getFieldPathIndex()`
- `LoopContextResolver::resolve()`
- `LoopContextResolver::mapLoopObjectToEntity()`

What it fixes:
- Bricks native loop roots are no longer treated as anonymous query strings.
- The runtime can classify repeater, relationship, post-object, and flexible roots from `query.objectType`.

Why it matters for universal handling:
- Every downstream nested-field fix gets more reliable once the root loop kind is canonical.

### 2. Container-scoped field rebinding

Code:
- [`src/Bricks/AcfFieldContextResolver.php`](../../src/Bricks/AcfFieldContextResolver.php)

Key methods:
- `AcfFieldContextResolver::resolve()`
- `AcfFieldContextResolver::rebindContainerScopedTagField()`
- `AcfFieldContextResolver::resolveContainerFieldDefinition()`
- `AcfFieldContextResolver::findContainerSubFieldDefinition()`
- `AcfFieldContextResolver::normalizeTagGroupPath()`
- `AcfFieldContextResolver::resolveNativeQueryFieldSelector()`

What it fixes:
- Bricks child tags that expose the wrong subfield key
- tags that use shortened parent aliases
- grouped descendants that need the actual container definition to disambiguate the leaf field

Why it matters for universal handling:
- This should become the canonical field-binding path for native loop descendants, not just a repair step for known bad tags.

### 3. Descriptor source/path enrichment

Code:
- [`src/Resolvers/ResolverRegistry.php`](../../src/Resolvers/ResolverRegistry.php)

Key method:
- `ResolverRegistry::classifyAcfField()`

Important source fields:
- `field_selector`
- `leaf_field_name`
- `leaf_field_key`
- `parent_field_selector`
- `row_index`
- `layout_key`
- `layout_name`
- `group_path`
- `group_key_path`

What it fixes:
- Nested descriptors now carry enough information for precise row, layout, and grouped-field reads/writes.

Why it matters for universal handling:
- These keys are the raw material for a real universal nested-path contract.

### 4. Canonical row reads and writes

Code:
- [`src/Resolvers/AbstractAcfResolver.php`](../../src/Resolvers/AbstractAcfResolver.php)

Key methods:
- `getRawRepeaterSubfieldValue()`
- `writeRepeaterSubfieldValue()`
- `getRawFlexibleSubfieldValue()`
- `writeFlexibleSubfieldValue()`
- `resolveParentFieldReadIdentifier()`
- `resolveRepeaterRowIndex()`
- `resolveFlexibleRowIndex()`

What it fixes:
- VE no longer depends on short child aliases to read or write native row payloads.

Why it matters for universal handling:
- This is already the right permanent direction: resolve the root field canonically, then operate on rows by explicit row identity.

### 5. Group-key-path traversal

Code:
- [`src/Resolvers/AbstractAcfResolver.php`](../../src/Resolvers/AbstractAcfResolver.php)

Key methods:
- `extractRowFieldValue()`
- `replaceRowFieldValue()`
- `resolveGroupedRowContainer()`
- `resolveGroupedRowSegmentKey()`

What it fixes:
- Raw ACF row payloads often store nested groups by field key.
- Friendly group names alone are not enough to find the right container.

Why it matters for universal handling:
- Key-path traversal should remain the canonical nested-group access strategy.

### 6. Row-aware live-update grouping

Code:
- [`src/Bricks/ElementInstrumentationService.php`](../../src/Bricks/ElementInstrumentationService.php)

Key methods:
- `buildSourceGroup()`
- `buildSyncGroup()`

What it fixes:
- Same-named grouped leaves or row descendants no longer share one vague live-update group and cross-refresh each other incorrectly.

Why it matters for universal handling:
- The same canonical nested-path identity should drive both save targeting and post-save DOM syncing.

### 7. Render-time row-rebind fallback

Code:
- [`src/Bricks/ElementInstrumentationService.php`](../../src/Bricks/ElementInstrumentationService.php)
- [`src/Resolvers/AbstractAcfResolver.php`](../../src/Resolvers/AbstractAcfResolver.php)

Key methods:
- `verifyDescriptor()`
- `attemptUniqueRowRebind()`
- `applyRowDescriptorRebind()`
- `resolveDisplayPayload()`
- `AbstractAcfResolver::getRowCandidateValues()`

What it fixes:
- If a descriptor reaches final verification with the wrong row index, but one unique row candidate matches the actual rendered value, VE now repairs the row binding instead of stripping the marker outright.

Why it is still transitional:
- The ideal state is that row identity is already correct before verification.
- This logic is still based on matching visible rendered output.
- It should remain a safety net, not become the primary row-resolution strategy.

## Recommended Consolidation Path

### Step 1. Promote canonical nested identity

Treat these as the required source-of-truth for any row descendant:
- root selector
- leaf selector
- row index
- layout key/name when flexible
- group key path

### Step 2. Move more responsibility upstream

The earlier `AcfFieldContextResolver` and `ResolverRegistry` can guarantee row/layout/group identity, the less `ElementInstrumentationService::verifyDescriptor()` has to repair later.

### Step 3. Keep row-rebind as a fallback only

Retain the current row-rebind logic for:
- damaged Bricks provider metadata
- odd loop nesting edge cases
- host-specific render mismatches

But do not expand it into general text-guessing across unsupported shapes.

### Step 4. Add explicit smoke fixtures

To graduate these patches into true universal handling, keep adding repeatable fixtures for:
- native repeater loops with 4+ rows
- nested groups inside repeater rows
- duplicate visible values across multiple rows
- native relationship and post-object loops
- flexible layouts with grouped descendants

## Open Risks

- The row-rebind fallback intentionally does nothing when multiple rows render the same exact value.
- Any native loop case with incomplete Bricks provider metadata can still fall back to unsupported if the root/container identity cannot be proven safely.
- Structured collection cases still need their own contract work and should not be folded into these scalar-row fixes by accident.

## Short Answer

Yes, these recent patches can be folded into true universal handling.

The path is:
1. keep the canonical selector + group-key-path work
2. make nested descriptor identity stricter and more complete
3. keep render-time row rebinding only as a narrow recovery layer
