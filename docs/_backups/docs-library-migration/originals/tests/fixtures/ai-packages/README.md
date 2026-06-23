AI package fixture directories for PHPUnit coverage.

Placeholders:

- `__SITE_FINGERPRINT__` is replaced at test runtime with the current site fingerprint.
- `__INVALID_SITE_FINGERPRINT__` is intentionally left invalid to exercise blocked intake behavior.

Each fixture directory represents the unpacked archive root. Tests ZIP these directories on demand.

Current compatibility fixtures:

- `submission-legacy-compat`: legacy manifest filename / operation alias coverage.
- `submission-legacy-generated-manifest`: AI-generated manifest shape that copied sample `site_fingerprint` to the root and used `validation_defaults.package_mode` instead of direct `intended_operation`.
