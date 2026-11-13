# Native Source Publisher v1 — Tours.dev

Status: SOURCE CANDIDATE. Not enrolled or authorized to publish.

The site is bound by exact origin, site UUID, immutable Git commit and one explicitly allowed mad4b-commerce JS file. It is not an arbitrary file writer.

The source operation reuses the vetted Company Code Release CAS/backup, original Broker grant cryptographic verifier, nonce replay defense, atomic write and rollback. Native runtime preflight independently verifies the original container mount, WP home URL, and first-party site UUID, not a remote Hostinger authority. A separate Master Admin approved ticket attestation must be signed by the trusted Central approval issuer for exactly the same plan. No private keys or approval issuers are present.

The original worker must register and claim the exact source.code.release Job, provide a fresh Original312 signed authority, and create a protected one-time Job reservation. A separately issued Broker Release Grant binds the same Job/plan. Only then can the installed SHA-pinned handler call the source executor. Independent readback is not itself an Original313 signed receipt: that must be issued by the separately trusted original finalizer.

Current root-owned Company Code Release policy is for Wayfatours, not Tours.dev. This candidate needs separately approved enrollment of a root-owned native-source-publisher-policy.v1.json, registered SHA-pinned handler/verifier/dependencies, Central issuer for the already-approved exact ticket, and signed Original313 finalizer integration. No runtime policy, registry, grant, user permission or site file is changed by these sources. CLI supports read-only status, plan and verify; it has no direct apply command. Test keys must never be promoted to production.

The sibling publisher-enrollment-proposal.json is a description, NOT an installed manifest or authority.


## 2026-11-13: Native release is canonical, OCI experiment archived

- Original Source Publisher and signed Broker/Original312/Original313 remain
  the one release authority. Company Code Release CAS, tenant-specific
  immutable Git source reconciliation and independent readback are the
  ordinary deploy path. A Docker runtime composer is NOT required.
- PR #819 was closed without merging. Its isolated PHP-FPM/OPcache QA
  can inform release checks, but its runtime controller has no authority.
- Hash contract: the installed first-party Registry checks the RAW
  provider Manifest bytes against its root-owned Index upon loading.
  Original306 signed association and this Publisher use the CANONICAL
  JSON hash of the trusted resolved Manifest. Keep these distinct.
  The installed Registry does not have a manifest_sha256() API.
  Do not import the #819 Publisher edit that depends on that method.
- First-party enrollment preflight remains read-only and returns HOLD
  until signed provider enrollment, Original306 exclusive association,
  root Source Policy and WordPress native write ability are available.
  Nothing in this source document grants authority.
