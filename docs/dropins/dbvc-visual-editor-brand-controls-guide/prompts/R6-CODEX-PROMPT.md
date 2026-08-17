# Codex Prompt — R6 Frontend Site Manager Workspace

```text
Implement R6 of the DBVC Visual Editor Brand Controls program: Frontend Site Manager Workspace.

Read the governing directives, target architecture, R6 release guide, security/testing documents, UI/UX mockup workflow, and current tracking/evidence records under:

docs/dropins/dbvc-visual-editor-brand-controls-guide/

R1–R5 should already be stable. Begin with a release-specific discovery delta. Confirm the current Go To Object implementation, toolbar/popover state, Review Fields, Media Manager, Global & Brand Control Center, main panel, mode-cookie/nonce routing, object permission logic, CSS layering, and supported laptop/desktop behavior. D-036 tables additional responsive/mobile work until explicitly reauthorized.

Required outcome:
- a persistent laptop/desktop drawer using existing Visual Editor conventions;
- current-object context;
- lazy, bounded navigation for pages, posts, approved CPTs, terms, permission-appropriate users, and media integration;
- valid frontend/backend routes only when permitted;
- preservation of Visual Editor mode across supported frontend navigation;
- integration of Review Fields, Media Manager, Global & Brand Control Center, edit-active-object, and Exit Visual Editor;
- continued use of the existing main panel as the only field editor;
- fallback to current toolbar/popover behavior;
- accessibility, performance, permission, builder-isolation, and supported laptop/desktop tests.

Do not add a small-screen slide-over, responsive-card variant, touch refinement, handset mockup, or mobile-specific QA while D-036 remains active.

Do not add Site Assurance, additional Media Manager scope, pins, named workspaces, bulk editing, object creation/deletion, full media management, or Bricks layout editing.

Before final UI implementation:
1. define the actual navigation read model, state/action contract, focus/Escape/layering behavior, and laptop/desktop layout requirements;
2. prepare a verified Claude Code mockup brief;
3. review returned static HTML/CSS against the runtime architecture;
4. record accepted/adapted/rejected decisions;
5. translate the design into existing DBVC components and scoped styles.

Reuse or safely extract Go To Object behavior rather than duplicating object discovery and permission logic. Protect uncommitted work, keep the release independently rollbackable, and update tests, documentation, tracker, evidence, decisions, and risks.
```
