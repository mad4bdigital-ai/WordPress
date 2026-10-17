# MAD4B Adaptive Operation Routing — Handoff and Security Contract
11 October 2026 · Feature 007 · PR #258 · Staging-oriented source delivery

## Objective
Replace fragile operation-name-by-name hardcoding in ChatGPT workflow planning
with **runtime-discovered, provider-neutral route selection**. Read-only
MAD4B Ability planning must find the registered capabilities and choose their
governed lane automatically, including ordinary WordPress plugins, content
integrations, approval planners, enrollment operations and developer adapters,
without treating discoverability as authorization.

## New registration
`MAD4B_SCP_Adaptive_Operation_Router`, bootstrapped via canonical
`Operational_Remediation`, registers two read-only private WordPress Abilities:
- `mad4b/operation-route-discover`
- `mad4b/operation-route-plan`

They are mounted on the `mad4b-read` server, cataloged under ChatGPT's
logical read universe, and listed in Operator Journeys. Neither is an
effectful executor; both reuse the existing policy read gate.

## Supported input
Exactly one target must be specified:
- `task`: natural-language goal, max 512 UTF-8 bytes;
- `ability_name`: exact existing registered WordPress Ability name; or
- `operation`: canonical operation/alias from the existing Operation Registry,
  with optional `target_kind`.

Additional bounded controls:
`intent=auto|read|write`, `phase=plan|execute`,
`max_candidates=1..8`, and observed
`failed_transport=direct|read-execute|write-execute|enrollment-execute|developer-execute`.
`failure_code` is **only an untrusted diagnostic hint**, never authority
to bypass a denied capability or retry a write.

## How the planner chooses
1. **Discover** using the existing `Operation_Registry::discover` for
   exact semantic operations, or
   `Unified_Capability_Gateway::search` against WordPress's registered
   Abilities (labels, descriptions, aliases, categories, declared read-only).
   If the auto top-N is composed entirely of mutations, run a second
   *read-only-filtered* search to locate a safe preflight planner.
   No external arbitrary plugin code is executed by discovery.
2. **Prepare** up to eight exact discovered Abilities through
   `Unified_Capability_Gateway::prepare`, which in turn uses the
   canonical Capability Descriptor, schema transport and signed preparation
   receipt; reject missing current receipt or unknown lane.
3. **Route** through the already governed ChatGPT dispatchers:
   `mad4b/read-execute` for reads, `mad4b/write-execute` for ordinary
   writes/admin/content, `mad4b/enrollment-discover/info/execute` for
   enrolled operations, and the isolated developer route only with its
   separately required Developer Agent and step-up. Never infer a missing
   registered executor or map all errors into admin.
4. **Rank** automatically and deterministically by bounded relevance,
   registered runtime state and safe-first policy. For `intent=auto`,
   an eligible **read planner wins over an effectful write**. A selected
   tool remains only a recommendation; the client must call the exact
   existing dispatcher with fresh input-schema/classification/authority
   scope digests and receipt.
5. **Handle transport failures** conservatively. A client-side
   `tool_not_found` does not demonstrate that an Ability is absent from
   WordPress. Recommend refreshing MCP `tools/list` and
   schema-pinned fixed discovery/dispatch, and disclose that a host's
   direct tool visibility is **unverified** by WordPress server metadata.
   Never claim a projected tool has appeared until the host confirms it.
6. **No blind mutation retries**. If any write/developer/enrollment
   dispatch may have failed after submission, planner returns
   `PREVIOUS_WRITE_REQUIRES_INDEPENDENT_READBACK`, with no selected
   ready-to-run route. Inspect operation journal, approval ticket, stored
   site revision and target postcondition first. Only a fresh exactly
   authorized operation can proceed.

## Approval example (root incident)
Earlier ChatGPT `approval-plan` was available on the WordPress write
catalog but unavailable on the current client's direct tools surface.
A nested write-dispatch attempt also returned an internal error. The
new route planner distinguishes:
- registered `mad4b/approval-plan` **from** host-visible direct tools;
- a fresh canonical descriptor **from** an execution grant;
- `mad4b/write-execute` as the standard governed route **from**
  a successful invocation of that route;
- a failed/indeterminate attempt **from** a clean unsubmitted request.

This is not authorization to call `Approval_Tickets::create_pending`
directly, forge a ticket, switch to admin, publish unreviewed content,
or invent a workflow. Ticket creation remains an independent effectful
operation with current OAuth/NHI grant and policy; AI decision must have
its own separately eligible policy and ticket receipts.

## Fail closed and dynamic limits
- Never select an unregistered, execution-ineligible or schema-unprepared
  Ability; never derive a missing adapter from a similar name.
- Breakglass/Raw SQL never auto-selected. Production does not gain
  mutation authority through routing or client suggestions.
- `enrollment` needs exact registered operation ID and current dispatch/
  schema policy digests; semantic executors described as
  `exact_executor_from_plan` remain unresolved until a planner issues
  its independently validated target.
- An isolated Developer route is not equivalent to ordinary `mad4b-write`.
- Capability Descriptor generation roots and preparation receipts are
  request-time inputs, not reusable trust or user approval.
- Retries for reads must re-run discovery/preparation for fresh receipts;
  no synchronous fallback performs arbitrary network or host commands.
- User requests, host error codes, availability hints and tool listing
  suggestions never create privileges. This source component only
  returns a bounded route plan.
- No new stored secrets, server commands, WordPress post changes, owner
  transfer, database updates or approval issuance in this feature.

## Acceptance / tests
`tests/adaptive-operation-router-runtime.php` verifies:
- only two private read-only Abilities are registered;
- direct exact-name, semantic operation and free-text discovery;
- correct canonical dispatch and preparation receipt;
- read-first automatic selection, no fabricated target;
- refused malformed inputs, oversized candidate sets, unknown operations;
- write dispatch absence, indeterminate write without fallback/replay;
- policy-denied reader, no auto-Production/Breakglass.

Native PHP 7.4 and 8.3 lint/runtime are added to the Feature 007 workflow.
As the workflow file is covered by G9 source fingerprints,
**G9 delivery evidence must be repinned to the precise Git blob bytes**
before running the Windows safe Build Kit. Keep all other G9 source
records intact and retain the G9 PARTIAL status.

## Still required for live completion
Deploy the exact certified immutable PR #258 HEAD to **All Royal Egypt
Staging** after its independent PHP/runtime checks. Then independently
verify the new route abilities are present and callable and that
approval planning works end-to-end *using a real new ticket*.
If a write API remains unavailable or the host does not expose the
registered direct tool, route output must report the limitation and
not claim a simulated success. The host-local Deployment Binding remains
a separate approved write with an exact ticket; do not run it from a
read-only routing plan. Production remains unchanged.
