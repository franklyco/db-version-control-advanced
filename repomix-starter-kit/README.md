# Repomix Bootstrap Starter Kit

This starter kit is meant for a **one-time initial Repomix install/configuration pass**.

The goal is to spend thinking and setup effort once, then leave behind a **lean, durable, repo-specific Repomix system** that reduces future token usage and repeated discovery work.

## Recommended use

1. Unzip this package.
2. Either:
   - place the folder in the target repository root as `repomix-starter-kit/`, or
   - keep it outside the repo and paste the install prompt manually while referencing the included files.
3. Paste `INSTALL_PROMPT.md` into Codex or another repo-capable agent.
4. Let the agent inspect the repo and adapt these templates.
5. Keep the final repo-specific Repomix files in the repo root or the repo's preferred docs/AI area.
6. Optionally remove this starter kit folder after the final setup is complete.

## Design rules for the installed system

- Keep all persisted Repomix-related outputs **minimal, repo-specific, and non-redundant**.
- Prefer the **smallest lasting footprint** that still creates a durable maintenance system.
- Use the official supported Repomix capabilities that the agent verifies in the installed version.
- Avoid turning Repomix into a large documentation or workflow bureaucracy.
- Respect existing directive files, repo maps, indexes, graph/RAG systems, and onboarding docs.

## What is included

### Templates
- `templates/repomix.config.template.json`
- `templates/.repomixignore.template`
- `templates/repomix-instruction.template.md`
- `templates/REPOMIX_PREFLIGHT.template.md`
- `templates/REPOMIX_MAINTENANCE.template.md`

### Snippets
- `snippets/AGENT_APPEND_SNIPPET.md`
- `snippets/OPTIONAL_COMMANDS_SNIPPET.md`

### Prompt
- `INSTALL_PROMPT.md`

## Important usage notes

- The included config file is a conservative starting point, not a guarantee that every option should remain enabled in every repo.
- The included maintenance and preflight templates are intentionally short. Keep them that way after adaptation.
- If the target repo is mid-feature, has a dirty working tree, or has multiple active branches/agents, start with the least invasive path first.
- Do not add CI, dependency installs, lockfile changes, or broad directive rewrites unless the repo clearly benefits and the agent has validated the choice.

## Recommended final repo footprint

In most repos, the final durable footprint should usually be limited to:

- `repomix.config.json` (or `.js` / `.ts` only if clearly justified)
- `.repomixignore`
- `repomix-instruction.md`
- one concise preflight note
- one concise maintenance note
- one compact directive snippet added to existing agent docs

Anything beyond that should be justified by the repo, not by the starter kit.
