# Quickstart — Controlled Staging Acceptance

This is an acceptance sequence, not Production authorization.

## 1. Preconditions

- exact feature-head package verified by SHA256SUMS;
- exact certified MCP Adapter active;
- `MAD4B_MCP_MUTATION_ENABLED` absent/false during install and discovery;
- no Breakglass/lifecycle/Flow/Elementor legacy-write constants enabled;
- database backup/snapshot available through hosting process;
- dedicated staging WordPress administrator available for configuration.

## 2. Install/activate

Install MCP Adapter first, then MAD4B Site Control Plane. Activation must create governance schema with **zero agents, zero subject bindings, zero grants and zero approvals**.

Expected authority state:
```text
read capability      governed by WP permissions
mutation global      OFF
NHI mutation         required when mutation becomes ON
agents               0
write authority      0
```

## 3. Run read-only self-test

`mad4b/runtime-self-test` / authority status must report:
- provider runtime integrity;
- custom MCP server isolation;
- governance schema ready;
- no wildcard grants;
- no duplicate subjects;
- approval/mutation storage ready;
- MCP peer inventory/blockers when implemented.

Any schema/provider/exposure blocker stops the sequence.

## 4. Certify authenticated subject bridge

Using the real staging MCP session, inspect only non-secret identity evidence supplied to `mad4b_scp_authenticated_subject_context`.

Acceptance:
- no Authorization header/token persisted or returned;
- stable subject type/fingerprint exists across calls for same client;
- another client does not resolve the same fingerprint;
- missing/ambiguous identity is observable and fail-closed for mutation.

## 5. Provision minimum staging NHI

Example profile:
```text
slug: staging-content-agent
status: enabled
environment: staging
subject: exact certified subject fingerprint
grants:
  - server: mad4b-content
    ability: mad4b/content-get-post
  - server: mad4b-content
    ability: mad4b/content-update-post
budgets:
  mutations: 3 / 15 minutes
  affected_objects: 3 / 15 minutes
```

Do not grant DB, filesystem, plugins, Flow or Breakglass.

## 6. Negative authorization tests while mutation OFF

- exact granted mutation → denied by global switch;
- ungranted DB update → denied;
- wildcard grant creation → rejected;
- disabled NHI → denied;
- wrong subject → denied.

No side effects are permitted.

## 7. Deliberately enable mutation for bounded staging window

Enable only the global mutation constant. Do not enable lifecycle/breakglass/Flow/legacy Elementor.

Re-run self-test. It must now require valid enabled NHI authority.

## 8. Approval and benign mutation pilot

Use one draft/test post.

Flow:
1. read post and capture modified/fingerprint;
2. create exact plan;
3. approve short-lived ticket if impact policy requires;
4. execute update;
5. verify read-after-write;
6. inspect mutation/audit record;
7. receive undo metadata if reversible.

## 9. Undo acceptance

First verify normal undo:
- current state equals after-state;
- undo succeeds;
- restored state equals before-state.

Then verify drift protection:
1. execute another governed mutation;
2. edit the post manually after mutation;
3. attempt undo;
4. expect `mad4b_undo_state_drift` and no overwrite of manual edit.

## 10. Replay/expiry tests

- reuse approval ticket → denied;
- modify one input field under old ticket → denied;
- expired ticket → denied;
- different NHI → denied.

## 11. Peer MCP test

If another MCP plugin/server is active, classify it. Unknown independent write authority is a blocker. Do not auto-disable; operator decides read-only/disable/federate.

## 12. Close staging window

Remove/disable `MAD4B_MCP_MUTATION_ENABLED` after certification unless an explicit controlled staging session continues. Verify mutation returns to deny state.

## Evidence required for Production review

- exact deployed commit/package hashes;
- runtime self-test output;
- NHI profile/grants with no secrets;
- identity subject type/fingerprint evidence;
- successful benign mutation and readback;
- successful normal undo;
- successful stale mutation rejection;
- successful drift-safe undo denial;
- approval replay/expiry denials;
- peer MCP inventory;
- audit-chain verification;
- confirmation that mutation gates are OFF at end of certification unless explicitly authorized otherwise.
