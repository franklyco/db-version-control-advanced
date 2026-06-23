# 13 · Open Questions and Risks

These should be verified in the real Bricks version and DBVC codebase before final implementation choices are locked.

## 1) Canonical breakpoint storage
The supplied list includes `bricks_breakpoints_last_generated`, but that looks derived/generated.

Question:
- Where do the true editable breakpoint definitions live in the current Bricks version?

Risk:
- building compare/apply logic against a generated option instead of canonical settings

## 2) Global elements vs components
The list includes both `bricks_global_elements` and `bricks_components`.

Question:
- are both needed for this portability feature, or only `bricks_components`?

Risk:
- importing the wrong layer or conflating reusable elements with component definitions

## 3) Icon/media dependency handling
`bricks_icon_sets` and `bricks_custom_icons` may rely on uploaded assets.

Question:
- should the MVP exclude actual asset transport and instead flag missing dependencies?

Recommendation:
- yes, unless DBVC already has a safe asset packaging/remap layer

## 4) Version drift across Bricks releases
Bricks internal structures may change across versions.

Question:
- what is the minimum supported Bricks version range for this feature?

Recommendation:
- store parser/normalizer version and detect unsupported structures early

## 5) Deep merge expectations
Users may ask for “merge” behavior, especially for classes and settings.

Risk:
- deep merge sounds convenient but can create unpredictable config states

Recommendation:
- MVP should stay with add / replace / keep / skip

## 6) Scale of compare payloads
Large sets of classes, variables, or components can make the workbench heavy.

Recommendation:
- store compare payload server-side
- paginate or virtualize rows in UI
- load detail drawer on demand

## 7) Category coupling
Classes and variables may depend on category objects.

Question:
- if a class is imported and its category is missing, should the category be auto-added?

Recommendation:
- yes, as part of dependency-aware apply within the same domain

## 8) Cross-domain dependency resolution
A component may reference:
- class names
- variables
- pseudo classes
- icon sets

Question:
- should the system auto-suggest importing dependent objects?

Recommendation:
- yes, at least warn and suggest, even if not auto-enforced in MVP

## 9) Multi-site / environment workflows
Future need may include pushing approved Bricks packages across many sites.

Recommendation:
- keep package spec and job history generic enough for future remote distribution

## 10) Backup storage location
Question:
- does DBVC already have a private storage standard that should be reused?

Recommendation:
- reuse existing DBVC storage conventions if present, otherwise avoid public URLs
