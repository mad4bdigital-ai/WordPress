# Permanent Content Hub Staging transport contract

## Verified diagnostic evidence (2026-11-01)

- A root-shell tool request was blocked **before Bash ran**, even for a read-only command ("date"). The reported refusal was "This tool call was blocked by OpenAI's safety checks". This provides no Bash exit status or stderr. It must not be interpreted as a Bash quoting or syntax error.
- The registered infrastructure inventory filtered to environment=staging returned **zero targets**; the unfiltered inventory listed **four production-tagged hosts** including the primary shared VPS. A mounted key named shell_access is present, but its presence does not establish authorization or Staging identity binding.
- Those observations do NOT prove why the external safety filter rejected a request. Only the platform that enforced the block can confirm its exact reason.

## Mandatory process

1. Use original typed infrastructure inventory and fixed read-only probes before requesting remote work. Confirm a Staging-scoped enrolled service/target, correct site and DB, and independent authorization. A shared Production-labeled VPS is NOT a substitute.
2. Enroll a distinct authorized Staging identity using the platform's original administrative process if none exists. No key substitution, self-grant, alternate endpoint or tunnel to evade an explicit refusal.
3. Use typed, narrowly scoped original Staging readback operations instead of embedding Bash, docker, wp eval, curl, SQL or heredocs into arbitrary remote-shell requests.
4. For changes use the original Native Operator plan and apply sequence, with exact source SHA, backup digest, authority receipt, rollback reference, unique correlation ID and independent signed readback. Preflight admission is never execution authority.
5. Classify a safety-check refusal as TOOL_ACCESS_DENIED_PRE_EXECUTION and stop. Do not change quotes, base64, spacing or connectors to make the same blocked action pass. Bash syntax failure requires an actual shell exit code and stderr. Record only safe metadata without credentials.
6. Preserve certified_only, existing Queue history and all identity/review holds. Never edit Production or invent provider/ChatGPT MCP receipts.

## Reusable preflight

Run locally: python3 tools/mad4b_staging_transport_contract.py --request request.json

Example JSON request with unverified enrollment (correctly denied):

{
  "contract": "mad4b.content-hub.staging-typed-transport.v1",
  "operation": "queue_status",
  "environment": "staging",
  "site": "https://tours.dev.mad4b.com",
  "database": "etg_commerce_staging",
  "mode": "certified_only",
  "target_id": null,
  "staging_binding_verified": false,
  "transport_authorized": false
}

The Python preflight performs NO remote execution and cannot grant rights. The tests are imported by tools/test_mad4b_content_hub_release_gate.py and run under the existing exact-HEAD Content Hub package CI.

Last verified Queue: 325 records, 14 nonterminal. This is historical until a new authorized Staging readback succeeds.
