Read `AGENTS.md` first.

Build the first working MVP slice for the DBVC Visual Editor addon.

Use the docs in `/docs/` as the canonical plan.

Key rules:
- Bricks render instrumentation is the discovery layer.
- The server-side descriptor registry is the source of truth.
- The browser only receives marker tokens plus authorized descriptor payloads.
- Save only through explicit resolvers.
- Start with singular entity + text-like fields only.

Implement one complete end-to-end path before broadening scope.
Do not overreach into repeaters, options, query loops, or flexible content during the first slice.

When done, update docs to match reality and provide a concise validation report.
