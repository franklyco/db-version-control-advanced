# DBVC Connected + Agency Control drop-in

Start with [START-HERE.md](START-HERE.md), then use [KICKOFF-PROMPT.md](KICKOFF-PROMPT.md) in the local DBVC checkout.

The package supports one repository and one DBVC plugin distribution initially, with separate connector and hub runtime roles. It does not introduce a new Git repository, independent WordPress plugin, frontend framework, or external queue service.

The scaffold is a reviewed starting structure for a local coding agent, not a finished synchronization product. Templates are inert `.stub` files. The [implementation guide](IMPLEMENTATION-GUIDE.md) separates delivered starter logic from future integration and runtime evidence.

Package manifest: [package.json](package.json). Proposed destination mapping: [scaffold/overlay-manifest.json](scaffold/overlay-manifest.json). Scope and state: [tracking/SESSION-HANDOFF.md](tracking/SESSION-HANDOFF.md).
