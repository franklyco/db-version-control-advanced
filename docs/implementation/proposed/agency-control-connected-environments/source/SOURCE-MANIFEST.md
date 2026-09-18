# Preserved source-package manifest

This directory holds the immutable source archive for the Agency Control handoff. It is reference material, not DBVC runtime code or current implementation authority.

| Field | Value |
|---|---|
| Archive | `Agency-Control-DBVC-Implementation-Package-2026-09-13.zip` |
| Original local source at promotion | `docs/dropins/Agency-Control-DBVC-Implementation-Package.zip` |
| Archive SHA-256 | `abc318ffe2000d4ac1043eb26373787df2c81578ecfdbdf838cc75ac8bb92aa1` |
| Archive integrity at promotion | `unzip -t` reported no compressed-data errors |
| Package date | 2026-09-13 |
| Package status | Proposed implementation plan and starter; not a functioning synchronization product |
| Internal checksums | `agency-control-handoff/FILE-CHECKSUMS.json` inside the archive |

## Handling rules

- Keep this ZIP unchanged. A revised source package gets a new dated filename and manifest entry; it does not overwrite this one.
- Read the canonical tailored docs one level above before using material from this archive.
- Do not copy its scaffold into DBVC code, register it, or treat archive tests as WordPress/runtime verification.
- Extract source material only to a reviewed temporary directory when a task requires it. Do not unpack it over the repository tree.
- The package contains no authority to alter remote sites, credentials, production content, or the current dirty worktree.

The archive has 35 source entries, including draft contracts, a separate hub scaffold, an inert DBVC observer seam, local inspection scripts, and an offline specification. Its historical evidence must be reconciled under [`../reconciliation.md`](../reconciliation.md) before adoption.
