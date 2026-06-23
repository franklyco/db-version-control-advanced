# 01 — Product Overview

## Problem

The current Content Migration recommendation experience is too cumbersome when rendered as a card with controls for each recommended field. That makes review:

- fragmented,
- slow,
- overly field-centric,
- mentally expensive,
- harder to trust at the page level.

Operators do not naturally think in isolated meta fields. They think in page sections and target page structure.

## Product goal

Design a **DBVC Content Migration Workbench** that lets an operator review each crawled page through a clear structure:

- what source evidence was found,
- what destination page/object was chosen,
- how the target sections are being populated,
- what remains unresolved,
- what blocks package readiness.

## Product principle

> Review by section, resolve by exception, verify by evidence.

## Core UX thesis

The best interface is not a card-per-field list.

The best interface is a **page-level section workbench** with:

- source evidence on the left,
- target section wireframe in the center,
- contextual inspector on the right,
- unmatched/warnings dock at the bottom.

## Operator questions the UI must answer quickly

For a given crawled page:

1. Is this mapped to the correct target object?
2. Were the correct sections inferred?
3. Are the recommended slot assignments plausible?
4. Which items are unresolved, conflicting, or blocked?
5. Can I approve this page safely?

## Non-goals

This UI is not:

- a full page builder,
- a raw ACF editor,
- a code diff screen,
- a giant spreadsheet,
- a visual design tool.

It is a **content assembly and validation workbench** for structured migration review.

## Primary UX outcomes

The workbench should reduce:

- time spent hunting individual fields
- accidental acceptance of bad mappings
- fatigue during repetitive review
- confusion around unresolved items

And improve:

- section-level comprehension
- confidence in page structure
- clarity around why a recommendation exists
- fast manual reassignment when needed
- review-by-exception throughput
