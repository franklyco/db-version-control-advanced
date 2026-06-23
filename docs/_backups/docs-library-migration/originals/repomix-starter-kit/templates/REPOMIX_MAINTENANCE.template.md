# Repomix Maintenance

## Purpose in this repo

Repomix is used here as a maintained repository packing layer for AI-assisted work. It should stay aligned with the repo's current structure and any existing directive or context systems.

## Where things live

- Config: `repomix.config.json`
- Ignore rules: `.repomixignore`
- Instruction file: `repomix-instruction.md`
- Preflight note: `[replace path]`
- Output file(s): `[replace path]`

## When to refresh this setup

Refresh the Repomix setup when any of these happen:

- new top-level directories appear
- major folders are renamed or moved
- package/workspace structure changes
- new generated artifact folders appear
- test layout changes
- core docs are reorganized
- directive hierarchy changes
- repo maps, indexes, graph systems, or RAG/context systems change
- packed output starts including noisy or sensitive content
- token-heavy hotspots become a problem

## What to check during a refresh

1. Do config, ignore rules, and instruction file still match the repo?
2. Did any directive or context-efficiency systems change?
3. Are important source, tests, schemas, migrations, and core docs still included?
4. Are token-heavy noise folders excluded?
5. Are sensitive files still kept out?
6. Do the current commands still work with the installed Repomix version?

## Output guidance

- Use normal full output for broad repo understanding.
- Use compressed output when size matters more than readability.
- Use JSON output only when a downstream tool truly benefits from structured parsing.
- For changed-file workflows, prefer piping a verified file list into Repomix rather than assuming a built-in shortcut.
- Use git diffs/logs only when code-history context is actually relevant.

## Principle

Repomix here is not a one-time utility. Keep it lean, current, and coordinated with the rest of the repo's context system.
