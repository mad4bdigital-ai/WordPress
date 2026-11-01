# Unified MAD4B authorization on central Staging — live deployment acceptance

Date: 2026-10-31. Environment: **Staging**, `https://tours.dev.mad4b.com`.

## Deployment performed

The approved VPS access connector `registry.infra.mad4b` applied a bounded, **read-only MU bridge** for the transport-neutral unified policy. This was an additive Staging installation, not replacement of the installed Site Control Plane package or issuance of a Production authority grant.

Host-protected, pinned Local Git source:
`/opt/staging/mad4b-unified-auth-staging-20261031/source`

Exact Local Git commit: `ffcbc5c7eece2084c29e5baf4488cfd1608aac92`. Five tracked source/test files. Three of those were installed into `wp-content/mu-plugins`:

- `315-mad4b-unified-authority-bridge.php`
- `mad4b-unified-operation-authority/class-mad4b-scp-unified-operation-authority.php`
- `mad4b-unified-operation-authority/class-mad4b-scp-central-operator-handoff.php`

The bridge loads after the existing Control Plane and registers **only** `mad4b/central-native-operator-handoff-plan` as a read-only ability. On an official package upgrade that includes these classes, the bridge defers to already-loaded classes to avoid declaration conflicts.

## Live acceptance

- PHP lint: PASS, all three installed files.
- Hermetic tests in the actual Staging PHP container: `MAD4B_UNIFIED_OPERATION_AUTHORITY: PASS` and `CENTRAL_NATIVE_OPERATOR_HANDOFF: PASS`.
- Source parity: all **3/3** installed files byte-identical to the pinned local Git source.
- WordPress bootstrap: unified policy loaded; handoff plan registered and responds.
- Central signed Content Hub readback: verified independently.
- Planning a central-only, Staging-scoped Content Hub receipt: explicitly **not** eligible to dispatch or mutate Production.
- Running `wp --user=1` without separate verified identity: `mad4b_unified_authority_unauthenticated`. WP user selection is **not** mistaken for SSH proof or permission.
- Existing `mad4b/plugin-transaction-plan` remains registered.
- Original Control Plane `MAD4B-BUILD-PROVENANCE.json`: `runtime_manifest_match=true`, `stale=false`, `provenance_mismatch=[]`. No existing Control Plane files overwritten.
- Frontend: HTTP 200. Unknown release: HTTP 404, not a fabricated PASS.
- No Production mutation, SSH remote-executor deployment, grant expansion or user credential change.

## Audit and rollback

Host audit record: `/opt/staging/mad4b-unified-auth-staging-20261031/acceptance.json` (mode 0600).

Exact-source, guarded rollback script: `/opt/staging/mad4b-unified-auth-staging-20261031/rollback-staging-unified-authority.sh`. Syntax checked; **rollback not executed** because acceptance passed. The script verifies the pinned source and all three installed file bytes before removing the MU bridge and then rechecks the original Control Plane and site.

## Integration remaining

This deployment establishes the **policy and planning layer**, not a direct production executor. The following operations have not yet been integrated and must not be represented as working:

1. Live OAuth/MCP, host-attested SSH/WP-CLI and signed Broker 312 adapters must each produce a trustworthy, bound identity tuple.
2. Bind the verified tuple and fresh exact, deny-first grant to actual dispatch of one immutable operation. A read-only `eligible` predicate alone is not an execution permit.
3. Native SSH Remote Executor has not been safely published after a separate tool security refusal; no denied route is bypassed.
4. Remote Readback 313 and rollback must independently prove the real target postcondition.
5. Existing Production deployment gate and operator approval rules remain unchanged.

This acceptance is intentionally separate from GitHub Release Verdict; a Staging read-only MU canary passing is not a declaration that the entire Control Plane release was merged or commercially shipped.
