# Distribution Drift

The plugin lives in two GitHub repositories with **unrelated git histories**:

| Repository | LocalWP site | Role |
|---|---|---|
| `franklyco/db-version-control-main` | `dbvc-codexchanges.local` | development; all PRs land here |
| `franklyco/db-version-control-advanced` | `frameworkflo-live.local` | the copy deployed to the live site |

They were split deliberately to keep an independent backup. They share **no commits**: different root commits (`beb630a` vs `bc11498`), and neither repository contains a single object from the other. That has two consequences worth stating plainly, because both are easy to get wrong:

1. **Nothing can be merged between them.** `git merge`, `git cherry-pick` and `git diff` cannot span the two. Syncing is a file copy, followed by a commit in the receiving repository.
2. **A commit from one repository being absent in the other is not drift.** Expecting `db-version-control-advanced` to "know" a `master` SHA is a category error.

Because the copy is manual, drift is invisible unless something compares content.

## The check

```bash
composer dist:drift          # human readable, exit 1 on unexpected drift
composer dist:drift-json     # same result as JSON
```

Git blob names are the SHA-1 of file content, so a path whose blob name matches in both repositories is byte-identical even though no commit is shared. That is what the script compares — not timestamps, not commit graphs.

Defaults live in [`scripts/distribution-drift.json`](../../scripts/distribution-drift.json); override per run with `--distribution=<path>`, `--ref=`, `--distribution-ref=`.

It reports four buckets:

- **identical** — same path, same content.
- **differing** — same path, different content. Almost always development being ahead.
- **missing from distribution** — exists here, absent there.
- **unexpected in distribution** — exists there, absent here and not allow-listed.

Exit codes: `0` no unexpected drift, `1` drift found, `2` could not run.

## Reading the result

The config carries two allowlists, and keeping them honest is the whole value of the tool:

`distribution_only` — paths that legitimately exist only in the distribution repo, so they are not reported as unexpected. As of 2026-10-18 that is the ChatGPT `distribution/` package, the Visual Editor handoff notes, and a few reference docs. **These exist nowhere else**, so a sync that mirrors development over the distribution repo would destroy them. Copy in one direction, file by file; never reset the distribution repo to match.

`development_only` — build scaffolding, repomix config, editor settings, Playwright and fixture files that the distribution copy is not expected to carry.

`tests/` is deliberately **not** in `development_only`. Neither repository's `.gitignore` excludes tests, and the distribution repo contains test files of its own, so a missing test is genuine drift. As of 2026-10-18 **177 `tests/phpunit` files are missing from the distribution copy** — an omission from past manual copies, not a boundary.

The script also asserts the premise: if the two repositories ever come to share history, `shares_history` flips to `YES` and the assumption behind this doc no longer holds.

## When to run it

- Before and after copying development into the distribution repo, so the copy is verifiable rather than hopeful.
- Before deploying the distribution repo to the live site, to see what the site is about to run.
- When a feature appears to be missing on the live site.
