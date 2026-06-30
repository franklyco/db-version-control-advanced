# Bricks Add-on User-Facing Language Audit

Date: 2026-06-25
Status: Review draft; no UI/code implementation yet.
Scope: Current Bricks add-on admin UI, Configure field labels/help, connected-site actions, protected-item wording, proposal/package wording, and standalone Bricks Settings Portability workspace.

## 1) Goal

Make administrator-facing Bricks addon language easier for non-technical users without changing data contracts, REST payload keys, option names, status enums, or stored values.

The main pattern is:

- Keep exact technical identifiers in advanced/details/raw JSON areas where they help support/debugging.
- Use plain labels for primary navigation, buttons, table headers, notices, and field help.
- When a technical value must remain visible, pair it with a plain label, for example `Live (stable)` or `This site's ID (site_uid)`.

## 2) Audited Surfaces


| Surface                                           | Current source                                                                                                     | Priority | Notes                                                                                                |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | -------: | ---------------------------------------------------------------------------------------------------- |
| Bricks submenu title, intro, first-time checklist | `addons/bricks/bricks-addon.php:1035`, `:1081`, `:1118`                                                            |     High | First impression still says "role-aware", "operators", "drift", "Entity", "artifact".                |
| Main Bricks tabs and panels                       | `addons/bricks/bricks-addon.php:1144`, `:1160`, `:1178`, `:1241`, `:1276`, `:1297`, `:1323`, `:1425`               |     High | Navigation should describe jobs users understand.                                                    |
| Configure field labels/help/options               | `addons/bricks/bricks-addon.php:359`, `:454`, `:536`                                                               |     High | Most labels expose implementation terms and raw enum values.                                         |
| Differences/protected/proposals/packages panels   | `addons/bricks/bricks-addon.php:1178-1423`                                                                         |     High | Core workflow should avoid "artifact", "UID", "drift", "mothership", "variant".                      |
| Embedded admin JS messages/actions                | `addons/bricks/bricks-addon.php:1536-1773`                                                                         |     High | Success, error, confirm, and dynamic table copy includes several technical phrases.                  |
| Bricks Settings Portability page                  | `addons/bricks/portability/class-dbvc-bricks-portability.php:74-280`                                               |     High | "Portability", "drift", "domain", "object", and "approved action" are abstract.                      |
| Portability dynamic labels                        | `addons/bricks/portability/assets/bricks-portability.js:73-159`, `:336-538`, `:855-920`, `:995-1327`, `:1582-1922` |     High | User-facing status/action labels are centralized enough for a clean pass.                            |
| Portability registry labels                       | `addons/bricks/portability/class-dbvc-bricks-portability-registry.php:12-186`                                      |   Medium | Domain labels are mostly understandable, but category/metadata labels need friendlier display names. |
| REST/API error messages                           | `addons/bricks/*.php`, `addons/bricks/portability/*.php`                                                           |   Medium | Keep developer detail in`code`; make `message` clearer where it reaches UI notices.                  |

## 3) Plain-Language Glossary


