# Meta Masking Reference

## Live Proposal Masking

Live proposal masking lets reviewers apply the configured meta-field/post/term meta masking rules directly inside the All Entities table without altering the existing export-time masking pipeline. When the “Apply masking rules” button runs, any posts, terms, or media flagged as **Needs Review** or **Unresolved meta** are re-labeled after their masked fields are ignored, auto-accepted & suppressed, or overridden so entity counts stay accurate.

Open the **Tools** pill above the All Entities table to expand the toolbox. The Meta Masking section lives directly inside that panel alongside Resolver Summary, hash sync actions, and duplicate shortcuts.

## Tooltip Copy & Links

Each tooltip must include concise help text plus a link to this document (or the published equivalent) so reviewers can dig deeper without cluttering the UI.

### Apply Masking Rules Button

- **Tooltip text:** “Apply the configured masking rules to every post, term, and media entity in this live proposal. Matching fields flip Needs Review / Unresolved meta flags once masking completes. Learn more.”
- **Help link:** `docs/meta-masking.md#live-proposal-masking` (label: “Masking guide”)

### Field-Level Masking Actions

1. **Ignore masked field**
   - Tooltip: “Ignore this masked field so it no longer counts toward Needs Review, while leaving local content untouched. Ideal for metadata that shouldn’t block applies. Learn more.”
   - Link: `docs/meta-masking.md#ignore-masked-field` (label: “Why ignore?”)
2. **Auto-accept & suppress**
   - Tooltip: “Mark this masked field as accepted and suppress it from future proposals so sensitive values never surface in diffs. Live proposal labels update automatically. Learn more.”
   - Link: `docs/meta-masking.md#auto-accept-and-suppress` (label: “Auto-accept details”)
3. **Override masked value**
   - Tooltip: “Replace this masked field with a reviewer-provided override while keeping an audit trail. Use when a sanitized value must ship with the proposal. Learn more.”
   - Link: `docs/meta-masking.md#override-masked-value` (label: “Override workflow”)

## Behavior Notes

- Export-time masking continues to run exactly as it does today; the new button simply mirrors those rules inside the live review experience.
- After any masking action finishes, the UI must re-query entity badges/counters so Needs Review and Unresolved meta indicators reflect the updated state.

## Ignore Masked Field

Use the ignore option when metadata is informational (e.g., analytics UTM arrays) and shouldn’t require reviewer attention per proposal. The field is omitted from review counts but the site retains its existing value.

## Auto-Accept and Suppress

Auto-accept lets reviewers treat masked fields as if they were accepted manually, then suppress those fields from future diffs so sensitive information never reappears. This is best for secrets, feature flags, or other values that should remain hidden yet still follow the accept flow.

## Override Masked Value

Override is designed for cases where masking provides a placeholder but deployment still needs a sanitized replacement value. Reviewers supply the override once; the proposal records that change, and downstream applies inherit the sanitized value without exposing the original data.

## Revert Masked Decisions

If you need to roll back the automated selections stamped by **Apply masking rules**, open the Tools panel and click **Revert masking decisions**. This clears any accept/keep decisions that were created via the masking tool (based on the current mask patterns) and removes the related suppress/override records so the affected fields return to Needs Review. Use this whenever masking rules change or when a proposal requires full reviewer scrutiny again.
