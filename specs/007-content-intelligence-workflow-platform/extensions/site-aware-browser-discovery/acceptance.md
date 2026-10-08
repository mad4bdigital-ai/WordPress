# Site-aware Browser Acceptance — adversarial contract

| Case | Expected | Class |
|---|---|---|
| One recognized ETG plugin and reviewed driver | Resolve ETG adapter + declared `tours` profile; certificate remains false | Isolated test |
| All Royal site with no signed acceptance provider | List unmapped signals, block execution, no ETG fallback | Isolated + live readback |
| A pluginless provider declares CPT and taxonomy evidence | May be recognized; reviewed driver still required | Isolated |
| Multiple recognized provider candidates | Explicit selection required | Isolated |
| Provider metadata disagrees with observed source list | BLOCKED | Isolated |
| Discovery hash/site origin/profile/revision drifts during execution | BLOCKED; discard local result | Isolated + E2E |
| Signed plan references another origin or unmatched provider | BLOCKED before Playwright | Isolated |
| Provider contract has no locally bundled reviewed driver | BLOCKED, never dynamically import code | Isolated |
| Unsupported plugin with no adapter | `UNMAPPED`, never `PASS` | WP admin view |
| ETG existing supported cases | Existing adapter semantics preserved | Native Node/Playwright |
| New site plugin semantic parity | Requires real oracle/provider-specific signed reducer | Live |
| Staging source ZIP differs from GitHub exact HEAD | Refuse release certificate | Package/live |
| Browser evidence absent, untrusted, or wrong digest | BLOCKED or INCOMPLETE, never PASS | Native reducer |
| Production WordPress environment or non-exact Site Profile | Deny writes and promotion | Live Staging gate |

**Invariants**: all discovery paths are read-only; no `wp_insert_post`, plugin activation, arbitrary HTTP requests or code evaluation. No mutation is authorized or inferred by recognition.

## Adversarial hardening (2026-10-09)

| Objection / exploit | Failing input | Source-only guard |
|---|---|---|
| Path traversal through active plugin basename | `../plugin.php`, leading-dot segment | Reject before `get_file_data` |
| Plugin recompiled under a new version without slug change | Header version changes | Per-main-file version entry in discovery fingerprint; invalid version blocks |
| Child or parent theme version changes | Same plugins/CPTs, new theme release | Theme/parent-theme version included in signed-run discovery snapshot |
| Same plugin active on multisite and locally | Duplicate basename | De-duplicate exactly matching basename; reject distinct files colliding on same slug |
| Provider hidden or repeated in discovery | Omitted or duplicated `provider_matches` | Exact declared-recognition coverage and uniqueness required |
| Bogus unmapped plugin list | Missing unhandled active slug | Recalculate independently and deny mismatches |
| Missing/bad post-type or taxonomy schema | Non-string or duplicate runtime signal | Deny before adapter selection |
| Evidence from another chunk/provider | Wrong case ID/order, nonce, signature, origin, observer, build identity | Cross-session Evidence Assembler blocks before writing |
| Remote browsing reuses prior context | Existing tab, cookies or service worker | New isolated context per execution, closed finally |
| Secret inheritance in child env | GitHub/MCP/OpenAI/OAuth tokens in process.env | Explicit transport-only allowlist |
| Cross-origin image beacon / nondefault port | Public remote image, same host different HTTPS port | Deny unless in approved asset-host and exact-port boundary |
| ETG evidence default inside generic MCP | Omitted explicit `expectedContract` | Fail closed; legacy ETG caller supplies contract explicitly |

**Important unresolved limitations:** a plugin or theme may change executable files while keeping the same version; plugin main-file headers are not a complete source or asset attestation. DNS rebinding can bypass syntactic hostname checks unless the external browser provider/network firewall enforces IP-level egress. Real All Royal Egypt domain-specific adapter/oracle, full PHP/Node/Playwright tests, signed reducer receipts, runtime exact-build pinning and Staging deployment remain mandatory.
