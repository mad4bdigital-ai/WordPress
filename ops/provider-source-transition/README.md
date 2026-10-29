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

At the 2026-10-29 inspected `master`, the old Control Plane read and write adapters still pin `0.4.0-alpha.13`. After merged PR #567, the neutral plugin's **source** declares `0.4.0-alpha.15-rc.1`. The native `cross-plugin-sources.json` catalog still records **`0.4.0-alpha.14-local.1` as its review candidate**, with `package_certification=pending`. These are different layers: source version, review candidate, installed build and certified package must never be conflated. `audit_consumer_readiness.py` reports the candidate-vs-source drift explicitly; no implicit promotion or rewrite of the reviewed source pin is permitted. Even a matching `ETG_DFSB_VERSION` alias cannot certify runtime or transfer write authority.

Do **not** change the historical `etg-dfsb/...` ability names, old `etg_dfsb_...` option keys or the write/rollback adapter at this point. They are registered provider/source interfaces. Real neutral read migration first requires exact Control Plane `rc.118` source parity and a certified native plugin/read adapter; actual write migration is a separate signed execution process.

This audit and its negative tests do not deploy code, approve a provider, publish content or send messages.

## Exact source/runtime parity gate (Phase 3 — source only)

`python3 ops/provider-source-transition/audit_runtime_parity.py --observed-runtime-version 0.4.0-rc.118` produces an informational comparison with the last reported test-site version. The version is **not** treated as authoritative evidence. Once root access and signed runtime provenance are available, supply the actual site UUID and exact main PHP SHA256. Even a matching version, SHA256 and site identifier **cannot itself authorize deployment**; a signed runtime build/deployment provenance and owner authorization must separately validate the source.

The workflow refuses to claim an adapter cutover based on source/header equality. Without actual runtime read-back, no plugin source in this PR is deployed to Staging or Production. The previous project's source was `rc.104` at inspection while Staging had most recently reported `rc.118`.

## Shadow-read parity — offline gate, never automatic cutover

`compare_read_shadows.py` compares **bounded, authorized read-only semantic fingerprints** collected from the historical and canonical DFSB providers for the same registered site. Its expected section names are `profiles`, `runtime_inventory` and `content_catalog`. Each snapshot must carry its native source ID, explicit site UUID and HTTPS origin, Site Profile revision, environment, locale/WPML language, Control Plane runtime SHA256, distinct provider package SHA256, timezone-aware capture timestamp and named semantic-hash contract.

A matching result is only `shadow_read_match_requires_certification`. It never grants plugin activation, source cutover, write authority or publication. Missing envelopes, mismatched tenant/origin/runtime/language, excessive capture skew and differences in semantic read hashes fail closed. Both snapshots must have been independently captured by an already-authorized read lane; this **comparator does not fetch WordPress or inspect confidential payloads**.

Usage when authorized snapshots become available:

`python3 ops/provider-source-transition/compare_read_shadows.py --legacy /path/to/legacy-fingerprints.json --canonical /path/to/canonical-fingerprints.json`

This is a test artifact contract, not a live observation. On the currently connected separate site `staging.egypttourgates.com` we observed the historical read abilities and two non-certified historical write surfaces, but not an authorized canonical read endpoint. Therefore no live shadow comparison or Staging switch is claimed.

## Reconciliation with current master

Before merging this PR, merge the exact current protected `master` into its branch **without rebasing or force-pushing** so source-based tests inspect the real merged DFSB version, not an outdated PR base. Keep a successful exact-HEAD CI run and fresh owner attestation before merging. The selector itself is always read-only. A later release must separately certify a reviewed exact build artifact, deploy to a named Site UUID, and compare live read fingerprints.
