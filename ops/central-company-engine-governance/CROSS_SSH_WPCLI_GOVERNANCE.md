# Cross-SSH WP-CLI — reuse original MAD4B Core and Content Job gates

**Status:** Source governance correction, not a deployed remote executor. Scope: Central Company Management. Keep the existing PR Draft.

## Architectural decision: REUSE, not parallel governance

The previous standalone WP-CLI policy compiler is a **non-integrated local prototype**. Do not wire it into the project, create a second grant ledger, review queue, job journal, transport authority or shell dispatcher. Reuse the existing Core services that Content Job already depends on, with a transport/provider adapter for owner-authorized **full WP-CLI sessions**, alongside typed automation operations.

The `MAD4B_SCP_Content_Jobs` aggregate owns content-job states, optimistic revision checking, append-only transitions and original source review. **Do not** repurpose content-job rows or its editorial approval as cross-company SSH execution grants. The reusable unit is its **Core gate composition**, not the content domain's authority.

## Verified source mapping

| Needed for company code reconciliation | Existing Core gate to reuse | Rule |
|---|---|---|
| Company/site/tenant/brand identity and revision fence | `MAD4B_SCP_Operational_Integrity::capture()` then `assert_unchanged()`; deployment/site profile resolver | Verify before and after remote read; identity checkpoint alone is non-authorizing |
| Operation definition and safe stage order | `MAD4B_SCP_Operation_Registry` + `MAD4B_SCP_Operation_Pipeline` | `company.code-reconciliation.plan` remains original `governed_read_only_v1`; no new dispatcher |
| Exact source hash, revision and durable job audit pattern | `MAD4B_SCP_Content_Jobs` transition CAS + `MAD4B_SCP_Audit` | Reuse shared audit/transaction integrity in the company operation. Do not write company jobs into content-job tables |
| Explicit protected mutation approval | `MAD4B_SCP_Approval_Tickets::authorize_exact/claim_exact/finalize_claim` | Only for separately registered write operation. No approval ticket inferred from a read plan |
| No duplicate effects / callback escape | `MAD4B_SCP_Execution_Fence` and original `MAD4B_SCP_CSO_Native_Executor` conventions | All writes enter certified native wrapper; uncertain outcome routes to reconciliation, never blind retry |
| Source-pinned provider adapter | `MAD4B_SCP_AI_Domain_Executors` registration pattern | Reuse *source digest, server-installed allowlist, owner descriptor, on-use recheck*; DO NOT register `company` inside its content-only domain map |
| Review → repair → rescan loop | `MAD4B_SCP_AI_Review_Lifecycle` semantics and original owner Reviews | Use the central review routing pattern for drift/ownership conflicts. Its content QA approval is not authority to change code or activate plugins |
| Confirm original owner result | `MAD4B_SCP_AI_Native_Owner_Review` owner-receipt pattern | Company provider must issue its own verified readback; do not accept editorial QA receipts |
| Signed evidence and trust | `MAD4B_SCP_Crypto_Profile` and the owner-audited signer enrollment pattern from `MAD4B_SCP_Test_Attestation` | Use a company-specific purpose and enrollment record; never trust a public key supplied only by the incoming evidence |

## Owner-enrolled full WP-CLI session over the original transport

**Channel-based standing authority:** enabling an authenticated SSH management channel for an exact company, site, WordPress root and OS/WordPress principal is the sole authorization event for that channel's Full WP-CLI capability. Do not require a second owner attestation, a second enrollment confirmation or an individual operation approval after the channel has been enabled. The existing authenticated management identity must have permission to enroll that precise company/site connection; a generic SSH reachability probe is not itself a company/site enrollment. The original Core and Native Broker remain the sole execution and auditing mechanisms. A remote connection merely discovered by an SSH handshake does not register another company's administrator.

