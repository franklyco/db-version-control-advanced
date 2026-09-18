# Lifecycle, cloning and isolation

## Activation versus enablement

Installing/upgrading DBVC must not automatically enroll a site or enable either role. A module gate can be off while the plugin remains active. Module tables are created/upgraded through an explicit, capability-checked lifecycle operation or the existing approved upgrade runner; ordinary frontend requests perform no DDL.

On enable: validate role prerequisites and compatibility, perform required schema migration under a lock, verify storage, then register services. On upgrade: check schema version once through the current lifecycle system; stage resumable migrations when needed. On a failed migration: hold that module while leaving unaffected manual DBVC functionality usable where possible.

On disable: stop creating new jobs, unregister routes/listeners, cancel scheduled ticks and let in-flight work exit at bounded checkpoints. Preserve stored observations for deliberate retention handling. Re-enable must reconcile edits made while disabled and show unknown/stale state until coverage is restored.

On emergency disable: return before heavy module loading and prohibit worker/network execution even if an old scheduled event fires. Cleanup of old schedules happens through an authorized lifecycle path, not a database write on every public request. Do not delete data merely because a feature flag becomes false.

On uninstall: use DBVC's plugin-level uninstall owner. Default to retaining management records unless an authorized explicit purge policy says otherwise. Deleting the connector must not delete Bricks objects or a framework definition at the hub. Future product extraction transfers store ownership without having the old uninstaller remove it.

## Clone and transfer exclusions

Exclude environment enrollment IDs/epochs, secrets, tokens, service principals, delivery cursors, queues, locks, retry counters and hub tenant state from DBVC content export/import and configuration portability. Review generic option providers, allowlists, full package exporters and future backup restore flows.

A full-site cloning tool can still copy every database row. Exclusions in DBVC alone cannot prevent that. Before the clone makes a network request, require environment re-enrollment or a clone-safe disconnected mode. Bind enrollment to an installation fingerprint stored outside the cloned content database where practical. URL mismatch and simultaneous identity use should trigger a hold, but neither is sufficient proof by itself. An identical database/filesystem/URL clone may be indistinguishable until explicitly re-enrolled; document this limitation.

Same-client staging clone: preserve intended content lineage and object UIDs; assign a new environment ID, credentials and epoch. Blueprint for a new client: create a new client binding and explicitly map approved framework subscriptions. Restoring an old backup requires epoch/counter reconciliation before resuming sends. Never reset a counter under an existing accepted epoch.

## Capability boundaries

Separate view-client, view-fleet, enroll/revoke, manage-subscriptions, approve-framework-definition, prepare-transfer and execute-transfer permissions. The first implementation may map these to a restricted administrator workflow, but the internal checks must remain distinct. Client site editors do not gain hub-wide administrative rights by editing content.

Authentication identifies an enrolled environment; routing derives its allowed client/agency scope. Every inbox read and ACK rechecks recipient ownership and current enrollment status. Cursor values and guessed event IDs are not authorization. Revoke service credentials and pending execution authority together; audit the management action.

## Sensitive transport

Use WordPress HTTP APIs with validated hub origins, bounded redirects and body limits. Hub URL configuration is administrator-controlled. Future payload/media transfers require explicit authorized origin/path/checksum policies; do not permit arbitrary URLs or archive paths from a sender. Secrets are redactable diagnostic fields, not ordinary exportable options.

## Single-site pilot and multisite

The initial runtime target is WordPress single-site. Detect multisite and fail closed for unsupported network enrollment/storage semantics. Supporting multisite later requires deciding site-versus-network identity, table prefix, capabilities, scheduler ownership and deletion behavior. Do not accidentally share one enrollment across blogs because the network activates the plugin.
