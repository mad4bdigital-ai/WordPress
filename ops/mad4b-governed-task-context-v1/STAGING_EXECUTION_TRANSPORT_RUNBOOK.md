# MAD4B Content Hub — execution transport diagnostics (non-blocking)

## Decision: do not add parallel permission gates

This file and tools/mad4b_staging_transport_contract.py are **diagnostic only**. Neither is a new execution proxy, security/approval layer, allowlist, or an independent reason to reject a permitted MAD4B operation. All actual permission checks remain with the original target connector, MAD4B authorization service, Native Operator, and destination site.

### Evidence and limits (2026-11-01)

- A remote root-shell call, including \`date\`, was blocked before Bash executed. Its tool-level safety refusal does not prove a command-syntax error; the cause of the platform refusal remains unconfirmed. Do not retry the **same rejected action** with alternate encoding, another connector, or quotation tricks to evade that refusal.
- The infrastructure inventory returned zero *separately Staging-tagged hosts* and four Production-tagged hosts. This is **not evidence that Staging is unenrolled or unauthorized**: the Staging WordPress container can reside on a Production-tagged VPS. Check the actual site, database, role/grant and operation, not a host label alone.
- The existence of a shell_access key does not itself prove that any particular command is authorized or forbidden.

## Correct existing-operation lifecycle

1. Identify which existing original MAD4B tool is appropriate and inspect its actual operation-specific contract. Read operations may use a valid authorized shell or WP-CLI route, without backup, rollback or custom Staging target prerequisites imposed by this diagnostic.
2. Distinguish **host inventory environment** from the **destination application environment**. Both Staging and Production may be operable under the original grant/policy; this runbook adds no environment ban and never treats a Production host label as a site denial. For this Content Hub backlog work the current intended destination remains tours.dev.mad4b.com / etg_commerce_staging. Do not silently switch sites.
3. For mutating operations follow the *existing Native Operator or service-specific* plan, authorization, backup, effect-readback and rollback requirements as applicable to that operation and its risk. Do not demand these from harmless reads, or assume a provided string/boolean conveys an actual grant.
4. Preserve real outcomes: source identity reviews require source evidence, and AI jobs require original authenticated MCP client/provider results. Do not mark a task successful solely to reduce pending counts.
5. If the **platform itself** rejects a tool call before execution, record TOOL_ACCESS_DENIED_PRE_EXECUTION, stop the rejected action, and request original-channel diagnostics or legitimate permission correction. A Bash failure requires a real shell exit status and stderr. Other authorized workflows are not disabled globally because one route failed.

## Optional standalone diagnostic

Run: python3 tools/mad4b_staging_transport_contract.py --request request.json

Example JSON request:

~~~json
{
  "operation": "queue_status",
  "site_environment": "staging",
  "host_inventory_environment": "production",
  "site": "https://tours.dev.mad4b.com",
  "database": "etg_commerce_staging"
}
~~~

The output is an *observation*, not an admit/deny result. It never accesses the VPS, authorizes a user, runs a command, or changes a Queue record. Its regression tests run in the original Content Hub exact-HEAD CI gate to prevent someone from accidentally reintroducing Staging-only host labels, required backup on reads, or blanket Production/enforce denials.

Last confirmed Content Hub Queue count: 325 records / 14 nonterminal. It must be rechecked via an authorized original source before reporting it as live.
