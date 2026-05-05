# 01 · Product Overview

## Core problem

Bricks Builder stores important theme/system configuration in `wp_options`, but today moving those settings between sites is usually one of these:

- manual re-creation
- full database migration
- risky direct option replacement
- ad hoc scripts
- version-blind JSON copying

That is too fragile for a framework-driven workflow.

## Desired outcome

A DBVC feature that lets a user move selected Bricks settings from one site to another through a governed workflow:

- export only what is enabled
- package data in a stable portable format
- inspect the package on import
- compare package objects with local site objects
- surface drift clearly
- choose actions in bulk or individually
- apply changes safely
- restore previous state if needed

## Primary user stories

### 1) Framework maintainer
“I updated our approved Bricks color palette, theme styles, classes, variables, and components on the source framework site. I want to export those and review/apply them on another site.”

### 2) Builder / implementer
“I imported a package from another site and need to see exactly what changed before touching production.”

### 3) QA / operations
“I need a restorable snapshot before import, and I need to know exactly which option groups and objects were changed.”

## What this is not

This should **not** start as:

- a blind replace-all importer
- a full DBVC golden-master system for every Bricks object forever
- a migration engine for arbitrary media-heavy assets without dependency handling
- a UI that hides dangerous overwrite behavior

## Best product framing

Treat each Bricks settings category as a **portable configuration domain** with its own:

- source option(s)
- object identity rules
- normalization rules
- diff rules
- apply strategies
- backup scope
- risk score

## Success criteria

The feature succeeds if a user can do the following with confidence:

1. export a package from Site A in under a minute
2. upload it on Site B
3. see grouped drift by settings type
4. make decisions mostly through bulk actions
5. apply without losing existing data
6. restore with one click if needed

## Product principle

**Review-first portability, not blind import.**
