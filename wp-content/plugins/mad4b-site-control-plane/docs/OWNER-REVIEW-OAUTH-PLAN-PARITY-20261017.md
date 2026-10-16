# Owner Review / OAuth plan parity — incident follow-up, 2026-10-17

## Incident to cover

Reported ETG Staging owner audit event 4460 (2026-10-16 21:32:42 UTC) shows a recorded owner intent but does **not** imply completion of Governed Write. Incident evidence supplied by the operator: 102/102 exact grants, installed source `a51fbdd6...`, stored candidate binding `303d4d10...`, reported local review digest `0950eee9...`, and OAuth/MCP review digest `ac66958b...`. These values are historical, supplied observations; this source-only increment does not independently assert that they remain the live values.

## Proven source-level mechanisms

1. WordPress owner review uses `plan_for_local_owner()`, which enters a narrow local-user preview on a single admin request; OAuth/MCP uses `plan()`, with bearer identity and dedicated authority Step-Up for apply.
2. Both review paths call the **same** `MAD4B_SCP_Staging_Write_Owner_Intent::digest()` implementation. It signs the exact plan identity, including `plan_sha256`, source, build, package, site UUID and profile revision/digest. Therefore different review digests do **not** on their own prove different hashing algorithms. Divergent input plans, effective subject/grant snapshots or state changes between requests are plausible. The source hashes the broad Write reconciliation plan to obtain `plan_sha256`.
3. The `102/102` count proves numeric parity only. It does not imply `candidate_binding.match`, manifest/provenance match, active write gate or confirmed effective runtime.
4. Local WordPress confirmation creates a **10-minute, HMAC-signed owner intent**, not a mutation grant. Actual apply requires the same explicitly enrolled owner, normal OAuth bearer bound to the exact ChatGPT client, authority Step-Up, exact request fields and one-time claim. Neither the successful redirect nor a recorded audit line is an apply completion receipt.
5. A transport timeout / indeterminate MCP apply result cannot justify blind replay. A one-time receipt may have been claimed already; status, candidate-binding and audit must be checked on a **fresh read request** before considering any new owner decision.

## Implemented in this PR increment

- New `mad4b/staging-write-owner-reconciliation-diagnose` read-only ability, protected by the **same owner OAuth Step-Up and client identity checks** as remote owner attestation.
- Newly recorded owner intents now retain the narrow exact `plan_identity`, a bounded `plan_evidence` projection and `review_channel` inside the existing HMAC-signed record. No credentials, grant rows, ticket values or unbounded provider structures are recorded.
- The diagnostic verifies the saved record and current plan, reports changed identity fields and read-only evidence fields, and shows counts, binding mismatch, current vs stored commit hashes, and selected next action.
- If an old receipt lacks the new signed identity fields, the output reports `LEGACY_RECEIPT_IDENTITY_UNAVAILABLE`; it does **not** reverse-engineer inputs from a one-way hash.
- Different exact plans are **not normalized into false equality**: the full exact-plan CAS and all owner/OAuth/grant/deployment gates remain unchanged. No new write operation or automatic approval is registered.
- The route matrix and read catalog expose the new diagnostic. The existing write handshake points the operator to this diagnostic when plan parity is uncertain.

## Decision table

| Diagnosis | Meaning | Safe action |
|---|---|---|
| Signed intent current + exact digest and plan identity match | Owner review can be presented to exact Step-Up workflow | Fresh OAuth handshake; submit exact plan once; new-request readback |
| Identity / grant inventory / binding evidence changed | Approval does not match current immutable or material plan state | Display changed fields, diagnose source and runtime; no receipt reuse |
| Historical receipt has no saved identity projection | Insufficient evidence to attribute original digest difference by field | Collect a new **read-only** plan comparison first; do not guess |
| Owner receipt already claimed but Write still blocked | Indeterminate execution or failed postcondition possible | Audit + fresh Write + candidate-binding status; never blind retry |
| 102/102 grants but bound head differs | Count parity is not package/runtime identity | Inspect exact candidate-binding lineage and separately authorized binding flow |
| Missing normal owner bearer, Step-Up, client, enrollment, audit | Independent authority gate unsatisfied | Fail closed and report exact blocker |
| Production host | Outside Staging-only authority scope | Separate production authorization required |

## Acceptance

Unit and hermetic tests exercise the same-plan local vs OAuth path, changed source, changed plan SHA, changed grant snapshot, 102/102 grants with stale candidate binding, legacy signed receipts, claimed/expired receipts, malformed diagnostic parameters, unsigned URL and missing Step-Up. PHP 7.4/8.3 tests run locally in containers; non-hermetic integrated source suites and GitHub CI must also pass before release.

**Not closed:** The historical audit ID 4460, original local and remote plan payloads, real signed OAuth subject, installed build on ETG and All Royal Egypt, and live browser acceptance have **not** been independently verified by this source change. There was no Staging mutation, grant replay, new owner approval, production change or certified deployment.
