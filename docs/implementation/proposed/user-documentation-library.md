# DBVC User Documentation Library

Status: Draft seed file for future user-facing documentation library.

## Bricks Add-on: Drift and Package Transport

### What "Drift" means in DBVC Bricks

In the Bricks add-on, drift is a read-only comparison between:

- the selected package manifest artifacts (golden source), and
- the current local site artifacts (actual state).

For each artifact, DBVC computes canonical hashes and reports status such as:

- `CLEAN`
- `DIVERGED`
- `OVERRIDDEN`
- `PENDING_REVIEW`

Drift scan does not write, mutate, or apply changes by itself.

### What is sent from a client site to mothership

Client-to-mothership transport is package-based. A package contains a manifest with artifacts.

Default bootstrap package behavior:

- includes all `bricks_template` entities found on the site, and
- includes registered Bricks options that are marked for inclusion (for example global classes, color palette, typography, theme styles, and related global settings).

This means the default package is a snapshot-style payload, not an automatic per-change delta stream.

### If one template changes, what gets sent?

If only one `bricks_template` changes, DBVC does not automatically send only that single template.

What gets sent depends on the package you publish:

- if you publish a default bootstrap package, it includes the full collected artifact set;
- if a custom/narrow package is built manually, only those included artifacts are sent.

### Is Bricks data always sent on save/update?

No. Bricks add-on publish actions are explicitly triggered through UI/API package actions (for example bootstrap create, local publish, or remote publish). Saving a template alone does not auto-publish a package to mothership.

### Is sending scheduled?

Not by default for package publish. An hourly scheduled hook exists, but it is a placeholder and does not perform automatic package publishing in current behavior.

### If a template is saved multiple times in an hour

Those saves are not automatically sent as individual remote transmissions.

Only explicit publish actions send package payloads. Each publish action sends the package content at that time.

## Backlog Note

This file is the initial seed for a future in-plugin/user-facing documentation library system. See roadmap/tracker entries for planned integration work under the `DBVC_USER_DOCUMENTATION_LIBRARY` effort.
