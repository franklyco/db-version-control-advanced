# Codex Prompt — Reconcile an Existing v1 Drop-In or Partial Implementation

```text
The DBVC Visual Editor implementation guide was updated to package version 2.0.0. Media Manager is now R1/R2, and the earlier Brand Control Center releases moved to R3–R6.

Read:
- docs/dropins/dbvc-visual-editor-brand-controls-guide/PACKAGE-UPDATE-NOTES.md
- README.md
- 00-GOVERNING-DIRECTIVES.md
- tracking/IMPLEMENTATION-TRACKER.md

Before making code changes:
1. inspect git status, current branch, recent commits, and all uncommitted work;
2. identify whether the old v1 guide remains in the repository or whether any old R1–R4 implementation work has started;
3. preserve all factual evidence/decisions already recorded;
4. map old release work to the new numbering:
   old R1→new R3, old R2→new R4, old R3→new R5, old R4→new R6;
5. identify dependencies or conflicts with the new R1/R2 Media Manager priority;
6. do not reset or rewrite stable completed work merely to follow the new order;
7. remove/replace conflicting old guide documents only within the docs/dropins package and only after preserving project-specific notes;
8. produce a concise reconciliation report and corrected next-session plan.

Do not implement a new feature in this reconciliation session unless the current user explicitly asks you to proceed after the report.
```
