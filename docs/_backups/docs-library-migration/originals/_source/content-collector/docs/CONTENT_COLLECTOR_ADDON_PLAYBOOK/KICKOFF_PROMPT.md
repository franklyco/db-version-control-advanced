# DBVC Kickoff Prompt (Content Collector Addon)

Use this prompt in the DBVC plugin workspace after placing source reference files under `./_source/content-collector`.

```text
You are working in the DBVC plugin project.

Context:
- We dropped a source folder at: `./_source/content-collector`
- Treat this as source code to absorb into DBVC addon modules.
- Do NOT preserve standalone plugin runtime compatibility.
- Do NOT implement legacy wrappers.
- Goal: migrate crawler + explorer + AI + export capabilities into DBVC-native architecture.

Primary source-of-truth manifest:
- `./_source/content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
- `./_source/content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
- `./_source/content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`

Execution requirements:
1) Read and follow the manifest exactly for file inventory, route contracts, option keys, pipeline steps, and storage contracts.
2) Enforce all guardrails from `GUARDRAILS.md` across every slice (prefix isolation, no runtime coupling to source folder, feature flags, contract lock, dry-run gate, idempotency, regression gate).
3) Follow phase sequence and acceptance criteria from `PHASE_PLAN.md`.
4) Before implementation, run preflight cleanup:
   - remove nested source VCS metadata (`./_source/content-collector/.git`)
   - remove source noise (`./_source/content-collector/dev-data`, `.DS_Store`)
   - add and verify guard check(s) that fail if DBVC runtime imports/requires from `./_source/content-collector`
   - add ignore rules for generated artifact/output folders
5) Start by producing a concrete migration plan mapped to actual DBVC file paths.
6) Then implement in dependency order:
   - artifact manager/storage helpers
   - crawler pipeline
   - explorer service + routes + UI
   - AI service + routes + status polling
   - export service + routes
7) Keep behavior parity with source contracts (artifacts, statuses, logs, manifest outputs).
8) Preserve deterministic fallback behavior when OpenAI is unavailable.
9) Reuse fixture payloads from `./_source/content-collector/tests/fixtures` for parity checks.
10) Do not import `./_source/content-collector` source files at runtime in DBVC production paths.

Output format each cycle:
- "Plan update"
- "Files changed"
- "Behavior parity status"
- "Open risks"
- "Guardrail compliance status"

Start now with:
- preflight cleanup + guard check verification,
- a migration map from source file -> DBVC target file,
- then implement the first slice (artifact manager + settings wiring).
```

## Suggested Codex Follow-Up Prompt (Second Pass)

```text
Continue with the next slice from the migration map.
Implement crawler + AJAX handlers and verify deterministic storage/index/redirect/log outputs match the source contracts in the addon manifest.
```
