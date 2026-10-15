# Implementation and extension plan

## Delivered source seams

- PHP observation: `class-mad4b-scp-site-capability-discovery.php` — bounded inventories and declarative provider recognition
- WordPress projection: `class-mad4b-scp-browser-acceptance-core.php` — `site_discovery` read only
- ETG adapter metadata: source plugin recognition and `default_profile_id=tours`
- Generic decision layer: `site-adapter-resolver.mjs` with exact, fail-closed target selection
- Reviewed execution registry: `site-driver-registry.mjs` (currently contains the ETG driver only)
- Generic external worker and orchestration: `run-site-browser-acceptance.mjs`, `run-live-site-browser-acceptance.mjs`
- MCP bridge: provider-specific evidence type selected by approved driver; removed implicit ETG plan/profile fallbacks
- UI + Staging view: show unmapped sources; prefer the selected provider's declared default profile rather than a hardcoded site name
- Contract workflow: native PHP syntax/smoke, Node boundary tests, generic executable syntax

## Safe onboarding of a new website

1. Read live site snapshot; verify HTTPS origin, Site Profile and deployment identity.
2. Compare `provider_matches` and unmapped plugin/CPT/taxonomy inventory. No site-name overrides.
3. For new functionality, create a *reviewed* WordPress provider descriptor with explicit recognition signals, plan callback, bounded signed-plan schema and evidence reducer.
4. Add a locally reviewed driver implementing `validatePlan` and `runBrowserPlan` to the static registry; no remote JS.
5. Add native fixture and adversarial tests: multiple candidates, wrong locale/query, missing case oracle, signature mismatch, stale revision, cross-origin, payload over budget.
6. Build exact-head package and run Playwright and signed WordPress reducer on Staging; certify only the tested capabilities.

## Residual dependencies

- All Royal Egypt plugin-specific Business/Booking/Elementor acceptance driver and independent semantic oracle are **not yet implemented**; generic discovery alone must never claim these passed.
- Network adapters need deployment-bound provider credentials, real external MCP initialize/tools/list and fresh signed Browser Acceptance evidence.
- Unknown plugin versions, unsupported dynamic endpoints and native browser interactions remain separate qualification requirements.
- No Production mutation/approval or `master` merge is authorized by this change.
