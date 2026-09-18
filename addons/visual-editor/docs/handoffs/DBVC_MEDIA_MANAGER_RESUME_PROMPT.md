# Copy/Paste Prompt — Resume DBVC Frontend Media Manager

Copy the text below into a fresh Codex task. The prompt deliberately delegates detailed history to the current handoff so the new task uses fewer initial tokens.

```text
Resume the DBVC Frontend Media Manager implementation in this repository:

/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main

Start read-only. Read and follow:

1. docs/README.md
2. docs/agent-entrypoints.md
3. addons/visual-editor/AGENTS.md
4. addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md

Then read only the task-specific current R1 release, tracker, QA gates, and files that the handoff directs you to. Treat current code and Git state as authoritative over the docs.

Before editing, report:

- git status --short --branch;
- active branch and exact HEAD;
- upstream divergence;
- the existing tracked/untracked dirty boundary;
- whether the active LocalWP/runtime provenance still matches the handoff.

The recorded base is branch codex/visual-editor-linked-posts-plan at 5db4b4094c0d834b3cf482adb095273387b59dc8, but the accumulated R0/R1 implementation is in the dirty working tree. Preserve all current changes. Do not reset, restore, stash, clean, broadly stage, commit, push, switch branches, or discard unrelated work.

Objective for this task:

Complete the smallest safe R1-E closeout slice from the handoff. Refresh focused validation; obtain authenticated active-site REST/table proof only if an already-authorized authenticated session is available; add deterministic non-mutating candidate-traversal/raw-read performance evidence where feasible; distinguish WebKit automation from real Safari and automation from real assistive technology; give aggregate JavaScript lint one bounded current attempt; and reconcile the R1 release gates, evidence, decisions, risks, tracker, module docs, and roadmap.

Do not change persistent WordPress options, login state, content, media assignments, credentials, database data, or LocalWP configuration just to satisfy QA. Do not install or upgrade dependencies. If an authenticated session, real Safari, or real assistive-technology pass is unavailable, record the exact residual gate instead of fabricating evidence.

Keep additional mobile/responsive work tabled under D-036. Preserve the existing responsive regression floor, but add no new mobile layouts, cards, slide-overs, touch refinements, or mobile-specific QA.

R1 remains read-only. Do not create descriptors from findings, call wp.media from the Media Manager, mutate content, write journal rows, invalidate content caches, or begin R2 unless R1 is signed off or I explicitly authorize the R2-A crossing line in this task.

If R1-E is fully closed or its remaining evidence risks are explicitly accepted, propose R2-A as the next bounded slice only: a server-authoritative bridge from one opaque current finding to one fresh standard descriptor, with current snapshot/owner/capability/field-applicability/field-family/empty-state revalidation. R2-A must stop before Media Library selection or content mutation.

Use focused checks first. Current known evidence is 11/11 Media Manager jsdom tests, 22/685 combined R1-A through R1-E PHP assertions/tests, 6/6 isolated Playwright engine cases, and a full PHP comparison of 706 tests/7,820 assertions with the same six inherited failures. Targeted Media Manager lint passes; the latest aggregate repository lint did not complete. Reverify rather than blindly trusting those counts.

When public REST, settings, add-on, hook, or safety contracts change, follow docs/agents/MAINTENANCE.md and run composer agent-docs:check. Do not claim a full lint, real Safari, real assistive-technology, authenticated runtime, or green PHP suite without current evidence.

At handback, provide:

1. branch/HEAD/divergence/dirty boundary;
2. exact completed scope;
3. files changed;
4. reused and new contracts;
5. commands and results;
6. inherited versus new failures;
7. runtime/browser/accessibility/performance evidence limits;
8. docs/tracker reconciliation;
9. residual risks and rollback;
10. the exact next approval line.

Stop after the bounded R1-E closeout and revised next-phase recommendation. Do not stage, commit, push, or publish.
```
