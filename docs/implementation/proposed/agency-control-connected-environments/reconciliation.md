# Agency Control reconciliation map

Status: **M0 input, not implementation evidence.** The source handoff reviewed historical DBVC evidence. This map identifies where a local agent must verify compatibility before adopting any portion of it.

| Needed capability | Current DBVC connection | Treatment | Owner | M0 verification needed |
|---|---|---|---|---|
| Portable post/term identity | `vf_object_uid`, import identity matching, core sync services | Reuse contract | DBVC core | UID collision, subtype, create/update, registry/meta disagreement, and destination resolution behavior |
| Review, proposal, backup, and recovery concepts | Proposal Review and core import services | Adapt | DBVC core | Public service boundary, side effects, partial-write behavior, and receipt semantics |
| Bricks package transport and connection primitives | Bricks packages, connected sites/onboarding, command queue, Phase 19 evidence | Adapt | Bricks add-on | Current role values, enrollment/clone handling, command validators, retries, receipts, and uncompleted live drill scope |
| Bricks drift and protected variation | Portability manager, drift-manager proposal, protected variants | Adapt | Bricks add-on | Individual-object boundaries inside shared options, dependency coverage, canonicalization semantics, and visibility-helper side effects |
| Content packet workflow | Cross-site entity packet proposal and existing Proposal Review intake | Adapt later | DBVC core | Packet schema, media/reference behavior, identity mapping, and explicit review/apply gates |
| Visual Editor history | Change journal and completed mutation pipeline | Optional adaptation | Visual Editor add-on | Exact completion boundary and whether a journal record can enrich, but never replace, settled snapshot capture |
| Domain observer, dirty-object store, reconciliation, outbox | No confirmed current generic connected-environments module | Build new | Optional DBVC connection module | Add-on registration, PHP/DTO/error conventions, storage/migration helpers, scheduling, and capability-documentation ownership |
| Agency/client registry, subscriptions, event projection | No confirmed DBVC responsibility | Build new | Separate Agency Control hub | Hub hosting, retention, roles, explicit membership, and protocol compatibility |
| Automatic apply, conflict resolution, fleet rollout | Existing apply/rollback capabilities are domain-specific | Defer | DBVC + hub | M4 prepare contract, M5 concurrency/recovery proof, and explicit authorization |

## Compatibility rules

1. A matching class or similarly named hook is not evidence that it satisfies the proposed contract.
2. Existing transport must be adapted behind a compatibility seam; legacy mothership/client behavior remains intact.
3. `dbvc_after_option_update` may be export-gated. It is a possible invalidation hint, not the sole reporter trigger.
4. Existing canonicalizers must be tested against meaningful ordering, missing/null/empty distinctions, and localized ID/URL shapes before cross-environment hashes use them.
5. Incoming numeric IDs, slugs, or object names are not portable identity or permission. Resolve destination identity before any future writer is even prepared.
6. Visual Editor journaling may enrich actor/context information, but external edits and partial batch failures require independent snapshot/reconciliation evidence.
7. A hub receipt, a delivery acknowledgement, a destination prepare result, an apply result, and verified persisted state are separate facts.

## Required M0 decisions

- Select the current implementation base only after recording its branch, HEAD, working-tree boundary, and active LocalWP plugin/runtime provenance.
- Decide the first supported Bricks storage shapes from the installed Bricks version, rather than assuming the source package's examples match.
- Freeze one observation schema/profile version for the initial class, variable, and `service` fixtures.
- Define whether source package transport primitives can be wrapped without changing existing connected-site behavior.
- Confirm the hub's data-retention and permission boundary before any metadata leaves a client environment.

## Explicit deferrals

Do not create a general remote executor, broad queue, or new importer during M0. Do not activate an optional module, schedule workers, enroll sites, create credentials, send network traffic, or apply content while this reconciliation remains incomplete.

## Reconciliation record template

For each adopted surface, record:

| Field | Record |
|---|---|
| Source-package claim | Exact archive file/section and historical revision, if supplied |
| Current local evidence | File, symbol, branch/HEAD, test/runtime observation, and date |
| Decision | Reuse directly, adapt, build new, defer, or reject |
| Contract gap | Identity, lifecycle, permissions, null semantics, failure behavior, or version difference |
| Validation | Smallest test that could reject the decision |
| Authority | Documentation only, local code, disposable fixture, or separately approved runtime/write scope |
