# Visual Editor Guardrails

- The browser is not the source of truth.
- DOM markers must be lightweight.
- Save targets must be resolved server-side.
- Unsupported content must remain unsupported.
- Derived content should display as derived, not editable.
- Shared/global fields require explicit warnings.
- All writes require capability checks, nonces, validation, sanitization, and audit logging.
- Avoid broad regex mutation of full rendered areas unless narrowly scoped and documented.
- Do not implement universal field support by default. Grow support through documented resolver additions.
