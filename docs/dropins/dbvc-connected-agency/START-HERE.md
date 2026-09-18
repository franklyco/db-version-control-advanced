# DBVC Connected + Agency Control

Package 0.2.0 · 2026-09-16 · implementation handoff, not an installable plugin

This package supersedes the separate-repository architecture in the earlier Agency Control handoff. Build within the existing DBVC repository. Use two independently gated add-ons: Connected Environments on participating sites and Agency Control on the designated studio hub. Keep DBVC's existing engines and optional Visual Editor.

## Place the package

Extract the ZIP's single `dbvc-connected-agency` folder into your local DBVC checkout's `docs/dropins/` directory. The resulting entrypoint is:

`docs/dropins/dbvc-connected-agency/START-HERE.md`

Do not upload this ZIP in WordPress Plugins. Do not copy its whole contents to the plugin root. Its PHP templates deliberately end in `.php.stub`; nothing under this package should be loaded at runtime. No automatic installer, database migration, credential enrollment, or bootstrap patch runs when you extract it.

Open the DBVC repository in Claude Code or Codex and paste the complete prompt in [KICKOFF-PROMPT.md](KICKOFF-PROMPT.md). That prompt authorizes local discovery and the first bounded implementation slice. The remaining milestones are planned separately.

## What is ready

| Included | Status |
|---|---|
| Architecture, ownership, extraction plan, delivery gates | Proposed implementation decisions |
| Source reuse map and GitHub source lock | Evidence from the pinned checkout; local reconciliation required |
| Add-on bootstraps, contracts, comparison and routing templates | Starter PHP; not wired to DBVC or WordPress storage |
| Observation schema and synthetic examples | Draft wire contract; not current REST routes |
| Offline executable specification | Behavior examples with tests; not authentication, persistence, or production sync |
| Repository inventory and package validation scripts | Local developer utilities; no WordPress calls or network |
| Authentication, SQL stores, workers, REST, UI, apply | Implementation tasks, not delivered working features |

See [evidence/PACKAGE-VALIDATION.md](evidence/PACKAGE-VALIDATION.md) for the exact checks performed.

## Read in order

1. The repository's own `AGENTS.md`, `docs/README.md`, `docs/agent-entrypoints.md`, and relevant module instructions.
2. [IMPLEMENTATION-GUIDE.md](IMPLEMENTATION-GUIDE.md).
3. [docs/01-architecture.md](docs/01-architecture.md), [docs/02-integration-map.md](docs/02-integration-map.md), and [docs/03-reuse-and-evidence.md](docs/03-reuse-and-evidence.md).
4. Only the milestone-specific documents needed for the current slice.

The local branch and uncommitted work take precedence over this historical source snapshot. Do not reset to the pinned commit. Keep these drop-in documents as the intake package; link them from the existing roadmap, then maintain implementation truth in the repository's established documentation system rather than duplicating it.

## Useful package checks

Run from this package directory:

```bash
python3 scripts/validate_package.py
python3 -m unittest discover -s tests -v
python3 scripts/demo.py
```

These require only Python 3 standard-library modules. For template PHP lint and the actual PHP contract harness, see [scripts/README.md](scripts/README.md). Do not count Python examples as PHP runtime verification.
