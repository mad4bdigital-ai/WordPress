# Feature 007 G4 — Combined Repository Delivery Evidence

Status: `REPOSITORY_G4_FOUNDATION_AND_PREFLIGHT_IMPLEMENTED_PROVIDER_ACCEPTANCE_PENDING`

## Integration topology
- Integration Hub: PR #258.
- Canonical G4 integration PR: #283.
- Subordinate WordPress-domain preflight child: #284.
- Exact G4 starting Hub: `20552e34a75c601c247e36edc0dbc86ff4aa230a`.
- Reviewed master ancestor: `dccb0889799eb255f0d64d6959d9687252801c5b`.
- Required path: `#284 → #283 → #258 → master`.
- PR #284 does not independently own or close the 25 G4 tasks.

## Repository foundation implemented
- Typed provider-family readiness and non-authorizing planning for Forms, Commerce, Builders, Site Operations and WordPress breadth.
- Dynamic evidence reuse from Plugin Discovery, Adapter Registry and existing certified adapters.
- Provider-owned WordPress domain preflight contracts plus independent native-read observations and bounded denial fixtures.
- Existing Fluent Forms / JetFormBuilder, WooCommerce, Elementor, Polylang and LiteSpeed evidence is reused where exact contracts exist.
- Domain preflight covers schema/permission/serialization boundaries without dispatching native mutation.
- G4 runtime coverage executes on PHP 7.4 and PHP 8.3.

## Task state
Owned G4 tasks remain PARTIAL only: Forms T3931–T3935; Commerce T3936–T3940; Builders T3941–T3945; Site Operations T3966–T3970; WordPress Breadth T3971–T3975.
No new DONE claim is made.

## Safety boundaries
- `authorizing = false`.
- `production_authorized = false`.
- `runtime_parity_claimed = false`.
- `live_provider_acceptance = false`.
- `live_browser_acceptance = false`.
- `native_mutation_dispatch_implemented = false`.
- No generic shell, raw SQL, arbitrary outbound HTTP, automatic grants, automatic tool mounting or stale-authority replay.
- Financial/payment/refund effects remain separately gated and never treated as reversible.
- Private/PII surfaces remain under explicit scoped authority and masking rules.
- Restore remains readiness-only and cannot replay stale authority.

## Remaining acceptance
- Native provider schema/permission/serialization/readback for remaining catalogued providers.
- Governed native apply only where provider-specific reversible contracts and exact authority exist.
- WooCommerce HPOS/order/customer/stock/hook disposable canaries and financial-effect handoffs.
- Divi/Kadence/Gutenberg/FSE native parity and rendered readback.
- Backup/migration/cache/security/redirect native adapters and frontend/restore acceptance.
- WPML/ACF/core/BuddyPress/Events Calendar native readers/serializers and persisted acceptance where applicable.
- Real PII read/mask/consent/retention/export/delete acceptance.
- Exact-head child CI, owner attestation and separate merge authorization before #284 integrates into #283.
- Reconciled #283 cumulative CI/governance before any merge into #258.
- Representative Staging/provider/browser acceptance remains separate.
- Production and final #258 promotion remain unauthorized.
