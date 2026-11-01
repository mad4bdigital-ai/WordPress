# Cross-SSH WP-CLI — reuse original MAD4B Core and Content Job gates

**Status:** Source governance correction, not a deployed remote executor. Scope: Central Company Management. Keep the existing PR Draft.

## Architectural decision: REUSE, not parallel governance

The previous standalone WP-CLI policy compiler is a **non-integrated local prototype**. Do not wire it into the project, create a second grant ledger, review queue, job journal, transport authority or shell dispatcher. Reuse the existing Core services that Content Job already depends on, with a small transport/provider adapter for typed WP-CLI operations.

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

## Typed adapter over existing transport

The **only new responsibility** is a narrow, source-reviewed provider adapter mapping a reviewed Core operation to a supported remote WP-CLI read and verifying the returned result. It is not an authorization engine.

1. Resolve enrolled SSH target through the **existing infrastructure registry** (immutable target ID, fingerprinted SSH host key, account, company/site UUID, canonical origin, WordPress root, environment, enrollment revision).
2. Before execution, obtain the existing Core operation's descriptor, verified identity/scope checkpoint, current provider pin and fresh allowed read decision. Do not promote previous connector probes into authority.
3. Compile only the exact operation-defined typed arguments. No caller-originated shell strings, raw SQL, `wp eval`, `wp eval-file`, `--ssh`, `--allow-root`, arbitrary WP-CLI flags, or WordPress path selection.
4. Call a **registered, source-pinned** existing native transport provider only after verified enrollment; match actual remote Site Profile to the target binding. A caller-controlled hostname is never a target ID.
5. Run bounded read, then independently recheck Core identity/revision, provider source pin, data schema, page/manifest coverage, and original signer enrollment. Persist evidence in the existing Core audit/journal. Return read-only result without new privilege.
6. A mutation requires a **different** Core operation plus the original approval ticket, reserve/claim, execution fence, site provider adapter, readback and restore. Production uses the same operations with risk-based additional authorization.

## Do not confuse these boundaries

- SSH authentication != operation authorization.
- Content Job review approval != code file deployment approval.
- AI Review Lifecycle `automatic_review_eligible` applies only to its declared content domains.
- The current `company.code-reconciliation.plan` is **read-only**; it cannot be reused as Apply.
- Core `Execution_Receipt` and `preparation_receipt` detached evidence have different contracts; an independent trusted signer registry and full-page verification are still required.
- Missing actual enrolled SSH target or refused tool call => **BLOCKED**, no alternate shell or synthetic success.

## Release acceptance

Positive: current enrolled SSH target, exact company/site binding, read-only Core operation, original source-pinned adapter, cryptographically verified signer and full-page readback succeed.

Negative: wrong tenant/site/brand/origin/environment, changed profile revision, unknown host key/transport, unregistered operation, arbitrary command/flag, wrong signer purpose, missing/duplicate page, expired/replayed evidence and unsafe callback are denied. Native write admission and restore must be independently proven before any Apply.

**Execution state:** This document corrects the architecture only. It does not register SSH targets, deploy an executor, sign complete source/target inventories or modify Production.
