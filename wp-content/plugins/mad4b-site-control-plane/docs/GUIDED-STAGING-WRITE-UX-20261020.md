# Governed Staging Write — guided single-intent UX

Status: **PR #443 source candidate**, not yet installed or accepted on ETG/ARE. Supports **Staging Write only**, not Production, Developer, Breakglass or Raw SQL.

## User journey

1. Owner asks: *"أصلح جاهزية الكتابة على ETG وARE Staging"* without knowing MCP operation IDs or SHA fields.
2. Client invokes the **existing** per-site first-party connector using `mad4b/enrollment-discover` with `query: "staging write authority convergence"`, or `mad4b/enrollment-info` with `operation_id: "staging_write_authority_convergence"`. Both now provide `guided_handoff` with a **read-only** site-specific state. A single exact query returns the prepared request; there is no need to search the Write catalog or request a direct tool that might be omitted from a bounded MCP `tools/list`.
3. If `state=already_current`, show readback only and **do not ask for approval**. If `prerequisites_blocked` or `invalid_*_evidence`, show the reason and do not expose a request. If `prior_operation_requires_reconciliation`, inspect the persisted site journal and signed owner-intent state; never blind-retry, never mint a replacement receipt to conceal uncertainty.
4. If `ready_for_exact_owner_decision`, show the site name/identity, **Write-only** scope and exact plan in plain Arabic. Ask for one explicit owner decision tied to the current exact plan; never issue it silently. Use the `prepared_request` returned in that read — not a copied or hardcoded SHA from past attempts — only after verified `mad4b:authority:step-up` for the enrolled owner and exact ChatGPT CIMD OAuth client.
5. Submit **once** through the existing `mad4b/enrollment-execute` dispatcher. It validates registration, dispatch-policy and input-schema digests again, then invokes the signed owner-review/one-time claim and governed Write-only reconciliation within its existing journal fence. A transport error or `target_error_reconciliation_required` is not failure proof or success proof; read journal and current state and do not replay.
6. In a new request, read back the current exact grants, candidate binding and `mad4b/write-runtime-certification`. Report each site separately, never propagate one site's owner approval or grant to the other.
7. Only after a separately approved exact content operation and successful current Write certification can a controlled tour Draft→Publish→Readback→Draft QA action run on ETG.

## Fail-closed UI contract

`guided_handoff` never performs a mutation. `prepared_request` exists only if the source plan is current, the registered Enrollment route is exact and owner-reviewed, and there is no uncertain or conflicting durable operation journal.

The field `automatic_execution=false` remains false even if the request has been prepared. The client may automate discovery, schema pinning, error classification and post-execution verification, **not** the owner's decision or OAuth step-up. Production content publishing, plugin installs, schema, permissions and release promotion follow independent governance.

There is no new privileged direct MCP tool, no new broad grant, no policy waiver, and no new global standing delegation. This design works with the existing Enrollment broker when the optional direct `staging-write-authority-convergence-apply` tool is absent from the client's bounded tools/list.

## Acceptance

- Hermetic PHP test checks successful ready state plus current, blocked, expired plan, stale digest, revoked route, uncertain journal and site-lock branches, verifying no replay/execution/Production/Breakglass.
- MCP compatibility suite runs under its PHP version matrix and also asserts the specific discovery/info wiring.
- After an exact-head certified package is deployed to ETG and ARE, run scoped user-journey acceptance end to end with **separate** current site owner decisions and independent grants/binding readback. Do not infer live success from source CI.