1. When the company-authorized administrator enables the SSH channel, its enrolled scope includes the company, site UUID, canonical origin, environment, SSH target/host fingerprint, exact WordPress root, SSH account and WordPress principal. Enable `full_wpcli` as the standing capability of this channel, without any separate owner-attestation action (optionally `full_shell` as a distinct capability). Record the persistent capability in the **original Core authority/connection registry**, with revocation and revision tracking. Do not use a separate grant database.
2. On each invocation, Core checks the registered connection is active, the actor holds its full-WP-CLI access, the exact company/site/profile has not drifted, the SSH host key remains pinned, and the request has a unique job and digest. Existing native 312/313 verification, reservation, signed receipt and append-only audit remain in force, **without an extra per-command manual approval**.
3. **WP-CLI commands are free-form:** `wp eval`, `wp eval-file`, `wp db query`, plugin/theme operations, custom plugin commands and arbitrary WP-CLI flags may be used by the enrolled full administrator, including on Production. No command-name or WP-CLI-subcommand allowlist or artificial ban on raw SQL. The caller cannot silently choose a different server, company, WP root, SSH principal or target via a command flag; changing the connection requires re-enrollment. The native worker passes the command as an argument vector directly to the trusted WP-CLI executable, **never via shell interpolation**.
4. Optional full remote Shell is a separately owner-enabled capability bound to the same SSH connection. It is not silently inferred from WP-CLI privileges. Commands execute with the enrolled OS account's actual permissions; Root is not conferred simply by connection enrollment.
5. The original Native Broker signs the complete command digest, actor, company, site UUID, target, transport revision and execution receipt, then performs independent readback. For write-capable WP-CLI the result includes affected site and original Core audit evidence; unresolved execution does not automatically retry.
6. Original Site Profile, `Operational_Integrity`, deployment origin binding, account permissions and cross-company isolation are checked on every run. Staging and Production have feature parity; higher-risk operations may use existing risk logging/review rules, but routine command execution under an enrolled full-admin session never requires an independent one-time approval ticket.
7. The existing `company.code-reconciliation.plan` remains a read-only *automation operation* and has no implicit write authority. Free-form administration is its own original-Core capability, **not** a way to mislabel `maintenance.shell` requests or undermine the fixed diagnostic canary.

### Implementation boundary

The currently installed 312 authorizer is hardcoded to `maintenance.shell`, and 313 verifies one literal diagnostic. They must gain a **new reviewed original-Core administration capability** with source-pinned enrollment, command digest and signed readback; retaining the old fixed diagnostic unchanged. Do not substitute `maintenance.shell` to bypass a denied WP-CLI operation, nor treat a test-only handshake as live authorization. The VPS live extension remains pending until the actual original Core/Broker source is accessible through its authorized update channel.

## Do not confuse these boundaries

- Successful authentication of a **previously owner-enrolled full WP-CLI connection** conveys reusable WP-CLI administration authority for that enrolled company/site and principal; an SSH handshake for an unenrolled connection alone does not.
- Content Job review approval != code file deployment approval.
- AI Review Lifecycle `automatic_review_eligible` applies only to its declared content domains.
- The current `company.code-reconciliation.plan` is **read-only**; it cannot be reused as Apply.
- Core `Execution_Receipt` and `preparation_receipt` detached evidence have different contracts; an independent trusted signer registry and full-page verification are still required.
- Missing actual enrolled SSH target or refused tool call => **BLOCKED**, no alternate shell or synthetic success.

## Release acceptance

Positive: current enrolled SSH target, exact company/site binding, read-only Core operation, original source-pinned adapter, cryptographically verified signer and full-page readback succeed.

Negative: wrong company/site/brand/origin/environment, changed profile revision, unregistered SSH account or host key, missing full-WP-CLI enrollment, wrong actual OS principal, injected shell transport or substituted WP root, stale/replayed job, forged readback or signature are denied. **Do not deny a command merely because it is free-form, mutating, includes SQL/PHP or is unknown to a fixed command allowlist.**

**Execution state:** This document corrects the architecture only. It does not register SSH targets, deploy an executor, sign complete source/target inventories or modify Production.
