Install and configure a **lean, durable, one-time Repomix bootstrap** for this repository.

A Repomix starter kit is available for reference. If a folder such as `repomix-starter-kit/` exists in the repo, use it as the primary starting point and adapt its templates instead of inventing a larger system from scratch.

## Goal

Create a **minimal lasting Repomix system** that reduces future token usage and repeated repo discovery.

This is a **one-time bootstrap task**, not a recurring documentation exercise. The bootstrap prompt may be comprehensive, but all persisted Repomix-related outputs must be:

- minimal
- repo-specific
- non-redundant
- easy for future agents to maintain

Prefer the **fewest files, fewest commands, and shortest durable instructions** that still create a reliable setup.

Verify every Repomix capability against the installed version before you document it or add scripts for it.

---

## Hard rules

- Inspect first, then decide.
- Start with the **least invasive approach**.
- Prefer `npx repomix@latest` or equivalent zero-friction usage first.
- Only promote to a local dependency if the repo clearly benefits.
- Do not invent unsupported Repomix config keys, commands, or workflows.
- Do not create large Repomix docs.
- Do not create redundant summaries that duplicate existing repo docs.
- Do not replace existing directive files; extend them carefully.
- Keep Repomix aligned with existing authoritative docs, indexes, repo maps, graph systems, RAG/context systems, and onboarding materials.
- If the working tree is dirty, another feature is in flight, or multiple agents appear active, avoid dependency installs, lockfile edits, CI edits, and broad workflow changes unless clearly justified.

---

## Phase 0 — Inspect the repo

Inspect and summarize:

- repo type
- primary languages/frameworks
- package manager / task runner if present
- likely source directories
- likely test directories
- main manifests
- existing CI
- existing directive files:
  - `AGENT.md`
  - `AGENTS.md`
  - `CODEX.md`
  - `CLAUDE.md`
  - `.cursor/rules/*`
  - `.github/copilot-instructions.md`
  - `README.md`
  - `CONTRIBUTING.md`
- existing repo-context systems:
  - repo maps
  - indexes
  - generated summaries
  - graph / RAG systems
  - onboarding docs
  - workflow / handoff docs
- likely token-heavy noise folders

Do not install anything yet.

---

## Phase 1 — Preflight compatibility pass

Before configuring Repomix, inspect any existing directive hierarchy and repo-context systems.

Determine for each relevant system:

- what it is
- where it lives
- whether it is current or stale
- whether it is authoritative
- what problem it solves
- whether Repomix should complement it, integrate with it, or avoid duplicating it

Create **one concise preflight note** using the starter kit template if available.

Preferred outputs:

- `docs/ai/repomix-preflight.md`
- `docs/repomix-preflight.md`
- `REPOMIX_PREFLIGHT.md`

Keep it short and useful.

### Decision rule

Do **not** treat Repomix as a replacement by default.

Choose one of these roles and document it briefly:

- Complement
- Integrate
- Selective consolidation
- Do not duplicate

---

## Phase 2 — Choose install path

Start with the least invasive path.

### Preferred default
Use zero-friction CLI usage first, such as:
- `npx repomix@latest`

Promote to a local dependency only if:
- the repo already has a natural Node-based workflow
- the repo would genuinely benefit from local scripts
- the change will not create unnecessary churn

Do not introduce a new package manager.

---

## Phase 3 — Create the minimal durable file set

Use the starter kit templates if present and adapt them to this repo.

Create or update only the minimum durable files needed:

1. `repomix.config.json`  
   Use JSON unless JS/TS config is clearly justified by the repo.
2. `.repomixignore`
3. `repomix-instruction.md`
4. one concise preflight note
5. one concise maintenance note
6. one compact addition to the repo's existing agent/directive layer

Do not create extra Repomix files unless the repo clearly needs them.

### Config guidance

Use only supported Repomix options that you verify.

Bias toward:
- readable stable output
- security checks enabled
- `.gitignore`, `.ignore`, and default ignore patterns enabled unless there is a strong reason not to
- instruction file path enabled
- git diffs/logs disabled by default unless clearly useful
- compression available but not necessarily the default
- JSON output optional, not mandatory
- split output optional, not mandatory

### Ignore guidance

Exclude token-heavy noise, generated assets, caches, vendored dependencies, logs, recordings, screenshots, exports, and local clutter where appropriate.

Do not exclude important:
- source
- tests
- migrations
- schemas
- key docs
- important manifests

---

## Phase 4 — Keep persisted artifacts lean

The installed Repomix system must be lighter than this bootstrap prompt.

Requirements:

- maintenance note: concise
- preflight note: concise
- instruction file: concise
- directive additions: compact
- no repeated explanations across files
- reference authoritative existing docs instead of re-summarizing them

If two files say the same thing, simplify.

---

## Phase 5 — Add workflows only if justified

Only add commands/scripts if they fit the repo naturally and the installed Repomix version supports them.

Possible examples:
- full pack
- compressed pack
- JSON pack
- split output for large repos
- changed-file workflow via `git diff ... | repomix --stdin`
- git-context workflow using diffs/logs

Do not add every possible variant.
Keep only the commands the repo will realistically use.

If the repo has no natural place for scripts, document exact commands in the maintenance note instead.

Do not add CI unless the benefit is obvious and the repo already uses CI in a compatible way.

---

## Phase 6 — Validate the actual setup

Actually run and verify the setup.

Validate at least:

- chosen install path works
- config file loads
- normal full output can be generated
- compressed output can be generated if retained
- JSON output works if you keep it
- split output works if you keep it
- any added commands/scripts actually work
- no obvious sensitive files are included
- directive updates and maintenance/preflight notes point to the right files
- the final setup matches the chosen Repomix role from preflight

If you omit an optional workflow, say why.

---

## Phase 7 — Final report

At the end, report:

1. repo/environment detected
2. chosen Repomix role
3. chosen install path and why
4. files created
5. files updated
6. commands/scripts added
7. notable ignore decisions
8. token-heavy hotspots found
9. validation results
10. day-to-day recommendation for future agents

---

## Quality bar

The correct outcome is **not** “the most elaborate Repomix system.”

The correct outcome is:

- the smallest durable setup
- verified against the current Repomix version
- aligned with the repo's real structure
- aligned with existing directive/context systems
- useful for future agents
- low-noise and low-token
