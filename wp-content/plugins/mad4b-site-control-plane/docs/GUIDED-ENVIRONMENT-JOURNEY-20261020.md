# MAD4B — One guided journey across all WordPress environments

**PR #443 — source candidate only.** No live deployment, owner authorization, source signature, production modification or Staging grant is inferred from these changes.

## Single entrypoint

The read-only WordPress/MCP Ability `mad4b/environment-journey` accepts one `operation_kind` and derives the effective environment **from the live WordPress runtime and enrolled MAD4B Site Profile**. It never accepts a caller-supplied environment, site URL, site identity, secret, permission token or head SHA as an authority selector.

Supported operation families: `diagnostics`, `derived_state`, `operational_repair`, `write_authority`, `content_publish`, `plugin_update`, `deployment`. The returned result is a verified **read-only planning suggestion** with source-of-environment, next-read route and an explicit approval policy; direct tool registration is not a write grant.

| Runtime environment | Dynamic plan and next step | Execution boundary |
|---|---|---|
| **Local** | Resolve exact WordPress/Profile identity; discover the native operation or readiness plan | No implicit Staging owner attestation, no global write, no arbitrary filesystem/developer mutation |
| **Development** | Same capability discovery; exact native operation and own authorization policy | Production approvals and Staging Write convergence cannot be reused |
| **Staging** | For `write_authority`, call the existing exact-intent Enrollment route and embed its prepared Owner Review/step-up plan when journal-safe | Separate site owner consent + verified OAuth `mad4b:authority:step-up`, then **one** governed Enrollment dispatch, then independent grants/binding readback. No Developer, Breakglass or Production |
| **Production** | Diagnose safely; choose exact owner batch and signed test path for publication/update/deployment and any other privileged action | Policy `MAD4B_SCP_Production_Automation` is independently validated for the exact item, finite expiry and permitted site. An active batch is **not** approval of an arbitrary new request |

The same input can be sent to multiple sites, but every result is computed inside that site's enrolled WordPress runtime. No hardcoded ETG/ARE site IDs or hostnames, no cross-site owner receipts and no stale SHA carry-forward.

## Fail-closed invariants

- WordPress/Profile environment conflicts, missing enrollment, mismatched home/site origin, unknown environment or unsupported operation kind yield **blocked** and no executable request.
- `read_only=true`, `mutation_performed=false`, `automatic_execution=false`, `authority_granted=false` for every state in every environment.
- Staging prepared request can only come from the existing Enrollment registry, exact current owner-review plan and durable operation journal. It is displayed for explicit owner decision, not submitted by this Ability.
- An uncertain prior operation is never retried automatically: inspect audit, journal and live candidate binding before a new request.
- Production's existing `active()` exact-batch status is an informative fact, not a write authorization. The dynamic guide never creates or renews a batch, test certificate or approval ticket.
- The guide cannot override WordPress `WP_ENVIRONMENT_TYPE`, Site Profile identity, approval scopes, package attestations, local host policy or the provider capability closure gates.

## Acceptance requirements

1. Hermetic PHP exercise 4 × 7 environment/operation combinations, Profile/WordPress conflicts, invalid identities, unsupported environments and operations, Staging owner handoff, uncertain operation rejection, and attempted cross-environment plan reuse.
2. The MCP compatibility workflow must run both the hermetic PHP fixture and its source contract across supported PHP runtimes and verify the registered read-only ability and ChatGPT bounded catalog. The Production readiness review must be append-only, exact-source bound and nonauthorizing.
3. Only **after** exact-head CI, certified ZIP, and separate deployments to each environment can the integration be exercised with real OAuth/owner consent and independent runtime readback.
4. Tests on ETG and ARE Staging are per-site and do not prove Local, Development or Production authority. For Production, an independent controlled acceptance plan and explicit owner approval are mandatory before any write.

The implementation intentionally unifies **discovery, state, approval guidance and verification plan**, not permission grants or privileged execution.
