# Repomix Maintenance

Repomix is a lean packing layer here. It complements `AGENTS.md`, `README.md`, `handoff.md`, and the Content Collector V2 docs rather than replacing them.

## Files

- `repomix.config.json`
- `.repomixignore`
- `repomix-instruction.md`
- `docs/repomix-preflight.md`
- `docs/repomix-maintenance.md`
- default output: `tmp/repomix-output.xml`

## Default Commands

- Full pack: `npx --yes repomix@latest`
- Compressed pack: `npx --yes repomix@latest --compress -o tmp/repomix-output-compressed.xml`

## Refresh When

- top-level directories change
- directive docs or the V2 resume pack move
- new generated or noisy folders appear
- packed output starts including logs, screenshots, fixtures, or other low-value files

## Refresh Check

Confirm the output still prioritizes live source, tests, manifests, and core docs, and still excludes repo noise such as `build/`, `docs/fixtures/`, `repomix-starter-kit/`, `test-results/`, and local log or image artifacts.
