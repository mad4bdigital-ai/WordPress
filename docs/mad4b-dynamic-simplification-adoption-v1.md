# MAD4B — Capability-first Dynamic Simplification (Adoption v1)

**Status:** design + enforceable Security adapter pilot; NOT a global live rollout. Owner gates, Governed Write, Audit v2 and existing Control Plane policy remain authoritative. Production mutations forbidden.

## Design decision: reuse what already exists

| Existing shared MAD4B primitive | Single responsibility | What an add-on must NOT duplicate |
| --- | --- | --- |
| `MAD4B_SCP_Admin_Route_Registry` | Parent-order and exact-page capability registration | Direct `admin_menu` submenu bypass or second route map |
| `MAD4B_SCP_Site_Profile` | Enrolled site UUID, origin and effective environment | Tenant guessing from domain/blog ID or WordPress default Production |
| `MAD4B_SCP_Security_Diagnostics::can_read()` / `MAD4B_SCP_Policy` | Read authorization for site-local security triage | New independent ACL |
| `MAD4B_SCP_Audit` (Audit v2) | Chain-of-evidence events and identity | New WordPress audit DB |
| Operation Journal / Governed Write | Mutation intents, approved effects and audit | Direct quarantine, installer or database writes |
| `MAD4B_SCP_Site_Capability_Discovery` | Bounded, non-authorizing observed capability snapshot | Installed plugin treated as proof of correct runtime behavior |
| `MAD4B_SCP_Dynamic_Provider_Contract` | Advisory normalized provider manifests, including side effects | Manifest presence treated as write grant |
| `MAD4B_SCP_Provider_Health_View` | Per-capability health and degradations | Global plugin-version hard blocks for unaffected capabilities |
| `MAD4B_SCP_Capability_Traits` | Semantic matching and certified traits | Version equality treated as semantic compatibility |
| `MAD4B_SCP_Unified_Capability_Gateway` | Capability discover/search/prepare | Another MCP transport or unaudited callback executor |

## One universal decision sequence

1. **Identify** site UUID, enrolled origin and governed environment; unbound/ambiguous = `UNKNOWN`.
2. **Discover** provider/capability from the live WordPress abilities catalog or a bounded source observation; record evidence and freshness. Do not activate unknown add-ons.
3. **Classify** semantic behavior + side effects (`read`/`plan`/`governed_write`); an installed version is provenance, never sufficient authorization.
4. **Observe** compatibility per *capability*, returning `HEALTHY`, `DEGRADED`, `BLOCKED` or `UNKNOWN` without global provider-wide collapse caused solely by a changed version.
5. **Authorize separately** using existing policy/owner gate and an exact prepared operation. A successful read observation must never widen developer, breakglass or mutation grants.
6. **Execute** only through original governed dispatcher and independently verify readback; security evidence never triggers implicit effects.
7. **Correlate** Audit v2 + Operation Journal + independently witnessed observer records by site UUID/request/operation IDs when present. Missing SSH logs = `actor_unknown`.
8. **Recover** via version-agnostic re-observation of *interfaces and semantics*, then recertify only affected capabilities. No automatic promotion to trusted or Production.

**Critical invariant:** fail-soft for safe **read availability**, fail-closed for missing **authorization, identity, integrity and mutation certification**. Recovery of diagnostics or connection state never implies regained grants.

## Application matrix (incremental, not blanket modification)

| Area | Dynamic signal | Required guard | Adoption |
| --- | --- | --- | --- |
| Security & Forensics | Registered security Abilities + Audit v2 + Site Profile | Bounded read-only files; no auto-quarantine | Pilot implemented in PR #404 |
| Elementor | Discovered native provider capability, not installed version | Layout mutation remains separately certified | Existing provider discovery should be audited |
| JetEngine / JetSmartFilters | Actual exposed interface and schema semantics | No broad unknown schema writes | Existing provider contracts, next tests |
| WPML / Rank Math | Dynamic API/ability presence + equivalent outputs | No release/publication without consistency evidence | Candidate tests |
| Fluent Forms / Bit Flows | Bounded observed hooks/capabilities | Execution stays blocked without exact runtime evidence | Candidate tests |
| OAuth / MCP | Dynamic protocol/connection diagnostics | Scope, tenant and per-operation permission revalidation | Existing gateway, no new OAuth app |
| Hosting / Hostinger | Optional authenticated read metadata when available | HTTP 401 = `UNKNOWN`, not `PASS`; no invented SSH attribution | External optional |
| Google Drive Context | Connected scoped capability and origin | Read/write separated, revocation state truthful | Existing Context Authority |
| Backup / Recovery | Read-only inventory, backup signatures, restore preflight | Exact owner gate and rollback; no implicit Production | Not authorized |
| Independent Observer | Signed Native/Hybrid checkpoint and heartbeat | Off-machine witness and custody required | v0.4 separate package, not installed |

## Cross-cutting tests that every migrated module must pass

- Plugin version changed but same observed read semantics: diagnostics remain available **without granting write**.
- Provider absent or partial: return `UNKNOWN` with explicit missing evidence, not fake `HEALTHY` or hidden broken connections.
- Capability collision / incompatible schema: block that capability only; no fallback to unrestricted shell, SQL or REST.
- Staging vs Production and sibling-tenant isolation: exact Site Profile UUID and enrolled origin; reject bleed.
- Passive admin/REST hotpath: no full disk scan, package repair, schema migration, network calls, or new grants during ordinary read/navigation.
- Provider rollback/activation/deactivation: re-observe and preserve Audit/Journal; no hardcoded version dependencies.
- Loss of monitoring heartbeat: an independent watchdog detects silence; a WordPress UI heartbeat cannot certify itself.
- Evidence: HMAC chain and signed outbox detect tampering; off-host witnessed checkpoints required to detect full journal rollback.

## Rollout constraints

1. PR #404 provides the Security adapter **only**, not an automatic mass refactor.
2. Separate small PRs for provider contracts and test fixtures after inspecting each existing implementation.
3. All new security/compatibility surfaces expose current `UNKNOWN` when certification absent; no false “clean”, “trusted” or WORM labels.
4. Stage on **All Royal Egypt Staging** after the exact-head CI/owner gates and existing site diagnostics. No WordPress connector mutation in this phase.
5. Keep Production mutation-disabled until an explicitly scoped authorization and independent acceptance.

## Remaining blockers

- PR #404 GitHub CI + exact owner approval.
- Actual integrated WordPress end-to-end and passive-hotpath resource acceptance.
- Off-host observer v0.4 installation, key custody and witness readback.
- Source-bound clean baseline, plugin provenance, real event correlation, alert delivery and governed response plan.
- A cross-provider compatibility matrix is a rollout plan, not evidence that every provider has already been migrated.
