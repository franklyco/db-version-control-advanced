# DBVC Content Migration Workbench — Handoff Package

This package is a repo-drop handoff for implementing the proposed **DBVC Content Migration Workbench** inside the existing local DBVC codebase.

The goal is not to force a greenfield UI. The goal is to help Codex or another implementation agent:

1. inspect the current DBVC architecture,
2. identify the real surfaces that already exist,
3. map this spec onto the current code and state model,
4. avoid overbuilding,
5. deliver the workbench incrementally.

## Package contents

- `CODEX_KICKOFF_PROMPT.md`
  - copy/paste prompt to guide Codex discovery and adaptation
- `package-manifest.json`
  - lightweight manifest for quick scanning
- `docs/01-product-overview.md`
  - why the workbench exists and what problem it solves
- `docs/02-workbench-ui-layout.md`
  - exact layout and UI composition
- `docs/03-components-and-state.md`
  - proposed component inventory, state model, events, and props
- `docs/04-interaction-flows.md`
  - operator workflows and decision paths
- `docs/05-implementation-plan.md`
  - phased implementation roadmap
- `docs/06-acceptance-criteria.md`
  - practical definition of done
- `docs/07-adaptation-notes.md`
  - how to adapt to the actual DBVC repo instead of forcing this spec literally
- `docs/08-wireframes-ascii.md`
  - wireframe-style sketches and screen states
- `docs/09-data-contracts-and-status-model.md`
  - suggested data shapes for UI integration
- `docs/10-risk-and-anti-patterns.md`
  - what not to build and common traps

## Intent

This handoff assumes:

- DBVC already has some form of Content Migration / V2 run pipeline.
- There is already a recommendation output or candidate mapping surface.
- The current interface is likely too field-card-oriented and too fragmented.
- The new workbench should be **section-first**, **page-level**, and **review-by-exception**.

## Important implementation stance

This package intentionally avoids hard-coding framework assumptions where possible.

Codex should first inspect:

- the existing migration UI entry points,
- the current route/file structure,
- current state/store strategy,
- component library/design system,
- current run/page/recommendation schemas,
- existing action handlers for approve/reassign/re-run,
- any existing audit/logging patterns.

Then it should adapt this spec to the actual repo rather than transplanting it blindly.

## Recommended first step inside the repo

Start with `CODEX_KICKOFF_PROMPT.md`.


## Added architecture context

- `docs/00-architecture-findings-and-recommendations.md` — earlier strategic findings and recommendations that shaped the workbench direction.