| Current term                           | Proposed user-facing term             | Updated version                     | Notes for updated version                                                                                                                                                            | Use this where                                                     |
| -------------------------------------- | ------------------------------------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------ |
| Mothership                             | Main Site                             | Mothership / Main Site (Mothership) | Use long version sparingly and short version for most labels, buttons, tags, etc.                                                                                                    | Primary UI, setup, packages, connection tests.                     |
| Client                                 | Client Site                           |                                     |                                                                                                                                                                                      | Setup, role descriptions, connected-site flows.                    |
| Role                                   | Site Type                             |                                     |                                                                                                                                                                                      | Configure fields and overview.                                     |
| Artifact                               | Item, Bricks Item                     | Item, Bricks Artifact Item          | Use shorter version in places where the longer version was already used in a parent element (example: use "Item" for table headers if "Bricks Artifact Item" is the UI panel's title | Tables, details, protected changes, proposals.                     |
| Entity                                 | Template Entity                      | Template Entity                    |                                                                                                                                                                                      | Filters and policy labels when referring to`bricks_template`.      |
| Option                                 | Bricks Setting                        |                                     |                                                                                                                                                                                      | Filters and policy labels when referring to option-backed records. |
| Artifact UID / Site UID                | Item / Site                           | Item UID / Site UID                 |                                                                                                                                                                                      | Primary labels. Keep raw`UID` only in advanced/detail text.        |
| Drift                                  | Difference / Changed                  |                                     |                                                                                                                                                                                      | Buttons, statuses, summaries.                                      |
| Diff                                   | Comparison / Difference Details       |                                     |                                                                                                                                                                                      | Primary labels; "diff" can stay in developer/raw details.          |
| Golden                                 | Approved Package / Main Site Version  |                                     |                                                                                                                                                                                      | User-facing details.                                               |
| Package                                | Package / Update Package              |                                     |                                                                                                                                                                                      | Keep "Package" but explain as a saved set of Bricks updates.       |
| Channel                                | Release Stage                         |                                     |                                                                                                                                                                                      | Package filters and help text.                                     |
| Canary                                 | Test                                  |                                     |                                                                                                                                                                                      | Show as`Test (canary)` when stored value is visible.               |
| Beta                                   | Pilot                                 | Pilot or Limited Release            |                                                                                                                                                                                      | Show as`Pilot (beta)`.                                             |
| Stable                                 | Live                                  | Live or Live Official               |                                                                                                                                                                                      | Show as`Live (stable)`.                                            |
| Proposal                               | Change Request                        |                                     |                                                                                                                                                                                      | Tabs, buttons, table headers.                                      |
| Protected Artifact / Protected Variant | Protected Local Change                |                                     |                                                                                                                                                                                      | Tabs, buttons, summaries.                                          |
| Linkage                                | Connection                            |                                     |                                                                                                                                                                                      | Connected-sites actions/notices.                                   |
| Intro / Handshake                      | Connection Setup / Confirm Connection |                                     |                                                                                                                                                                                      | Setup and connected-sites actions.                                 |
| Alias / Canonical UID                  | Duplicate Site ID / Main Site ID      |                                     |                                                                                                                                                                                      | Connected-sites conflict copy.                                     |
| Domain                                 | Settings Group                        |                                     |                                                                                                                                                                                      | Portability workspace.                                             |
| Object                                 | Item                                  |                                     |                                                                                                                                                                                      | Portability tables.                                                |
| Payload / Raw Compare                  | Detailed Data / Advanced Details      |                                     |                                                                                                                                                                                      | Detail drawers and raw JSON views.                                 |
| Read-only Mode                         | Review-Only Mode                      |                                     |                                                                                                                                                                                      | Settings and notices.                                              |
| Dry-run                                | Preview Only                          |                                     |                                                                                                                                                                                      | Apply actions.                                                     |
| Restore Point / Rollback               | Backup / Restore Backup               |                                     |                                                                                                                                                                                      | Apply and history UI.                                              |

## 4) Main Bricks Admin Mapping


| Area                         | Current text                                                                            | Proposed text                                                                          | Source                           | Priority |
| ---------------------------- | --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | -------------------------------- | -------: |
| Page title                   | DBVC Bricks Add-on                                                                      | Keep existing                                                                          | `bricks-addon.php:1043`, `:1082` |     High |
| Page intro                   | Role-aware Bricks controls and status for this site.                                    | Manage how this site's Bricks templates and settings sync with the main site.          | `bricks-addon.php:1083`          |     High |
| Disabled notice              | Bricks add-on is disabled. Enable it in Configure -> Add-ons to access submenu actions. | Bricks Site Sync is turned off. Turn it on in Configure -> Add-ons to use these tools. | `bricks-addon.php:1044`          |     High |
| Read-only notice             | Read-only mode is enabled. Mutating actions are disabled.                               | Review-only mode is on. You can view details, but changes are disabled.                | `bricks-addon.php:1085`          |     High |
| Loading notice               | Loading Bricks data...                                                                  | Loading Bricks sync data...                                                            | `bricks-addon.php:1142`          |   Medium |
| First-time checklist summary | First-Time Checklist - Guided setup for new operators                                   | First-Time Checklist - Guided setup for this site                                      | `bricks-addon.php:1119`          |     High |
| Checklist step               | Confirm site role and policies in Configure -> Add-ons.                                 | Choose whether this site sends or receives Bricks updates.                             | `bricks-addon.php:1124`          |     High |
| Checklist step               | Run a drift scan in Differences.                                                        | Check this site for Bricks differences.                                                | `bricks-addon.php:1125`          |     High |
| Checklist step               | Review Entity and option artifact diffs before actions.                                 | Review each template or setting difference before changing anything.                   | `bricks-addon.php:1126`          |     High |
| Checklist step               | Run Dry Run Apply and create a restore point before real apply.                         | Preview the update and create a backup before applying changes.                        | `bricks-addon.php:1128`          |     High |
| Checklist step               | Review package channel/version details in Packages.                                     | Review the package version and release stage.                                          | `bricks-addon.php:1130`          |   Medium |
| Retry button                 | Retry Last Action                                                                       | Try Again                                                                              | `bricks-addon.php:1141`          |      Low |

## 5) Navigation Mapping


| Current tab/panel   | Proposed label          | Reason                                                     | Source                                    |
| ------------------- | ----------------------- | ---------------------------------------------------------- | ----------------------------------------- |
| Configure           | Settings                | More familiar admin word.                                  | `bricks-addon.php:1161`, `:1823`, `:1890` |
| Overview            | Status                  | Page mainly shows current role/status and diagnostics.     | `bricks-addon.php:1168`, `:1827`          |
| Differences         | Compare Changes         | Clear action-oriented label.                               | `bricks-addon.php:1180`, `:1828`          |
| Protected Artifacts | Protected Local Changes | Explains the user intent.                                  | `bricks-addon.php:1244`, `:1838`          |
| Apply & Restore     | Apply & Backups         | More direct than "restore point/rollback".                 | `bricks-addon.php:1279`                   |
| Proposals           | Change Requests         | Less abstract than "proposal".                             | `bricks-addon.php:1300`                   |
| Packages            | Packages                | Keep, but add package definition in helper text.           | `bricks-addon.php:1325`                   |
| Documentation       | Help                    | Users expect task help here.                               | `bricks-addon.php:1428`                   |
| Governance Overlay  | Rules Summary           | "Governance" is abstract unless the audience is technical. | `bricks-addon.php:1493`                   |

## 6) Configure Field Mapping

### 6.1 Groups


| Current group | Proposed group          | Source                 |
| ------------- | ----------------------- | ---------------------- |
| Activation    | Turn Bricks Sync On     | `bricks-addon.php:362` |
| Connection    | Site Connection         | `bricks-addon.php:366` |
| Golden Source | Update Source           | `bricks-addon.php:382` |
| Policies      | Update Rules            | `bricks-addon.php:408` |
| Operations    | Scan and Apply Settings | `bricks-addon.php:424` |
| Proposals     | Change Requests         | `bricks-addon.php:435` |

### 6.2 High-Priority Fields


| Current field                          | Proposed label                                     | Proposed help/copy direction                                                           |
| -------------------------------------- | -------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Enable Bricks Add-on                   | Enable Bricks Site Sync                            | Turn on the Bricks sync tools and background jobs. Turn off to fully disable them.     |
| Add-on Visibility Mode                 | Where to Show Bricks Sync                          | Show settings in Configure and the Bricks menu, or only in the Bricks menu.            |
| Role                                   | Site Type                                          | `Receiving Site`: receives packages. `Main Site`: publishes and reviews packages.      |
| Site UID                               | Site ID                                            | A stable ID for this site. Use the same value every time this site reconnects.         |
| Mothership Base URL                    | Main Site URL                                      | Enter the WordPress site URL for the main site. Do not include`/wp-json`.              |
| Auth Method                            | Connection Method                                  | Choose how this site authenticates with the main site.                                 |
| API Key ID                             | Username / Key ID                                  | For WordPress Application Passwords, use the integration username.                     |
| API Secret                             | Password / Secret                                  | Paste the matching password or secret.                                                 |
| Credential Rotation Warning (days)     | Credential Age Warning (days)                      | Show a warning when credentials have not been refreshed after this many days.          |
| Strict TLS Verify                      | Verify Secure Connection                           | Keep enabled for live HTTPS sites.                                                     |
| Read-only Mode                         | Review-Only Mode                                   | Allow viewing and reviewing, but block apply, publish, approval, and rollback actions. |
| Source Mode                            | Where Updates Come From                            | Main site, a specific package version, or a local package.                             |
| Channel                                | Release Stage                                      | Test, pilot, or live release stage.                                                    |
| Client Publish Force Channel           | Force Sent Packages to Release Stage               | Optional override for packages this receiving site sends to the main site.             |
| Require Stable Force Confirmation      | Require Confirmation for Live Releases             | Ask for explicit confirmation before publishing to`Live (stable)`.                     |
| Intro Packet Auto-send                 | Automatically Start Site Connection                | Try to connect this site when valid main-site credentials are saved.                   |
| Intro Handshake Token                  | Connection Verification Token                      | Last token used to verify this site with the main site.                                |
| Client Registry State                  | Connection Status                                  | Shows whether this site is waiting, connected, rejected, or disabled.                  |
| Connected Sites Registry Source        | How Connected Sites Are Added                      | Add sites manually or detect them from site check-ins.                                 |
| Connected Sites Registry Mode          | Connected Sites List Source                        | Use the saved connected-sites list, with package history as fallback.                  |
| Command Transport Mode                 | How Updates Are Delivered                          | Main site sends updates directly, or receiving sites check for updates.                |
| Command Envelope TTL Hours             | Delivery Expiration (hours)                        | Stop trying to deliver an update after this many hours.                                |
| Default Policy: Entity artifacts       | Default Rule for Templates                         | What to do with Bricks templates unless a specific rule overrides it.                  |
| Default Policy: Option artifacts       | Default Rule for Bricks Settings                   | What to do with Bricks settings unless a specific rule overrides it.                   |
| New Entity Requires Explicit Accept    | Confirm New Templates Before Creating Them         | Require approval before creating new Bricks templates.                                 |
| Block Destructive Deletes              | Block Deletes                                      | Prevent delete operations unless explicitly allowed.                                   |
| Max Diff Payload KB                    | Maximum Compare Detail Size (KB)                   | Limit advanced comparison details to keep the UI responsive.                           |
| Drift Scan Mode                        | Difference Check Mode                              | Check manually or on a schedule.                                                       |
| Drift Scan Interval (minutes)          | Difference Check Interval (minutes)                | How often scheduled difference checks run.                                             |
| Auto-create Restore Point Before Apply | Create Backup Before Applying                      | Make a backup automatically before applying changes.                                   |
| Restore Point Retention                | Backups to Keep                                    | Maximum number of backups to keep.                                                     |
| Apply Batch Size (artifacts)           | Items Updated at a Time                            | Number of Bricks items processed in one apply batch.                                   |
| Dry-run on Apply by Default            | Preview Changes by Default                         | Start apply actions in preview-only mode.                                              |
| Proposals Enabled                      | Enable Change Requests                             | Allow users to send or review change requests.                                         |
| Auto-submit on Divergence              | Automatically Send Change Requests for Differences | Create change requests automatically when differences are found.                       |
| Proposal Require Note                  | Require a Note for Change Requests                 | Require notes before submitting a change request.                                      |
| Proposal Queue Limit                   | Change Requests to Keep                            | Maximum number of saved change requests before cleanup.                                |
| Mothership SLA (hours)                 | Review Due In (hours)                              | Target review window for change requests.                                              |

### 6.3 Option Value Labels


| Raw value               | Proposed display label            |
| ----------------------- | --------------------------------- |
| `mothership`            | Main Site                         |
| `client`                | Receiving Site                    |
| `mothership_api`        | Main Site                         |
| `pinned_version`        | Specific Package                  |
| `local_package`         | Local Package                     |
| `canary`                | Test (canary)                     |
| `beta`                  | Pilot (beta)                      |
| `stable`                | Live (stable)                     |
| `AUTO_ACCEPT`           | Apply automatically               |
| `REQUIRE_MANUAL_ACCEPT` | Ask before applying               |
| `ALWAYS_OVERRIDE`       | Always replace local version      |
| `REQUEST_REVIEW`        | Request review                    |
| `IGNORE`                | Ignore                            |
| `manual`                | Manual                            |
| `scheduled`             | Scheduled                         |
| `PENDING_INTRO`         | Setup needed                      |
| `VERIFIED`              | Connected                         |
| `REJECTED`              | Rejected                          |
| `DISABLED`              | Disabled                          |
| `direct_push`           | Main site sends updates           |
| `client_pull_envelope`  | Receiving sites check for updates |

## 7) Compare Changes Panel Mapping


| Current text                                                               | Proposed text                                                                | Source                                  | Priority |
| -------------------------------------------------------------------------- | ---------------------------------------------------------------------------- | --------------------------------------- | -------: |
| Differences                                                                | Compare Changes                                                              | `bricks-addon.php:1180`                 |     High |
| Run drift scan and review template Entity and option artifact differences. | Compare this site's Bricks templates and settings with the selected package. | `bricks-addon.php:1181`                 |     High |
| Run Drift Scan                                                             | Check for Differences                                                        | `bricks-addon.php:1186`                 |     High |
| Export Review JSON                                                         | Export Review File                                                           | `bricks-addon.php:1187`                 |   Medium |
| Publish Package to Mothership                                              | Send Package to Main Site                                                    | `bricks-addon.php:1189`                 |     High |
| Protected Reason                                                           | Reason for Protecting                                                        | `bricks-addon.php:1195`                 |   Medium |
| Required to mark selected artifact as protected                            | Required before protecting the selected item                                 | `bricks-addon.php:1196`                 |     High |
| Mark Selected Protected                                                    | Protect Selected Item                                                        | `bricks-addon.php:1197`                 |     High |
| Unmark Selected Protected                                                  | Remove Protection                                                            | `bricks-addon.php:1198`                 |     High |
| Artifact Class                                                             | Item Type                                                                    | `bricks-addon.php:1203`                 |     High |
| Entity                                                                     | Template                                                                     | `bricks-addon.php:1206`                 |     High |
| Option                                                                     | Bricks Setting                                                               | `bricks-addon.php:1207`                 |     High |
| CLEAN                                                                      | No differences                                                               | `bricks-addon.php:1212`, `:1221`        |     High |
| DIVERGED                                                                   | Different                                                                    | `bricks-addon.php:1213`, `:1222`        |     High |
| OVERRIDDEN                                                                 | Local version kept                                                           | `bricks-addon.php:1214`, `:1223`        |     High |
| PENDING_REVIEW                                                             | Waiting for review                                                           | `bricks-addon.php:1215`, `:1224`        |     High |
| artifact uid                                                               | item name or ID                                                              | `bricks-addon.php:1218`                 |     High |
| Artifact                                                                   | Item                                                                         | `bricks-addon.php:1229`, dynamic detail |     High |
| Class                                                                      | Type                                                                         | `bricks-addon.php:1229`, dynamic detail |     High |
| Run a drift scan to load differences.                                      | Check for differences to load results.                                       | `bricks-addon.php:1230`                 |     High |
| Detail                                                                     | Selected Item Details                                                        | `bricks-addon.php:1234`                 |   Medium |
| Refresh Raw Compare                                                        | Refresh Details                                                              | `bricks-addon.php:1235`                 |   Medium |
| Select an artifact to inspect details.                                     | Select an item to see details.                                               | `bricks-addon.php:1236`, `:1685`        |     High |
| Raw Compare (masked/ignored rules applied)                                 | Detailed Data Compare (rules applied)                                        | `bricks-addon.php:1685`                 |   Medium |
| Local Hash / Golden Hash                                                   | Current Site Version ID / Package Version ID                                 | `bricks-addon.php:1685`                 |   Medium |
| Local payload / Golden payload                                             | Current site data / Package data                                             | `bricks-addon.php:1685`                 |   Medium |

## 8) Protected Local Changes Mapping


| Current text                                                                | Proposed text                                                                 | Source                           | Priority |
| --------------------------------------------------------------------------- | ----------------------------------------------------------------------------- | -------------------------------- | -------: |
| Protected Artifacts                                                         | Protected Local Changes                                                       | `bricks-addon.php:1244`          |     High |
| Client-managed protected variant records for intentional local divergences. | Keep selected local Bricks items from being overwritten by incoming packages. | `bricks-addon.php:1245`          |     High |
| Refresh Protected Artifacts                                                 | Refresh Protected Items                                                       | `bricks-addon.php:1246`          |     High |
| Add Protected Artifact                                                      | Protect an Item                                                               | `bricks-addon.php:1249`          |     High |
| Artifact UID                                                                | Item ID                                                                       | `bricks-addon.php:1250`, `:1270` |     High |
| Label                                                                       | Display Name                                                                  | `bricks-addon.php:1252`, `:1270` |      Low |
| Artifact Type                                                               | Item Type                                                                     | `bricks-addon.php:1254`          |     High |
| unknown                                                                     | Not sure                                                                      | `bricks-addon.php:1256`          |   Medium |
| option                                                                      | Bricks Setting                                                                | `bricks-addon.php:1257`          |   Medium |
| bricks_template                                                             | Template                                                                      | `bricks-addon.php:1258`          |   Medium |
| Scope                                                                       | Applies To                                                                    | `bricks-addon.php:1260`, `:1270` |   Medium |
| site_local                                                                  | This site only                                                                | `bricks-addon.php:1262`          |     High |
| Reason (required)                                                           | Why protect this item? (required)                                             | `bricks-addon.php:1264`          |     High |
| Save Protected Artifact                                                     | Save Protection                                                               | `bricks-addon.php:1266`          |     High |
| No protected artifacts loaded/found.                                        | No protected items loaded/found.                                              | `bricks-addon.php:1271`, `:1740` |     High |
| Read-only                                                                   | Review-only                                                                   | `bricks-addon.php:1740`          |   Medium |
| Remove protected artifact ...?                                              | Remove protection for ...?                                                    | `bricks-addon.php:1743`          |     High |
| Read-only mode blocks protected variant mutations.                          | Review-only mode is on, so protection changes are disabled.                   | `bricks-addon.php:1742`, `:1743` |     High |

## 9) Apply, Backups, And Change Requests Mapping


| Current text                                                       | Proposed text                                                             | Source                  | Priority |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------- | ----------------------- | -------: |
| Apply & Restore                                                    | Apply & Backups                                                           | `bricks-addon.php:1279` |     High |
| Client-only actions for dry-run/apply and restore workflows.       | Preview, apply, back up, or restore incoming Bricks updates on this site. | `bricks-addon.php:1280` |     High |
| Dry-run                                                            | Preview only                                                              | `bricks-addon.php:1281` |     High |
| Allow destructive operations                                       | Allow deletes/replacements                                                | `bricks-addon.php:1282` |     High |
| Bulk mode (chunked)                                                | Process in smaller groups                                                 | `bricks-addon.php:1283` |   Medium |
| Chunk Size                                                         | Items per group                                                           | `bricks-addon.php:1284` |   Medium |
| Dry Run Apply                                                      | Preview Apply                                                             | `bricks-addon.php:1287` |     High |
| Apply Selected                                                     | Apply Selected Changes                                                    | `bricks-addon.php:1288` |     High |
| Create Restore Point                                               | Create Backup                                                             | `bricks-addon.php:1289` |     High |
| Rollback Restore ID                                                | Backup ID                                                                 | `bricks-addon.php:1291` |     High |
| Run Rollback                                                       | Restore Backup                                                            | `bricks-addon.php:1293` |     High |
| Proposals                                                          | Change Requests                                                           | `bricks-addon.php:1300` |     High |
| Submit and review proposal state transitions for Bricks artifacts. | Send or review requests to keep or approve a Bricks change.               | `bricks-addon.php:1301` |     High |
| Status Filter                                                      | Show                                                                      | `bricks-addon.php:1302` |      Low |
| RECEIVED                                                           | New                                                                       | `bricks-addon.php:1305` |     High |
| APPROVED                                                           | Approved                                                                  | `bricks-addon.php:1305` |   Medium |
| REJECTED                                                           | Rejected                                                                  | `bricks-addon.php:1305` |   Medium |
| NEEDS_CHANGES                                                      | Needs changes                                                             | `bricks-addon.php:1305` |     High |
| Submit Proposal (Selected Diff)                                    | Request Review for Selected Difference                                    | `bricks-addon.php:1308` |     High |
| Review Notes                                                       | Notes for reviewer                                                        | `bricks-addon.php:1309` |   Medium |
| Proposal                                                           | Request                                                                   | `bricks-addon.php:1317` |     High |
| Artifact                                                           | Item                                                                      | `bricks-addon.php:1317` |     High |
| Select a diff artifact before submitting a proposal.               | Select a difference before requesting review.                             | `bricks-addon.php:1745` |     High |

## 10) Packages And Connected Sites Mapping


| Current text                                                                                          | Proposed text                                                                           | Source                           | Priority |
| ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | -------------------------------- | -------: |
| Mothership package listing and inspection controls.                                                   | Review saved Bricks update packages and choose which connected sites can receive them.  | `bricks-addon.php:1326`          |     High |
| Client view is filtered to packages eligible for this site UID:                                       | Showing packages allowed for this site ID:                                              | `bricks-addon.php:1349`          |     High |
| Stable Force Channel Active                                                                           | Live Release Override Active                                                            | `bricks-addon.php:1351`          |   Medium |
| Channel definitions                                                                                   | Release stages                                                                          | `bricks-addon.php:1357`          |     High |
| canary = first validation group, beta = wider pre-release rollout, stable = production-ready release. | Test = first review group, Pilot = wider review group, Live = production-ready release. | `bricks-addon.php:1358`          |     High |
| Channel                                                                                               | Release Stage                                                                           | `bricks-addon.php:1361`, `:1408` |     High |
| Create Package from Current Site                                                                      | Create Package from This Site                                                           | `bricks-addon.php:1364`          |   Medium |
| Connected Sites Targeting                                                                             | Choose Receiving Sites                                                                  | `bricks-addon.php:1365`          |     High |
| Target Mode                                                                                           | Send To                                                                                 | `bricks-addon.php:1366`          |     High |
| all / selected                                                                                        | All connected sites / Selected sites                                                    | `bricks-addon.php:1367`          |     High |
| Refresh Connected Sites                                                                               | Refresh Site List                                                                       | `bricks-addon.php:1368`          |   Medium |
| Save Allowlist                                                                                        | Save Selected Sites                                                                     | `bricks-addon.php:1369`          |     High |
| site uid or label                                                                                     | site name or ID                                                                         | `bricks-addon.php:1371`          |     High |
| Allow                                                                                                 | Can Receive                                                                             | `bricks-addon.php:1379`          |     High |
| Site UID                                                                                              | Site ID                                                                                 | `bricks-addon.php:1379`, `:1388` |     High |
| Label                                                                                                 | Site Name                                                                               | `bricks-addon.php:1379`          |   Medium |
| Onboarding                                                                                            | Connection                                                                              | `bricks-addon.php:1379`          |     High |
| Last Seen                                                                                             | Last Contact                                                                            | `bricks-addon.php:1379`          |     High |
| Protected Variant Visibility                                                                          | Protected Local Changes Across Sites                                                    | `bricks-addon.php:1383`          |     High |
| Review client-protected Bricks artifacts from the latest package metadata.                            | See which connected sites have Bricks items marked as protected.                        | `bricks-addon.php:1384`          |     High |
| Protected                                                                                             | Protected Items                                                                         | `bricks-addon.php:1388`          |   Medium |
| Types                                                                                                 | Item Types                                                                              | `bricks-addon.php:1388`          |   Medium |
| Freshness                                                                                             | Last Reported                                                                           | `bricks-addon.php:1388`          |   Medium |
| Run Publish Preflight                                                                                 | Check Before Publishing                                                                 | `bricks-addon.php:1393`          |     High |
| Test Mothership Connection                                                                            | Test Main Site Connection                                                               | `bricks-addon.php:1394`          |     High |
| Confirm forced stable publish                                                                         | Confirm live release                                                                    | `bricks-addon.php:1396`          |     High |
| Pull Latest Allowed + Dry Run                                                                         | Preview Latest Allowed Package                                                          | `bricks-addon.php:1397`          |     High |
| Publish Package to Mothership                                                                         | Send Package to Main Site                                                               | `bricks-addon.php:1398`          |     High |
| Promote Channel                                                                                       | New Release Stage                                                                       | `bricks-addon.php:1401`          |   Medium |
| Promote Selected Package                                                                              | Move Selected Package to Release Stage                                                  | `bricks-addon.php:1403`          |   Medium |
| Revoke Selected Package                                                                               | Stop Selected Package                                                                   | `bricks-addon.php:1404`          |     High |
| Publish Selected Package (Targeting Applied)                                                          | Publish Selected Package to Chosen Sites                                                | `bricks-addon.php:1406`          |     High |
| Audience                                                                                              | Recipients                                                                              | `bricks-addon.php:1408`          |     High |
| Mothership Publish Runs                                                                               | Packages Sent to Main Site                                                              | `bricks-addon.php:1413`          |     High |
| Recent package publish attempts from this client to the configured mothership.                        | Recent attempts to send packages from this site to the main site.                       | `bricks-addon.php:1414`          |     High |
| Targeting                                                                                             | Recipients                                                                              | `bricks-addon.php:1418`          |   Medium |
| Verify                                                                                                | Check                                                                                   | `bricks-addon.php:1418`          |      Low |
| Receipt                                                                                               | Record                                                                                  | `bricks-addon.php:1418`          |      Low |

### 10.1 Connected-Site Dynamic Actions


| Current text                                                 | Proposed text                                                | Source                  | Priority |
| ------------------------------------------------------------ | ------------------------------------------------------------ | ----------------------- | -------: |
| Open Client Bricks Tab                                       | Open Receiving Site                                          | `bricks-addon.php:1704` |     High |
| Confirm Handshake                                            | Confirm Connection                                           | `bricks-addon.php:1704` |     High |
| Reset Linkage                                                | Reset Connection                                             | `bricks-addon.php:1704` |     High |
| Forget Linkage                                               | Hide / Forget Connection                                     | `bricks-addon.php:1704` |     High |
| Auto-Merge Alias                                             | Auto-Merge Duplicate Site                                    | `bricks-addon.php:1704` |   Medium |
| Merge/Deactivate Alias                                       | Merge Duplicate Site                                         | `bricks-addon.php:1704` |     High |
| Conflict: duplicate URL. Canonical UID: ...                  | Duplicate site URL. Main site ID: ...                        | `bricks-addon.php:1704` |     High |
| Known aliases                                                | Alternate site IDs                                           | `bricks-addon.php:1704` |     High |
| known alias UID                                              | alternate site ID                                            | `bricks-addon.php:1704` |     High |
| Map Known Alias                                              | Add Alternate Site ID                                        | `bricks-addon.php:1704` |     High |
| Accept intro handshake ... and mark onboarding VERIFIED?     | Confirm this site connection and mark it connected?          | `bricks-addon.php:1773` |     High |
| Reset linkage ... and set onboarding state to PENDING_INTRO? | Reset this connection and require setup again?               | `bricks-addon.php:1773` |     High |
| Forget linkage ... fresh intro handshake is verified.        | Hide this connection until the site reconnects successfully? | `bricks-addon.php:1773` |     High |
| Type the site UID to confirm:                                | Type the site ID to confirm:                                 | `bricks-addon.php:1773` |     High |
| Confirmation UID mismatch. Forget Linkage cancelled.         | Site ID did not match. The connection was not hidden.        | `bricks-addon.php:1773` |     High |

## 11) Portability Workspace Mapping


| Current text                                                                                           | Proposed text                                                                                                            | Source                                                                                | Priority |
| ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------- | -------: |
| Bricks Settings Portability                                                                            | Move Bricks Settings Between Sites                                                                                       | `class-dbvc-bricks-portability.php:35`                                                |     High |
| -> Settings Portability                                                                                | -> Move Bricks Settings                                                                                                  | `class-dbvc-bricks-portability.php:36`                                                |     High |
| DBVC Bricks Settings Portability                                                                       | Move Bricks Settings Between Sites                                                                                       | `class-dbvc-bricks-portability.php:81`                                                |     High |
| Dedicated export, compare, apply, backup, and rollback workspace for portable Bricks settings domains. | Export Bricks settings from one site, compare them with this site, choose what to apply, and restore a backup if needed. | `class-dbvc-bricks-portability.php:82`                                                |     High |
| portability actions                                                                                    | move-settings actions                                                                                                    | `class-dbvc-bricks-portability.php:85`                                                |   Medium |
| Portability & Drift Manager                                                                            | Move and Compare Bricks Settings                                                                                         | `class-dbvc-bricks-portability.php:104`                                               |     High |
| Export portable Bricks settings packages... normalized drift... pre-apply backups.                     | Export Bricks settings, upload them to another site, compare differences, apply selected changes, and back up first.     | `class-dbvc-bricks-portability.php:105`                                               |     High |
| Bricks portability sections                                                                            | Bricks settings move sections                                                                                            | `class-dbvc-bricks-portability.php:108`                                               |   Medium |
| Workspace                                                                                              | Move & Compare                                                                                                           | `class-dbvc-bricks-portability.php:109`                                               |   Medium |
| History & Rollback                                                                                     | History & Restore                                                                                                        | `class-dbvc-bricks-portability.php:110`                                               |     High |
| Export Package                                                                                         | Create Export Package                                                                                                    | `class-dbvc-bricks-portability.php:116`                                               |   Medium |
| Select portable Bricks domains...                                                                      | Choose the Bricks settings groups to include in the ZIP file.                                                            | `class-dbvc-bricks-portability.php:117`                                               |     High |
| Loading supported domains...                                                                           | Loading supported settings groups...                                                                                     | `class-dbvc-bricks-portability.php:118`                                               |     High |
| Export Selected Domains                                                                                | Export Selected Settings Groups                                                                                          | `class-dbvc-bricks-portability.php:121`                                               |     High |
| Domains                                                                                                | Settings Groups                                                                                                          | `class-dbvc-bricks-portability.php:123`, `:148`, `:152`, `bricks-portability.js:1588` |     High |
| Import Package                                                                                         | Upload Package                                                                                                           | `class-dbvc-bricks-portability.php:127`                                               |   Medium |
| Upload a Bricks portability package zip, normalize it, and compare it against this site.               | Upload a Bricks settings ZIP and compare it with this site.                                                              | `class-dbvc-bricks-portability.php:128`                                               |     High |
| Upload Package and Compare                                                                             | Upload and Compare                                                                                                       | `class-dbvc-bricks-portability.php:130`                                               |   Medium |
| Review Workbench                                                                                       | Review Changes                                                                                                           | `class-dbvc-bricks-portability.php:138`                                               |     High |
| Import a package to load drift rows.                                                                   | Upload a package to load differences.                                                                                    | `class-dbvc-bricks-portability.php:139`, `bricks-portability.js:1611`                 |     High |
| Incoming Package = ... Current Site = ...                                                              | Package Version = data from the uploaded ZIP. This Site = data currently on this site.                                   | `class-dbvc-bricks-portability.php:140`                                               |     High |
| Refresh Current Site Compare                                                                           | Refresh Comparison                                                                                                       | `class-dbvc-bricks-portability.php:143`, `bricks-portability.js:514`                  |   Medium |
| Apply approved changes... approval timestamps...                                                       | Apply selected changes and record when this package was applied.                                                         | `class-dbvc-bricks-portability.php:145`                                               |   Medium |
| Approved Action                                                                                        | Selected Action                                                                                                          | `class-dbvc-bricks-portability.php:156`, `:181`                                       |     High |
| object label or ID                                                                                     | item name or ID                                                                                                          | `class-dbvc-bricks-portability.php:161`                                               |     High |
| Hide No Drift rows                                                                                     | Hide Unchanged Rows                                                                                                      | `class-dbvc-bricks-portability.php:162`                                               |     High |
| Bulk Action                                                                                            | Set Action for Filtered Rows                                                                                             | `class-dbvc-bricks-portability.php:165`                                               |     High |
| Keep Current Site                                                                                      | Keep This Site's Version                                                                                                 | `class-dbvc-bricks-portability.php:166`, `bricks-portability.js:125`                  |     High |
| Add Incoming Package                                                                                   | Add From Package                                                                                                         | `class-dbvc-bricks-portability.php:166`, `bricks-portability.js:127`                  |     High |
| Replace With Incoming Package                                                                          | Replace With Package Version                                                                                             | `class-dbvc-bricks-portability.php:166`, `bricks-portability.js:128`                  |     High |
| Apply To Filtered Rows                                                                                 | Apply Action to Filtered Rows                                                                                            | `class-dbvc-bricks-portability.php:167`                                               |   Medium |
| incoming-versus-current diff                                                                           | package-versus-this-site comparison                                                                                      | `class-dbvc-bricks-portability.php:169`, `:190`, `bricks-portability.js:1001`         |     High |
| Object                                                                                                 | Item                                                                                                                     | `class-dbvc-bricks-portability.php:181`                                               |     High |
| Object ID                                                                                              | Item ID                                                                                                                  | `class-dbvc-bricks-portability.php:181`                                               |     High |
| Match                                                                                                  | Matched By                                                                                                               | `class-dbvc-bricks-portability.php:181`                                               |   Medium |
| Applied / Approved On Current Site                                                                     | Applied to This Site                                                                                                     | `class-dbvc-bricks-portability.php:181`, `bricks-portability.js:916`                  |   Medium |
| Review Action                                                                                          | Choose Action                                                                                                            | `class-dbvc-bricks-portability.php:181`                                               |     High |
| Row Diff                                                                                               | Item Details                                                                                                             | `class-dbvc-bricks-portability.php:189`                                               |     High |
| Close row diff modal                                                                                   | Close item details                                                                                                       | `class-dbvc-bricks-portability.php:191`                                               |   Medium |
| Backups & Rollback                                                                                     | Backups & Restore                                                                                                        | `class-dbvc-bricks-portability.php:201`                                               |     High |
| Options                                                                                                | Settings Changed                                                                                                         | `class-dbvc-bricks-portability.php:202`                                               |   Medium |
| Recent Jobs                                                                                            | Recent Activity                                                                                                          | `class-dbvc-bricks-portability.php:205`                                               |   Medium |
| I confirm the approved incoming package changes should be applied and backed up first.                 | I confirm these package changes should be applied to this site after creating a backup.                                  | `class-dbvc-bricks-portability.php:273`                                               |     High |
| Save Decisions as Draft                                                                                | Save Review Draft                                                                                                        | `class-dbvc-bricks-portability.php:276`                                               |   Medium |
| Apply Approved Changes                                                                                 | Apply Selected Changes                                                                                                   | `class-dbvc-bricks-portability.php:277`                                               |     High |

### 11.1 Portability Status And Dynamic Text


| Current text                                                          | Proposed text                                                     | Source                                        |
| --------------------------------------------------------------------- | ----------------------------------------------------------------- | --------------------------------------------- |
| No Drift                                                              | No Differences                                                    | `bricks-portability.js:75`                    |
| Incoming Package and Current Site are equivalent after normalization. | The package version and this site match after DBVC compares them. | `bricks-portability.js:76`                    |
| Incoming Only                                                         | Only in Package                                                   | `bricks-portability.js:79`                    |
| Current Site Only                                                     | Only on This Site                                                 | `bricks-portability.js:83`                    |
| Same Name, Different ID                                               | Same Name, Needs Review                                           | `bricks-portability.js:87`                    |
| Same ID, Different Name                                               | Same Item, Different Name                                         | `bricks-portability.js:91`                    |
| Changed Values                                                        | Changed Settings                                                  | `bricks-portability.js:95`                    |
| Changed Structure                                                     | Changed Settings Structure                                        | `bricks-portability.js:99`                    |
| Incoming Adds Properties                                              | Package Adds Details                                              | `bricks-portability.js:103`                   |
| Current Site Has Extra Properties                                     | This Site Has Extra Details                                       | `bricks-portability.js:107`                   |
| Singleton                                                             | Single site-wide setting                                          | `bricks-portability.js:113`                   |
| Matched by Slug                                                       | Matched by URL name                                               | `bricks-portability.js:116`                   |
| Matched by Token / Selector / Option Name                             | Matched by technical key                                          | `bricks-portability.js:117-119`               |
| Has Drift                                                             | Has Differences                                                   | `bricks-portability.js:137`                   |
| Current Only                                                          | This Site Only                                                    | `bricks-portability.js:145`                   |
| No supported portability domains found.                               | No Bricks settings groups are available to export.                | `bricks-portability.js:341`                   |
| high risk                                                             | review carefully                                                  | `bricks-portability.js:348`                   |
| verify                                                                | needs check                                                       | `bricks-portability.js:349`                   |
| Date Applied & Approved on Current Site                               | Applied to This Site                                              | `bricks-portability.js:393`, `:486`, `:498`   |
| Current Site Compare: Fresh                                           | Comparison is up to date                                          | `bricks-portability.js:523`                   |
| Current Site Compare: Stale                                           | Comparison is out of date                                         | `bricks-portability.js:523`                   |
| Changed domains since compare                                         | Settings groups changed since comparison                          | `bricks-portability.js:529`                   |
| No filtered review rows to display.                                   | No matching review items to display.                              | `bricks-portability.js:825`                   |
| Not yet recorded                                                      | Not applied yet                                                   | `bricks-portability.js:843`                   |
| Untitled object                                                       | Untitled item                                                     | `bricks-portability.js:875`, `:1017`, `:1023` |
| Dependency Analysis                                                   | Linked Items Check                                                | `bricks-portability.js:1326`                  |
| Missing on Current Site but supplied by Incoming Package              | Missing on this site; included in package                         | `bricks-portability.js:1264`                  |
| Missing on both Current Site and Incoming Package                     | Missing from this site and the package                            | `bricks-portability.js:1275`                  |
| Possibly external or non-Bricks managed                               | May come from another plugin or custom code                       | `bricks-portability.js:1279`                  |
| Include These Missing Variables                                       | Also Include These Variables                                      | `bricks-portability.js:1269`                  |
| Include These Missing Categories                                      | Also Include These Categories                                     | `bricks-portability.js:1291`                  |
| Missing class dependencies on Current Site                            | Missing classes on this site                                      | `bricks-portability.js:1311`                  |
| Include These Missing Classes                                         | Also Include These Classes                                        | `bricks-portability.js:1316`                  |
| Select at least one portability domain.                               | Select at least one Bricks settings group.                        | `bricks-portability.js:1735`                  |
| Choose a ZIP package to import.                                       | Choose a Bricks settings ZIP file to upload.                      | `bricks-portability.js:1759`                  |
| Open a review session before refreshing the current-site compare.     | Open a review before refreshing the comparison.                   | `bricks-portability.js:1794`                  |
| Confirm apply before continuing.                                      | Confirm that you want to apply these changes before continuing.   | `bricks-portability.js:1894`                  |
| Rollback this portability backup?                                     | Restore this backup?                                              | `bricks-portability.js:1922`                  |

## 12) Portability Registry Label Tweaks

Keep the Bricks product terms, but simplify labels that expose implementation metadata.


| Current registry label              | Proposed display label        | Source                                                  |
| ----------------------------------- | ----------------------------- | ------------------------------------------------------- |
| Bricks Global Classes Categories    | Global Class Categories       | `class-dbvc-bricks-portability-registry.php:18`         |
| Bricks Global Variable Categories   | Global Variable Categories    | `class-dbvc-bricks-portability-registry.php:20`         |
| Bricks Pseudo Classes               | Pseudo Classes                | `class-dbvc-bricks-portability-registry.php:21`, `:101` |
| Bricks Breakpoints Settings         | Breakpoint Settings           | `class-dbvc-bricks-portability-registry.php:24`, `:146` |
| Bricks Breakpoints Generated Marker | Breakpoint Generated Marker   | `class-dbvc-bricks-portability-registry.php:28`         |
| Bricks Global Classes Changes       | Global Classes Change History | `class-dbvc-bricks-portability-registry.php:31`         |
| Bricks Global Classes Locked        | Locked Global Classes         | `class-dbvc-bricks-portability-registry.php:32`         |
| Bricks Global Classes Timestamp     | Global Classes Updated Time   | `class-dbvc-bricks-portability-registry.php:33`         |
| Bricks Global Classes User          | Global Classes Last Editor    | `class-dbvc-bricks-portability-registry.php:34`         |
| Bricks Global Classes Trash         | Deleted Global Classes        | `class-dbvc-bricks-portability-registry.php:35`         |
| Bricks Font Face Rules              | Generated Font CSS            | `class-dbvc-bricks-portability-registry.php:40`         |
| Bricks Icon Sets                    | Icon Sets                     | `class-dbvc-bricks-portability-registry.php:43`         |
| Bricks Custom Icons                 | Custom Icons                  | `class-dbvc-bricks-portability-registry.php:46`         |
| Bricks Disabled Icon Sets           | Disabled Icon Sets            | `class-dbvc-bricks-portability-registry.php:49`         |

## 13) Keep Technical Text In These Places

Do not translate or soften these unless a separate technical-support pass is approved:

- REST route names, JSON keys, option keys, enum values in request/response bodies.
- Error `code` values such as `dbvc_bricks_client_envelope_secret_missing`.
- Raw JSON/preformatted diagnostics output.
- Exported package manifests and checksums.
- Internal docs, tests, fixtures, and archived implementation history.
- Exact Bricks storage option names when shown as advanced detail.

## 14) Recommended Implementation Order After Approval

1. Add a small display-label helper for shared enums: roles, channels, policy actions, status values, target modes, onboarding states.
2. Update `bricks-addon.php` static labels and embedded JS messages.
3. Update the Configure field metadata/help text while keeping field keys and saved values unchanged.
4. Update portability PHP labels, then centralize JS display strings in `bricks-portability.js`.
5. Keep raw JSON/detail panes technically exact, but add friendlier headings around them.
6. Update tests that assert literal UI strings, especially Bricks phase UI tests.

## 15) Review Questions

1. Should "mothership" become "Main Site" everywhere in primary UI, or should advanced users still see "mothership" beside it, for example `Main Site (mothership)`? Use "Main Site (mothership)" for titles/headings of panels pages and if decendant labels contain or reference "Mothership" then keep it as "Mothership"
2. Should "Package" remain the primary word, or should the UI use "Update Package" in headings/buttons? "Update Package"
3. Should the standalone page name be "Move Bricks Settings Between Sites" or "Bricks Settings Transfer"? Use "Bricks Settings Transfer"
4. Should "Protected Local Changes" be the final approved term for protected artifacts/variants? Yes, but let's also include "Protected Variant Items" where they might be applicable
5. Should the release labels be `Test / Pilot / Live`, or keep `Canary / Beta / Stable` with helper text? Let's use "Test / Pilot / Live" but include Canary / Beta / Stable verbiage whenever the new term is first listed on a page/section/panel - example: Pilot (Beta)
