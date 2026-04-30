# Contributing

## Working style

- Read `AGENTS.md` first.
- Keep changes modular.
- Prefer additive, reversible slices.
- Update docs when architecture or flow changes.

## Branching

Use short-lived branches for non-trivial work:
- `codex/visual-editor-mvp`
- `task/bricks-instrumentation`
- `fix/save-pipeline-validation`

## Validation

Use the lightest validation that matches risk:
- PHP syntax checks
- targeted runtime checks
- broader smoke validation only at logical checkpoints

## Documentation

If you change:
- module boundaries
- request flow
- descriptor structure
- REST shape
- supported scopes

then update the relevant docs in `/docs/`.
