# Codex Prompt — R4 Expanded Global & Brand Control Center

```text
Implement R4 of the DBVC Visual Editor Brand Controls program: Expanded Global & Brand Control Center.

R3 must already be production-ready. Read the governing directives, target architecture, R4 release guide, quality documents, UI/UX mockup documents, and current tracking/evidence records under:

docs/dropins/dbvc-visual-editor-brand-controls-guide/

First perform a release-specific discovery delta and confirm the actual R3 components, list/read model, current CSS/event conventions, and remaining Shared Globals compatibility path.

R4 is a UI/read-model/workflow release. Add:
- meaningful non-empty categories based on registered metadata;
- search and only the filters justified by current product data;
- deterministic grouping/sorting;
- safe type-specific value summaries;
- clear editable, inspect-only, unsupported, unavailable, loading, empty, no-results, and error states;
- discovery of registered controls not rendered on the current page;
- continued opening through the existing main editor panel;
- Shared Globals compatibility/fallback.

Do not enable new ACF option-field families in this release and do not add inline editing, bulk actions, pins, Site Assurance, additional Media Manager scope, design tokens, or the R6 drawer.

Before final production markup/styling:
1. write a verified UI Requirements Brief and Data/State Contract from the actual code;
2. prepare the handoff for Claude Code using ui-ux/CLAUDE-CODE-MOCKUP-HANDOFF.md;
3. ingest the returned static HTML/CSS mockup as a visual reference;
4. record accepted, adapted, and rejected decisions;
5. wire approved design intent into existing DBVC components rather than copying the mockup wholesale.

Complete tests, accessibility, supported laptop/desktop layout, security, performance, compatibility, release notes, rollback, and tracking updates before marking R4 ready. Additional responsive/mobile and touch-specific work remains tabled by D-036 until explicitly reauthorized.
```
