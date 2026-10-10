# CI-independent Staging candidate installation — Feature 007 / PR #258

## Principle

GitHub Actions in `queued`, `pending`, `timed_out` or `unavailable` state **is not a deployment blocker in itself**. It is equally **not** a test PASS. Staging may accept a separately owner-reviewed, independently Ed25519-signed exact-HEAD native test package, without a terminal GitHub CI success run. The old normal CI-certified candidate route remains valid.

No Production update, master promotion, or GitHub branch-protection bypass is inferred by this Staging-only exception. Failures in **native tests, ZIP validation, source provenance, signer trust, owner approval, WordPress runtime identity, or rollback readiness are still blockers**.

## Trust and roles

- **Signer:** a separately operated trusted test machine with PHP 8.3/Docker + local v7 isolated kit. Generate an Ed25519 private signing key *outside WordPress*; do not transmit it to MCP, ChatGPT, GitHub or WordPress.
- **WordPress owner:** enroll only its raw 32-byte Ed25519 *public* key once using the new scoped MCP Abilities `mad4b/staging-offline-attestor-enroll-plan` then `mad4b/staging-offline-attestor-enroll-apply`, with current Site Profile identity, exact plan SHA, dedicated governed Write ability grant, enrolled administrator, ChatGPT OAuth authority step-up and explicit `ENROLL STAGING OFFLINE ATTESTOR` confirmation.
- **Verifier:** `MAD4B_SCP_CI_Outage_Attestation::verify()` uses pinned public key from the WordPress option and PHP sodium to verify receipt signature. The receipt must match immutable GitHub package source commit, archive SHA-256, build fingerprint, package manifest digest, ZIP size, site UUID, origin, literal `staging`, evidence-bundle SHA-256 and five named PASS gates. Receipt is valid at most **24 hours**. Changed key or absent independent native tests fail closed.
- **GitHub publisher:** `tools/mad4b_publish_offline_staging_candidate.py` validates exact locally built deterministic ZIP, exact canonical receipt, five gate outcomes, native evidence bundle digest and signed PR-head identity. It creates immutable, source-named ZIP and manifest; optionally publishes them to the *already existing* GitHub Release `mad4b-site-control-plane-update-channel` via authenticated `gh` CLI. No Actions run is needed. Neither master pointer nor old manifest is replaced, and it refuses existing asset overwrite.

## Native test requirements (not waived)

Verify *on the exact selected source HEAD*:

1. `g9_delivery_contract=PASS`: all source fingerprints and isolation/denial gates.
2. `php83_tree_syntax=PASS`: lint full plugin source under actual PHP 8.3.
3. `canonical_package_receipt=PASS`: deterministic official builder and SHA-256 receipt.
4. `isolated_zip_runtime_integrity=PASS`: independent runtime manifest/ZIP verification.
5. `exact_source_verification=PASS`: PR head equals immutable source commit and artifact provenance.

`Build-MAD4B-F007-Selected-PR-Latest-v7.ps1` is an established source of native evidence but **an individual successful run only proves what that run checked**; evaluate the five exact gates and retain a source-bound evidence bundle. Do not turn a failed test into `PASS` or sign partial source evidence. The owner must review the complete bundle; the publisher does not manufacture test outcomes.

Example `gate-results.json`:

```json
{
  "g9_delivery_contract": "PASS",
  "php83_tree_syntax": "PASS",
  "canonical_package_receipt": "PASS",
  "isolated_zip_runtime_integrity": "PASS",
  "exact_source_verification": "PASS"
}
```

The example is **schema only**, not an assertion that those tests passed on the current HEAD.

## Prepare and publish (CI not referenced)

Create an Ed25519 signer key with a local audited crypto tool such as
`openssl genpkey -algorithm Ed25519 -out staging-ci-outage-private.pem`.
Restrict file ACLs/permissions; never commit or share the private key.

```powershell
python tools/mad4b_publish_offline_staging_candidate.py `
  --source-sha "<EXACT_PR_258_HEAD>" `
  --pull-request 258 `
  --site-uuid "<VERIFIED_STAGING_SITE_UUID>" `
  --site-origin "https://staging.allroyalegypt.com" `
  --zip "<CANONICAL_ZIP_PATH>" `
  --canonical-receipt "<CANONICAL-PACKAGE-RECEIPT.json>" `
  --native-test-bundle "<REVIEWED_NATIVE_TEST_BUNDLE>" `
  --gate-results "<REVIEWED_GATE_RESULTS.json>" `
  --signer-private-key-pem "<LOCAL_PRIVATE_KEY.pem>" `
  --out-dir "<PRIVATE_OUTPUT_DIRECTORY>" `
  --owner-reviewed "I REVIEWED THE EXACT STAGING PACKAGE AND NATIVE TESTS"
```

The tool emits the **public** key for scoped owner enrollment and writes the signed exact-SHA GitHub release ZIP and manifest. After enrollment and independent review, rerun with `--publish-to-github` to publish those exact immutable assets. The fixed GH CLI release tag must already exist. If the selected PR HEAD moves, the publisher blocks and the signer must repeat tests/signing on the new HEAD.

No GitHub Actions terminal verdict is queried by this path. For continued unattended, compliant publishing, use an authorized independent runner to run the same five native tests and sign reports; the signer must remain outside any untrusted WordPress process.

## MCP update

The existing `mad4b/control-plane-selected-head-plan` now accepts **either** terminal successful original CI certification **or** an independently signed, valid Staging-only receipt, and reports:
`verification_evidence_mode = github_ci_verdict | owner_signed_ci_outage`,
`github_ci_terminal_required = false` in the latter case, and
`offline_receipt_sha256` / expiration bound to its exact SHA-256 plan.

After explicit owner approval, `mad4b/control-plane-selected-head-apply` re-fetches the selected PR source, downloads only the fixed GitHub Release ZIP, verifies SHA-256/byte size, re-checks signed offline manifest and expiry before any WordPress replacement, and hands the archive to the existing governed upgrader with maintenance lease, backup, audit, activation preservation, readback and rollback.

The WordPress-Native candidate opt-in remains **off by default**; use the separately authorized `mad4b/manual-workflow-plan/apply` to enable it after installation and authority convergence. A queued GitHub Actions check cannot override a failed WordPress site or release trust gate.

## Known limits

1. A new plugin source cannot gain new Abilities without installing a verified update at least once. Old installed code will not recognize signed CI-outage manifests before that bootstrap.
2. Separate Ed25519 key enrollment and evidence publishing require appropriate authorization; an MCP read cannot invent that trust.
3. An all-in-one WordPress clone may copy stored trust and Site Profile; this is less independently bound than the Host-backed deployment lane.
4. Browser/WPML/performance and other real-world acceptance can remain outstanding after a successful source/ZIP signature check; report them separately. Never label a candidate Production certified.
5. GitHub branch protection and other third-party rules may still use CI; this alternative is **not** a bypass for merging PRs or Production promotion.
