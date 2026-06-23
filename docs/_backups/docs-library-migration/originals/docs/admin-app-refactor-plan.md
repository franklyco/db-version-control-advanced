# Admin App Refactor Plan

The React admin bundle currently lives in `src/admin-app/index.js` as a 3k+ line monolith. This plan breaks the work into incremental refactors so day-to-day changes no longer require editing the compiled artifact.

## Target Architecture
- `src/admin-app/App.tsx` – top-level router + context providers.
- `src/admin-app/api/index.ts` – typed wrappers for the REST routes (`fetchJSON`, `postJSON`, `deleteJSON`).
- `src/admin-app/store/` – proposal + entity state (React context + reducers).
- `src/admin-app/components/` – split per concern (EntityTable, ResolverDrawer, MaskingTools, Notifications).
- `src/admin-app/hooks/` – shared hooks (`useMasking`, `useResolverMetrics`, `useToastQueue`).
- `src/admin-app/styles/` – SCSS modules compiled via `wp-scripts`.

## Iteration Plan
1. **Extract data layer**
   - Recreate the request helpers (`n`, `i`, `l` in the current bundle) as proper modules with fetch polyfills, nonce injection, and typed errors.
   - Add Jest tests for the helpers so retries/error states are covered before moving UI code.
2. **Rebuild masking tools**
   - Port the masking drawer UI into a standalone React component that consumes `useMasking` (hook manages pagination, apply batching, undo cache).
   - Keep the legacy bundle as a fallback flag until parity is confirmed.
3. **Entity table + filters**
   - Move the virtualization + column selection logic into modern React components (react-table or equivalent) so filters and drawers can be reasoned about independently.
4. **Resolver + notifications**
   - Extract toast stack, resolver drawers, and duplicate overlays into modular components; wire them to the shared store so state flows in one direction.
5. **Build tooling**
   - Update `package.json` to point `main` to `/build` assets but use `/src/admin-app` as the source of truth.
   - Document `npm run dev` / `npm run build` steps in `README.md` and enforce linting via `wp-scripts lint-js`.

## Migration Safeguards
- Add a feature flag (`DBVC_EXPERIMENTAL_ADMIN_APP`) so the new bundle can run on staging before replacing the compiled file.
- Mirror every REST call in Cypress smoke tests (or Playwright) to verify regressions before shipping.
- Keep the compiled bundle checked in until the TypeScript source proves stable; once the new pipeline lands, move legacy bundle under `build/` as generated output only.

## Current Status
- ✅ API + UI audit complete; chunking + masking telemetry feeds the spec above.
- 🔄 Data layer extraction in progress (request helpers identified, tests pending).
- ⏳ Component migration staged for masking tools followed by entity table refactor.
