# Experience: understandable change management

Retained experience principles from the earlier What Would Disney Do exploration: make routine coordination disappear, keep technical mechanisms backstage, and prove one complete operator scenario. This package does not depend on that skill being installed locally. No themed UI is required.

## Three views

| View | Questions answered | First controls |
|---|---|---|
| Fleet | Which adopted framework objects changed? Which versions lag? Which overrides are intentional? | Client, definition, version, state and freshness filters |
| Client | What changed on production/staging? What is waiting? Which side changed independently? | Environment pair, incoming/outgoing/conflict tabs, compare |
| Object | Where does it come from? Who uses it? What would the change affect? | Lineage, known consumers, override decision and history |

States must distinguish `behind_version`, `local_drift`, `approved_override`, `conflict`, `unknown`, `offline` and `unsupported`. A total of zero pending reports is not proof of clean state; show last scan, cursor completeness and coverage.

## First scene

The operator changes the production button class and a service page. The hub groups the resulting technical writes into two meaningful items. The class shows “Changed on client production; differs from adopted framework version” with known affected instances. The service appears under that client's environment comparison. Neither becomes a framework release automatically.

An offline staging site says “Waiting for staging to reconnect” with last observation time, then receives its pending report when running. The operator sees “Compare” first. M2 does not display a functioning Publish button; later milestones enable actions based on actual target capability.

## Visible simplicity and required work

| Visible result | Required mechanism |
|---|---|
| One change card | Debounce/coalescing plus persisted canonical snapshot |
| Correct recipient | Authenticated membership and explicit subscriptions |
| “Intentional override” | Approved exact hash, version and rationale |
| “Also affects these pages” | Verified dependency edges plus coverage disclaimer |
| “Ready for review” | Complete compatible target inventory; no implied apply permission |
| “Applied and verified” | Persisted write receipt plus independent verification |
| “Undo this release” | Before image, current-state check and tested compensation |

Provide keyboard access, understandable labels, focus restoration and bounded tables. Follow current module UI conventions when extending DBVC. Use semantic classes/CSS tokens for the new hub. Do not impose a new sitewide styling system on existing DBVC screens during this project.

## Success measures

Pilot thresholds: all authored scenario changes classified correctly; zero cross-client deliveries; duplicate replay gives one logical event; reporter causes zero content changes; ordered-list changes are detected; offline sites recover without false clean state. Record end-to-end reporting latency and save-request overhead before promising a number. A proposed performance budget can be selected after one real baseline; no measurements are invented here.

Future ambitious possibility: a verified object-impact view that lets the agency rehearse one framework release across a representative canary set, then approve staged deployment with exceptions preserved. Dependency coverage and recovery proof are prerequisites, not decorative badges.
