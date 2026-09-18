# Copy/paste kickoff prompt

Act as the lead engineer implementing DBVC Connected Environments and Agency Control in this existing DBVC repository.

Read `docs/dropins/dbvc-connected-agency/START-HERE.md` and `IMPLEMENTATION-GUIDE.md`, after the repository's own AGENTS.md, documentation entrypoints and relevant module instructions. This package supersedes the earlier separate-hub-repository plan. Keep the work inside this repository: an optional connector add-on on client environments, an independently enabled Agency Control hub add-on on a designated studio installation, and a small shared contract layer. Keep Visual Editor optional and reuse DBVC's identity, diff, import/export, application and recovery owners.

First inspect the actual branch, HEAD, dirty state, add-on bootstrap, capability inventory, supported PHP/runtime versions and active LocalWP plugin path. Preserve all existing work. The package's pinned GitHub evidence is historical, not permission to reset or switch branches. If partial work from the earlier architecture exists, reconcile and reuse it. Confirm the updater/distribution origin without changing it as unrelated cleanup.

Create a concise reuse/adapt/new/defer map tied to current source. Then implement M0 and the first coherent M1 reporting slice: independently gated module loading, verified Bricks class/variable observation, cheap dirty signals, generation-aware background processing, durable local snapshots/outbox and a minimal read-only inspection surface. Do not stop after writing a plan when local implementation can proceed. Complete useful bounded work if a runtime prerequisite is unavailable and report the exact remaining gate.

The `.php.stub` files are starter templates, not installed behavior. Review `scaffold/overlay-manifest.json`; adapt each destination to current code and remove `.stub` only when integrating it. Do not run the package from docs, copy everything into runtime, or create another Composer/JS project. Fail-closed Runtime placeholders must be replaced with actual implemented wiring before any capability is marked active.

Required boundaries: both modules off add no transport/scans/routes/jobs/tables/assets; connector-only loads no hub administration; hub-only requires neither connector activation nor Bricks/Visual Editor. No network on save. Do not use export-gated hooks as the only detector. Preserve ordered collections and distinguish missing/null/empty states. Use portable identity and clone-safe enrollment; exclude credentials, environment identity, queues and cursors from synchronization. Keep client environment baselines separate from framework versions and overrides.

The later M2 acceptance scenario is: A-production changes a subscribed framework class and a service post; the class enters studio review and A-staging, the service only A-staging, B receives neither, and offline A-staging catches up. Sender claims do not set membership/recipients or prove an apply origin. Reporting never promotes a framework version or applies content automatically.

Work autonomously on reversible local changes and disposable local fixtures within this scope. Do not push/deploy, enroll live sites, or change actual production content. Ask only for information that blocks meaningful progress. Avoid unrelated refactors and preserve existing manual DBVC behavior.

Run focused tests plus the repository's required documentation gates. Do not treat offline Python examples as PHP/WordPress runtime evidence. Record actual source/runtime paths and limitations. Update existing canonical implementation/capability docs according to repository guidance and maintain a concise session handoff with completed work, exact verification results and the next bounded task.
