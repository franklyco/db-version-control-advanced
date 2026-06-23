# Optional command ideas

Only add commands that the repo actually needs and the installed Repomix version supports.

## Minimal set
- Full pack:
  - `npx repomix@latest`
- Compressed pack:
  - `npx repomix@latest --compress`
- JSON pack:
  - `npx repomix@latest --style json -o repomix-output.json`

## Useful optional patterns

### Split output for large repos
- `npx repomix@latest --split-output 20mb`

### Changed-file workflow using stdin
- `git diff --name-only HEAD~1..HEAD | npx repomix@latest --stdin`

### Git-context pack
- `npx repomix@latest --include-diffs --include-logs --include-logs-count 10`

Do not add all of these by default. Keep only what the repo will actually use.
