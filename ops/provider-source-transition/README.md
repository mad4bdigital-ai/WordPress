# MAD4B Provider Source Transition — neutral-first

This is an inspect-only source-resolution contract for the first legacy provider family tracked by issue #570. It is NOT a WordPress runtime adapter, installer, release gate, or provider certification. It cannot write options, rewrite database references, activate plugins, publish SEO data, expose new MCP abilities, or execute Cron/email.

## Why the legacy label remains

ETG Dynamic Filter SEO Bridge is a historical plugin/provider identity, not the identity of a customer or tenant. Its upstream constants, PHP namespaces, ability names, rollback contracts and recorded versions must not be globally renamed. The separate dynamic-filter-seo-bridge package is the canonical tenant-neutral successor in the existing cross-plugin-sources.json catalog. Both source entries currently have package_certification=pending, so code presence does not imply runtime readiness.

## Decisions

- New company: always select the canonical source as a candidate. Never implicitly fall back to a legacy provider, even if the old plugin is installed.
- Existing company: preserve the enrolled exact source reference (canonical or legacy) for compatibility; never inherit old write authority or infer certification from a readable adapter.
- Every decision: execution_allowed=false, publication_allowed=false, mutation_allowed=false. Separately verified package attestation, company/site-bound certificate, owner approval and policy admission are mandatory before actual deployment.
- Unknown provider, missing source descriptor, duplicate source or permissive transition policy: fail closed.
- Registry remains authoritative: no company IDs, fixed tenant domains, branded runtime classes or WordPress plugin modifications.

## Read-only commands

From the repository root:

    python3 ops/provider-source-transition/resolve_source.py --mode new_site
    python3 ops/provider-source-transition/resolve_source.py --mode existing_site --existing-source etg-dynamic-filter-seo-bridge
    python3 -m unittest discover -s ops/provider-source-transition -p "test_*.py" -v

## Safe migration route

1. Match the active deployed Control Plane build to authoritative source before changing bundled adapters. Never overwrite a newer runtime with older source.
2. Certify the canonical plugin with exact source/artifact provenance, Safe Boot and native compatibility tests.
3. Additive mapping: keep legacy abilities/class aliases as compatibility endpoints while read-only consumers migrate to the canonical provider.
4. Independently certify each write and undo behavior. Do NOT automatically transfer etg-dfsb-write authority.
5. Remove deprecated aliases only after zero active consumers and explicit owner approval.

This stage adds a source decision policy, a read-only resolver and CI tests. No Production or Staging mutation.

## Native consumer audit (Phase 2 — source only)

Run `python3 ops/provider-source-transition/audit_consumer_readiness.py`. The audit inspects the **actual repository source**, not a guessed company identity. It checks the source-registered legacy read and write adapter classes, compatibility abilities, native option aliases and version pins against the canonical plugin's declared version. It is deliberately non-authorizing.

At the inspected master revision, the old Control Plane read and write adapters require `0.4.0-alpha.13`, whereas the canonical neutral plugin declares `0.4.0-alpha.14-local.1`. Version compatibility must not be inferred from the neutral package's `ETG_DFSB_VERSION` alias alone. The newer isolated candidate `alpha.15-rc.1` in another branch is likewise not yet a deployed/runtime-authorized source.

Do **not** change the historical `etg-dfsb/...` ability names, old `etg_dfsb_...` option keys or the write/rollback adapter at this point. They are registered provider/source interfaces. Real neutral read migration first requires exact Control Plane `rc.118` source parity and a certified native plugin/read adapter; actual write migration is a separate signed execution process.

This audit and its negative tests do not deploy code, approve a provider, publish content or send messages.
