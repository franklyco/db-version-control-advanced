# Developer utilities

Run from this package directory. Python 3 standard library is sufficient; no `pip install`, npm or Composer installation is needed for package checks.

```bash
python3 scripts/validate_package.py
python3 -m unittest discover -s tests -v
python3 scripts/demo.py
python3 scripts/run_php_checks.py
```

`run_php_checks.py` uses your existing PHP CLI. It copies mapped templates to a temporary directory, lints them, runs a pure PHP contract/bootstrap harness, and removes the temporary files. Exit 2 means PHP is missing and checks were not run. It never activates WordPress, applies SQL or loads the actual DBVC plugin. Match the repository's supported PHP versions when using it in CI.

`validate_package.py` checks original delivery integrity. Once you intentionally modify a package document, its original checksum no longer matches; review the change rather than treating that as a runtime error. Prefer tracking adapted implementation in its source owners and existing repository docs. No tool regenerates checksums automatically to conceal changes.

Read-only source inventory:

```bash
python3 scripts/inspect_dbvc.py --repo /absolute/path/to/dbvc --output /private/path/outside-the-repository/dbvc-discovery.json
```

Choose a new output filename outside the actual Git worktree. This script reads selected source files and Git branch/HEAD/status. It does not fetch, checkout, stash, reset, install, call WordPress or access the network. The report includes source hints and local paths; keep it private unless reviewed for sharing. It is evidence for discovery, not proof of behavior.

The SQL template is a review draft only. There is deliberately no automatic copy/migrate/enroll/deploy script. Integration requires adapting source to current DBVC ownership and instructions.
