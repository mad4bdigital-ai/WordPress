# MAD4B MCP — independent CI layers (2026-10-22)

This is a non-authorizing quality/acceptance split. It **does not** disable or replace
existing Release Verdict, owner attestation, site-specific Staging acceptance,
Production readiness, governance or exact-head policy. The workflow runs on PR
heads, protected-master source changes, and manual dispatch.

## What a green check means

| Check | Purpose | Must NOT be inferred |
| --- | --- | --- |
| L1 / Source quality and inventory | PHP syntax, static hotpath rules, MCP declared/registered inventory consistency | That WordPress loaded or that a remote client connected |
| L2 / Fail-closed behavior | Deterministic simulated adapter rejection, catalog preflight faults and projection behavior across PHP 7.4/8.3 | That a mock is faithful to WordPress users or OAuth |
| L2 / Native WordPress capabilities | Real WordPress Core + SQL, positive draft-owner and negative subscriber checks with \`map_meta_cap\` | That Direct Mode is released or that a production site permits writes |
| L3 / MCP protocol and HTTP authorization boundary | Disposable WordPress + pinned MCP Adapter; authenticated WP-CLI MCP initialize/tools/list and unauthenticated HTTP initialize rejection (404 is a failure) | End-to-end HTTP Bearer OAuth issuance, remote ETG/ARE interoperability, safe write authorizations |
| L4 / Non-authorizing release integrity | Immutable checked-out SHA, source-controlled matrix, coverage-registry self-test, readiness baseline self-test | Owner approval, signed runtime evidence, site readiness or Production promotion |

Each job starts independently (no \`needs:\` edges), emits its own failure status,
and never uses \`continue-on-error\`. Workflow token permission is \`contents: read\`;
all SQL and post/user fixtures execute only in disposable CI services.

## Source-structure false negatives

The previous ChatGPT hotpath test counted exactly nine textual occurrences of
\`$this->create( $adapter,\`. That is a frozen architectural assertion, **not**
a proof of route correctness. The revised test derives server IDs from
\`MAD4B_SCP_Servers::expected_server_ids()\` and compares that set to actual
\`register_servers()\` declarations. It rejects missing/duplicate routes without
forbidding a reviewed tenth server. Runtime registration, WordPress REST route,
permission callback and OAuth behavior still need L2/L3 tests.

The Direct-Mode branch's local PHP \`current_user_can\` mock does not implement
WordPress \`map_meta_cap('edit_post', ...)\` semantics. The new L2 WordPress
integration proves this capability mapping using real core rather than changing
production permissions for the mock. PR #469 must update its own harness before
retesting Direct Mode. This CI branch does not import or approve PR #469's
Direct Mode implementation.

## Interpreting independent failures

- **L1 failed:** invalid source syntax, impossible inventory or malformed test
  architecture. Do not modify WordPress business logic merely to preserve a
  count of server registrations.
- **L2 failed:** reproduce with the recorded WordPress/PHP and inspect fixture
  fidelity. A mock failing while WordPress passes is a *test defect*.
- **L3 failed:** check the network trace and stage of failure: bootstrap,
  REST route, authentication, initialize, catalog/tools/list, then tools/call.
  A 404 must not count as successful unauthorized access rejection, and an
  empty tools list must not be reported as restored site functionality.
- **L4 failed:** investigate manifest or capability-coverage drift, exact
  provenance, and owner approval separately. These are release blockers but
  do not alone demonstrate a broken MCP handshake.

CI success must never be presented as proof of an ETG/ARE ChatGPT connector.
A separate Staging acceptance must exercise real externally issued OAuth
credentials and \`initialize\`, \`ping\`, \`tools/list\`, a permitted harmless
\`tools/call\`, invalid-token rejection, and catalog fault-recovery on each
site. Those credentials must never be inserted into a PR workflow.

## Future acceptance

1. Keep existing governance, packaging and release checks active.
2. Fix PR #469's mocked meta-capability semantics and separately diagnose
   its new-server registration failure; never delete \`edit_post\` permission
   enforcement to make a mock pass.
3. Add PR #471's explicit bad-descriptor fault-injection regression: authenticated
   transport remains usable for \`initialize\` and \`tools/list\`, rejects any
   \`tools/call\`, and returns normal verified tools after repair.
4. Keep Direct/Governed selection behind the existing \`mad4b-chatgpt\` surface
   only after an independently reviewed protocol design and staging evidence.

The new L3 checks use WordPress Core authentication via WP-CLI for a *positive*
protocol scenario and anonymous HTTP for a *negative* transport scenario.
These are deliberately different trust boundaries; neither replaces positive
HTTP OAuth bearer-token integration. No ETG, ARE or Production mutation was
performed in this change.
