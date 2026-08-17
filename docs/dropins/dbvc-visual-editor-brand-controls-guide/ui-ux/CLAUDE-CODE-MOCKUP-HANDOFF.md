# Claude Code Static Mockup Handoff

Claude Code will provide static HTML/CSS mockups for UI-heavy releases. Codex must first define the verified runtime data, actions, states, and constraints. The mockup is a design reference; Codex remains responsible for production architecture, security, accessibility, and wiring.

## Applicable releases

- R1 Media Manager scan/report
- R2 Media Manager remediation refinement
- R4 Expanded Global & Brand Control Center
- R6 Frontend Site Manager Workspace

R3 and R5 may use targeted mockups only when their actual UI delta warrants it.

## Required sequence

1. Codex completes release-specific discovery.
2. Codex writes a UI Requirements Brief.
3. Codex freezes a safe display view model and allowed action list.
4. Codex provides current Visual Editor screenshots/tokens/components where available.
5. Claude Code returns static HTML/CSS and state examples.
6. Codex reviews accepted/adapted/rejected decisions.
7. Codex implements the design intent in current DBVC components.
8. Runtime/security/accessibility/browser tests are performed independently.

Do not ask Claude to infer backend contracts from the concept PNG.

## What Codex must provide

### Product goal

Name the exact release and one primary user task. Avoid redesigning the whole Visual Editor.

### Existing visual/runtime context

Provide when available:

- current toolbar, popover, main panel, and Review Fields screenshots;
- current CSS variables/tokens and namespace;
- panel width, layering, admin-bar, and viewport constraints;
- focus/Escape/outside-click behavior;
- known site-CSS and Bricks conflicts;
- existing loading/status/error components;
- current Media Library modal behavior for R2.

### Safe display record

Only properties the server can safely provide. Examples:

#### Media Manager list record

```text
opaque group reference
safe entity label
object type label
permitted route summary
missing count
finding-family counts
updated/scanned label
status
allowed read actions
```

#### Media Manager expanded field record

```text
opaque finding reference
field label
family
safe context label
empty/status summary
safe preview data
writable/inspect-only state
allowed actions
```

#### Brand control record

```text
opaque control reference
label
description
category/group
owner/source label
scope
field family
status
safe value summary
allowed actions
```

Do not provide arbitrary field keys, option keys, owner IDs, row paths, nonces, or write payloads as mockup contracts.

### Verified actions

Name only actions supported by the release.

R1 examples:

- open/close Media Manager;
- start/refresh/cancel scan where implemented;
- search/filter/sort/page;
- expand/collapse entity;
- open permitted frontend/backend route;
- retry/refresh finding.

R2 adds only verified actions such as:

- choose media;
- upload through native modal;
- manage gallery;
- discard draft selection;
- save field;
- save one entity row if approved.

R4 examples:

- search/filter/open registered control;
- open existing main editor;
- close center.

R6 examples:

- navigate permitted objects;
- open Review Fields, Media Manager, Brand & Globals;
- open backend/frontend route;
- exit Visual Editor.

Do not ask Claude to invent bulk save, drag/drop, object creation/deletion, arbitrary inline editing, or persistent workflows outside the release.

## Required states

Common:

- populated/default;
- loading;
- empty;
- no search/filter results;
- recoverable error;
- permission-limited/unavailable;
- long labels/content;
- keyboard focus;
- narrow viewport;
- reduced-motion intent.

Media Manager:

- no scan yet;
- scanning progress;
- scan complete;
- expired/failed scan;
- large result set;
- collapsed row;
- expanded loading;
- expanded writable/inspect-only mix;
- resolved/changed since scan;
- R2 draft image selected;
- R2 media modal layering note;
- per-field save success/error/stale outcome;
- no upload permission.

R4:

- mixed editable/inspect-only controls;
- unavailable provider/source;
- descriptor-loading transition.

R6:

- persistent desktop drawer;
- overlay/mobile drawer;
- object without frontend route;
- paginated results;
- coexistence with main panel and Media Library.

## Accessibility constraints

Require:

- semantic HTML;
- visible keyboard focus;
- full keyboard operation;
- no color-only meaning;
- readable contrast;
- touch-friendly targets;
- logical heading order;
- appropriate dialog/drawer/navigation/table semantics;
- expansion buttons with `aria-expanded` intent;
- reduced-motion-friendly transitions;
- clear status/error text;
- focus restoration notes.

## Technical constraints

- Static HTML/CSS by default.
- Minimal mockup-only JavaScript only when explicitly requested for state demonstration.
- No backend calls.
- No external framework/CDN unless current repo already uses it and Codex authorizes it.
- No global selectors such as unscoped `button`, `input`, `body`, `a`, or `*`.
- Use a mockup root namespace.
- Do not assume production event names, IDs, data attributes, endpoints, or component framework.
- No production secrets/client data.
- Use the fixture under `fixtures/` only as display data.
- Do not copy mockup CSS/JS wholesale into production.

## Review questions

- Does the design expose only safe display data?
- Does it add actions outside release scope?
- Does it imply cross-entity bulk mutation?
- Does it imply a custom uploader?
- Can it map onto existing Visual Editor components?
- Does it conflict with toolbar/popover/main panel/Media Library layering?
- Are loading, empty, error, stale, unsupported, and permission states complete?
- Is focus order workable?
- Are selectors scoped?
- Does mobile behavior preserve the workflow?
- Does any count/label imply more certainty than the scan or usage evidence provides?

Record decisions before production implementation.
