# PR #814 — Scoped Local OAuth Operator Grants Tool Readback

**Status: CI-only instrumentation, not an authorization change.**

On the real disposable WordPress Local OAuth matrix, the registered reviewed
`mad4b/operator-grants-converge` Ability is absent from the pre-bearer MCP
server inventory. The existing test proves the expected Ability survived
catalog budget selection, but does not distinguish a false registration,
capability descriptor binding failure, official tool preflight quarantine,
or adapter server materialization failure.

The independently materialized *official bundled MCP Adapter* successfully
builds the actual Operator Grants WordPress Ability schema, including its
`action=plan|apply`, exact plan/source/site binding and confirmation fields.
This rules out a generic DTO incompatibility in the bundled 0.6.1 Adapter;
it does not certify the full WordPress registration path on current CI.

The Local OAuth acceptance now returns **only** bounded, test-only boolean
flags and sanitized stage/error codes when the registered reviewed tool is
missing. It reads first-party `MAD4B_SCP_Servers::registration_status()`
without minting a token, reading a private payload, executing any Ability,
or showing the catalog/descriptor metadata itself. Fields: Ability name,
registered/base/requested/budget-selected/descriptor-bound booleans,
up to two descriptor error codes, up to two preflight stage/error/validator
codes, sanitized preflight blocker and observed tool count. The existing
fail-closed assertion remains unchanged: a missing reviewed direct tool
still fails the entire disposable WordPress acceptance.

The official Adapter DTO fixture also permanently tests the Operator Grants
schema to separate pure-schema regression from profile-/catalog-admission
problems. Nothing here grants operator rights or relaxes the independent
Developer Authority, signed Original313, Site Profile, OAuth step-up,
registered policy, CAS, or first-party Producer gates. Deploying to real
WordPress or changing production authority remains **RELEASE HOLD**.
