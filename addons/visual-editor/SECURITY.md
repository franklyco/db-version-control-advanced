# Security

## Scope

This addon will handle authenticated frontend editing of WordPress and ACF-backed content. Security requirements are strict.

## Minimum rules

- logged-in users only
- editor capability or stronger, or explicit custom capability
- authenticated REST endpoints only
- nonces for activation and save requests
- descriptor token verification server-side
- no arbitrary client-provided meta key writes
- field-type-aware sanitization
- audit log for all successful writes

## Reporting

Report vulnerabilities privately to the project owner or maintainer.

## Secrets

Do not commit secrets, environment variables, tokens, or private keys.
