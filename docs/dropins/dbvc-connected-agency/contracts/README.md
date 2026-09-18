# Draft contracts

`observation.schema.json` is draft v0.2, not a deployed DBVC API. Example IDs and hashes are synthetic. No file contains credentials or actual client content.

An event is an immutable observation of one logical object at a source sequence. The server authenticates its environment/epoch, derives client/agency scope and chooses recipients from trusted subscriptions. Sender origin/causation fields are descriptive, never authorization or proof of a completed operation.

The projection's `complete=false` means its hash cannot prove full equivalence. `exists=false` is valid only for a verified deletion/absence under the adapter's authoritative coverage. An unavailable read does not generate a deletion. Object/profile identity is part of comparison; hashes from different profiles are incomparable.

Maximum sequence uses the JavaScript-safe integer bound for eventual admin consumers. IDs are bounded ASCII values; opaque principal/registry bindings remain internal. Unknown fields fail validation. Retrying preserves body and event ID. A source clock is diagnostic only; sequence and revision drive ordering.

The Python reference validator checks the explicit example contract, not arbitrary JSON Schema. The package validator also checks schema JSON syntax. Use a full Draft 2020-12 validator during local integration if one is available; do not claim that the package's custom checks constitute general schema validation.

The SHA-256 function in the Python demo illustrates semantic distinctions. It is not a production Bricks canonicalizer or a normative PHP/JavaScript encoding. The local agent must build cross-language golden bytes/hash fixtures where needed, preserving list/object types and supported number semantics.

`examples/registry.json` is trusted synthetic server state, not a format clients may submit to select recipients. The production registry must enforce scope on writes and reads. `expected-routing.json` gives observable outcomes for the three included example events.
