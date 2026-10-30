# Meta Masking Reference

## Live Proposal Masking

Live proposal masking lets reviewers apply configured post-field, post-meta, and term-meta masking rules directly inside Proposal Review without altering the existing export-time masking pipeline. Matching unresolved fields appear as **Masking candidates**; this count is separate from **Fields need review**, **Meta needs review**, **Media needs review**, and **Resolver conflicts**. Ignoring, auto-accepting, or overriding a candidate removes only that masking work from the pending review totals.

Open the **Tools** pill above the All Entities table to expand the toolbox. The Meta Masking section lives directly inside that panel alongside Resolver Summary, hash sync actions, and duplicate shortcuts.

### Post Field Masking

Admins can extend masking beyond postmeta by checking **Post fields to mask** within the Config → Masking tab. Selected fields (e.g., `post_date`, `post_modified`, `post_author`) appear alongside masked meta in the Tools panel. Decisions against these fields behave exactly like meta masks—ignored fields stop blocking reviews, “auto-accept + suppress” preserves local values during imports, and overrides let reviewers swap in sanitized timestamps or identifiers.

## Tooltip Copy & Links

Each tooltip must include concise help text plus a link to this document (or the published equivalent) so reviewers can dig deeper without cluttering the UI.

### Apply Masking Rules Button

- **Tooltip text:** “Review the configured masking candidates for every entity in this proposal. Completed masking choices leave media resolver counts unchanged. Learn more.”
- **Help link:** `docs/reference/meta-masking.md#live-proposal-masking` (label: “Masking guide”)

### Field-Level Masking Actions

1. **Ignore masked field**
   - Tooltip: “Ignore this masked field so it no longer counts as a Masking candidate, while leaving local content untouched. Ideal for metadata that shouldn’t block applies. Learn more.”
   - Link: `docs/reference/meta-masking.md#ignore-masked-field` (label: “Why ignore?”)
2. **Auto-accept & suppress**
   - Tooltip: “Mark this masked field as accepted and suppress it from future proposals so sensitive values never surface in diffs. Live proposal labels update automatically. Learn more.”
   - Link: `docs/reference/meta-masking.md#auto-accept-and-suppress` (label: “Auto-accept details”)
3. **Override masked value**
   - Tooltip: “Replace this masked field with a reviewer-provided override while keeping an audit trail. Use when a sanitized value must ship with the proposal. Learn more.”
   - Link: `docs/reference/meta-masking.md#override-masked-value` (label: “Override workflow”)

## Behavior Notes

- Export-time masking continues to run exactly as it does today; the new button simply mirrors those rules inside the live review experience.
- After any masking action finishes, the UI re-queries the canonical status counters so **Masking candidates** reflects the updated state. Media resolver counters are independent and do not change because of masking choices.
- Post field selections use the same suppression/override pipeline as meta. Suppressed post fields skip importer writes so local dates/authors remain unchanged, while overrides inject reviewer-provided values when the bundle applies.

## Ignore Masked Field

Use the ignore option when metadata is informational (e.g., analytics UTM arrays) and shouldn’t require reviewer attention per proposal. The field is omitted from review counts but the site retains its existing value.

## Auto-Accept and Suppress

Auto-accept lets reviewers treat masked fields as if they were accepted manually, then suppress those fields from future diffs so sensitive information never reappears. This is best for secrets, feature flags, or other values that should remain hidden yet still follow the accept flow.

## Override Masked Value

Override is designed for cases where masking provides a placeholder but deployment still needs a sanitized replacement value. Reviewers supply the override once; the proposal records that change, and downstream applies inherit the sanitized value without exposing the original data.

## Revert Masked Decisions

If you need to roll back the automated selections stamped by **Apply masking rules**, open the Tools panel and click **Revert masking decisions**. This clears any accept/keep decisions that were created via the masking tool (based on the current mask patterns) and removes the related suppress/override records so the affected fields return to **Masking candidates**. Use this whenever masking rules change or when a proposal requires full reviewer scrutiny again.

## Options Export Redaction

`options.json` is not covered by the masking rules above; it has its own fixed redaction, always on. The sync folder sits under ABSPATH, and nginx ignores its `.htaccess`, so treat every value in the file as public.

- Export replaces secret-shaped values with `[dbvc-redacted]`. A top-level option is redacted whole when its name contains a word such as `secret`, `token`, `password`, `license` or `credentials`, or ends in `_key`, `_pass`, `_salt` or `_api`. Inside a settings array, only string or number values whose key ends that way are redacted. Look-alikes such as `max_tokens`, `lost_password_page` and a bare `key` are kept.
- Import treats `[dbvc-redacted]` as "keep this site's value". A redacted top-level option is never written. A redacted nested field takes the target's value at the same path, or is dropped when the target has none.
- To change the classification, use `dbvc_is_secret_option_key` (`$is_secret`, `$key`, `$path`). Here `$path` is `''` for a top-level option name and the dotted parent path for a nested key.
- Redaction runs after `dbvc_export_options_data`, so that filter cannot add a secret back.

### Other sync-folder files

The same redaction and placeholder contract covers the other files DBVC writes beside `options.json`:

- **ACF options-group files** (`options/{group}.json`). A field is redacted whole when it is an ACF `password` field or its name looks secret by the top-level rule. Repeater and group sub-fields are redacted by key name. On import, a fully redacted field is not written, and a redacted sub-field takes the site's current value at the same path.
- **Entity Editor snapshots** (`.dbvc_entity_editor_backups/*.snapshot.json`). Before the snapshot is written, the configured meta masking (`dbvc_mask_meta_keys`, `dbvc_mask_subkeys`) runs, and then the secret-name redaction. Serialized meta values are unserialized when a nested key is redacted. Snapshot and `.bak.json` names now end in a random suffix, and the folder gets the `.htaccess` and `index.php` deny files.
- **Upload sync and backup restore.** After files are placed, every folder in the sync tree gets the deny files, and `options.json`, `options/*.json` and WS Form form files are redacted again. A restored export from before redaction therefore stops exposing secrets, and it can no longer carry another site's secrets in on import. Sites that move secrets between environments through `options.json` on purpose can return `false` from `dbvc_redact_restored_option_exports` (`$redact`, `$sync_root`).
- **WS Form form exports** (`third-party/ws-form/forms/*.json`) and Entity Editor WS Form snapshots. Form-level meta and each action's settings (stored by WS Form as a JSON string in the action grid) are redacted with a WS Form key list: keys ending `password`, `secret`, `api_key`, `token`, `license_key`, `username`, `endpoint`, `webhook` or `header_value` always, and keys ending `email`, `cc` or `bcc` when the value contains a literal address. Mapping labels such as `..._dl_var_key` and `..._header_name` are kept. The filter `dbvc_is_secret_option_key` receives paths starting `wsform.meta` or `wsform.action.<type>`. On import a placeholder takes the uid-matched local form's value, matching actions by row ID and action type; a clone drops it, and an unreadable match refuses the import.
- **Backups** (`uploads/sync/db-version-control-backups/`). New backups get deny files in every folder and re-redact `options.json`, `options/*.json` and WS Form form files. For backups taken before redaction, run `wp dbvc redact-backups --dry-run --user=<admin>` to list what would change (names and counts only), then `wp dbvc redact-backups --user=<admin>` to apply after a confirmation. `--backup=<name>` limits it to one folder.

The `dbvc_export_options_on_update` setting (Export tab, default on) controls whether every saved site option rewrites `options.json`. Turn it off to write the file only through the admin export, `wp dbvc export` and the other export triggers.
