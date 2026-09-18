# Codex Prompt — R3 Registry-Backed Brand Control Center

Use after R0 is complete and, under the default program sequence, after R1–R2 are production-ready. If the user intentionally parallelizes work, preserve dependency isolation and do not alter the shipped Media Manager.

```text
Implement R3 of the DBVC Visual Editor Brand Controls program: Registry-Backed Brand Control Center.

Read:
- docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/02-TARGET-ARCHITECTURE-AND-BOUNDARIES.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/SECURITY-AND-DATA-SAFETY.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/TEST-QA-RELEASE-GATES.md
- the completed R0 evidence, decisions, risks, and tracker entries

Adapt to the current codebase and actual R0 extension points. Preserve uncommitted work. Do not add functionality outside R3.

Required outcome:
- a narrow provider-aware control registry;
- validation and duplicate handling;
- compatibility with current Shared Globals settings and behavior;
- a minimal production Brand Control Center listing approved controls;
- fresh descriptor resolution when a control opens;
- reuse of the existing main panel, save contracts, acknowledgement, journal, cache, and reload behavior;
- feature/fallback behavior consistent with current settings architecture;
- tests and release notes.

Do not add new ACF mutation families, a full expanded UI, a Site Manager drawer, Site Assurance, additional Media Manager scope, design tokens, staging, undo, or custom tables.

Work in small reviewable slices. Before editing, confirm the actual files/symbols and summarize the intended delta. After implementation, run the relevant automated tests and provide browser/manual QA steps, compatibility evidence, known limitations, and rollback instructions. Update the tracker, evidence, decision, and risk records.
```
