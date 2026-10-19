# Production automation and signed artifact tests

This is source implementation for review, not a live deployment or a Production certification. The existing Staging reconciliation worker remains Staging-only. Production uses a separate, explicit owner batch.

## Implemented paths

| Path | Behavior |
|---|---|
| Production runtime startup | The exact enrolled owner can enable `production_auto_enable` from Site Profile. The existing gate is restored on requests across code releases while its exact Site Profile binding remains valid. |
| Production content approvals | One owner-approved batch contains up to 20 exact operations, with one configured AI Agent and a 1–168 hour expiry. Supported abilities: core content create, update and bundle. Each item permits one decision only. |
| Production plugin deployment | Native WP-Cron runs owner-preapproved exact server-resolved plugin update plans once. Signed tests, package revalidation, protected plugin-file backup, activation preservation and installed-file readback remain required. |
| Signed artifact tests | The same read-only verifier supports Local, Development, Staging and Production. It validates the enrolled public signer, artifact bytes/manifest, version, asserted source SHA, issuer, exact target site/origin/environment, test outcomes and expiry. |

Production does not inherit a Staging OAuth auto-all scope or its grants. AI ticket decisions still require verified OAuth identity, the configured Production Agent, its exact Production NHI grant, deterministic classification/payload, candidate binding and the existing authorization/budget/content-review path. The classifier continues to report the baseline human lane in Production; this batch records that the owner has already approved the exact operation. It does not reclassify all Production operations as autonomous.

The batch claim is atomic and bound to a ticket. Replays and duplicate jobs fail closed. Revoking/replacing the batch or allowing it to expire also invalidates its unexecuted AI-approved tickets. A consumed item is not restored after an uncertain persistence or install result. Inspect and approve a fresh plan instead of retrying blindly.

## Owner setup

Open **MAD4B Control Plane → Site Profile** on the exact enrolled Production site.

1. Finish the existing Production Site Profile write/OAuth enrollment. Select **Enable automatic runtime** and confirm the exact rendered gate plan. This reuses the same compare-and-swap persistence, audit and rollback transaction as the OAuth gate endpoint; it does not weaken that endpoint's step-up requirements.
2. Enroll trusted **public** test signer keys in **Signed test certificates**. This panel exists in each supported enrolled environment. Private keys remain with the trusted test runner, outside WordPress/web roots.
3. Obtain `mad4b/plugin-remote-update-plan` on Production. A missing certificate blocks eligibility but the downloaded package identity is available for test binding. Run the required tests against those exact bytes on Staging/CI, produce the signed certificate, and request the plan again with `test_attestation`.
4. Submit an exact batch. For AI decisions, configure an existing Production Agent public ID and ensure its exact `mad4b/approval-ai-decide` grant exists. This UI never creates NHI grants. For deployments, copy the eligible current plan digest, the same reason/plugin identity, and its signed certificate.
5. WP-Cron executes the deployment batch when WordPress cron runs. Sites without traffic need a host cron runner. AI content approval decisions run when the authenticated Agent requests them; this feature does not schedule a permanently running AI agent.

Example batch shape (placeholders are not valid deployment evidence):

```json
{
  "enabled": true,
  "hours": 24,
  "agent_public_id": "<Production Agent UUID>",
  "approvals": [
    {
      "ability": "mad4b/content-update-post",
      "provider": "core",
      "input": {"post_id": 17, "post_title": "Reviewed title", "post_status": "draft"}
    }
  ],
  "deployments": [
    {
      "input": {
        "plugin_file": "example/example.php",
        "reason": "Deploy the exact tested update",
        "expected_plan_sha256": "<eligible current plan SHA-256>",
        "test_attestation": {"payload": {}, "signature": {}}
      }
    }
  ]
}
```

`approvals[].input` must be the exact operation input used to create the ticket, including any governed Context Receipt. Preparing different content, a different target or a fresh receipt requires a new exact owner batch. This first implementation is bounded batch automation, not an unlimited standing permission for future unspecified releases or content.

Save `enabled: false` with empty lists to revoke the batch. Removing/disabling a trusted signer revokes certificate validation immediately. Profile/origin drift, the mutation kill switch, expired evidence and missing audit storage block execution. A failed deployment stops the batch; other pending jobs are not silently continued or rescheduled. An abandoned worker lock requires inspection before manual recovery.

## Certificate and signer format

The verifier does not sign caller-reported PASS results. A trusted test runner must execute the tests and sign the resulting payload using an enrolled key. Signing is available to an appropriately configured runner through the existing `MAD4B_SCP_Crypto_Profile::sign_digest()` implementation. There is no remote endpoint to mint a test certificate or upload a private key.

Certificate envelope:

```json
{
  "payload": {
    "contract": "mad4b.artifact-test-attestation.v1",
    "issuer_site_uuid": "<issuer UUID>",
    "issuer_origin": "https://staging.example.com",
    "issuer_environment": "staging",
    "target_site_uuid": "<target UUID>",
    "target_origin": "https://example.com",
    "target_environment": "production",
    "plugin_file": "example/example.php",
    "package_sha256": "<exact ZIP hash>",
    "archive_manifest_sha256": "<exact archive manifest hash>",
    "target_version": "2.0.0",
    "source_commit_sha": "<40 lowercase hexadecimal characters>",
    "issued_at": 1792450000,
    "expires_at": 1792453600,
    "tests": {"install": "PASS", "smoke": "PASS", "rollback": "PASS"}
  },
  "signature": {
    "contract": "mad4b.detached-signature.v1",
    "profile_id": "<test signer crypto profile>",
    "profile_sha256": "<profile SHA-256>",
    "algorithm": "RS256",
    "kid": "<enrolled key ID>",
    "signed_sha256": "<canonical payload SHA-256>",
    "signature_b64url": "<unpadded base64url RSA signature>"
  }
}
```

Canonicalization recursively sorts object keys with string ordering, preserves list order, and uses UTF-8 JSON with unescaped Unicode/slashes. Payload fields use strings, integer timestamps and test-name→`PASS` maps. Sign the lowercase hexadecimal SHA-256 string, as the existing Crypto Profile does, using RSA SHA-256. The verifier checks the signed source assertion and exact artifact hashes; it does not independently prove that a Git repository produced those bytes.

Trusted signer JSON is a map keyed by `kid`. Each value contains:

- `enabled` (boolean), `issuer_site_uuid`, `issuer_origin`, `issuer_environment` (`local`, `development`, `staging`, `production` or `ci`).
- `target_environments` (nonempty list of supported WordPress environments).
- `not_before` and `not_after` (integer epoch seconds), and `required_tests` (list of additional functional test names).
- `public_key`: the matching `profile_id`, `profile_sha256`, `algorithm: "RS256"`, `kid`, and `public_key_pem` from the existing Crypto Profile public key export. RSA must be at least 2048 bits.

Every certificate requires `install`, `smoke` and `rollback`, plus configured functional tests, with every result `PASS`. Certificates last at most 24 hours and cannot outlive their key. Production accepts only Staging or CI issuers. Trust is exact-profile-bound; provisioning/copying a database cannot transfer it to another origin.

`mad4b/test-attestation-verify` exposes this check as a read-only ability in each supported environment. A valid certificate does not grant a write. Production's new native deployment queue requires it. The existing Staging remote-update path verifies it whenever supplied. Other legacy installers retain their existing release-signature/evidence contracts; this change does not claim to retrofit every installer or automate Control Plane self-updates in Production.

## Limits and verification

Core/MCP/protected plugins remain excluded by the existing remote updater. Native deployment authority is the explicitly recorded owner batch, not a fabricated OAuth session or human approval ticket. Its audit identifies that authority. Plugin-file rollback does not restore database migrations; this is not a full-site backup/restore system. Functional post-deployment acceptance on the live target still needs live testing; exact installed-file readback alone is not that acceptance.

The existing CI entry point `tests/governed-runtime-gates-contract.py` now runs the new gate, batch and real-RSA verification fixtures in isolated PHP processes, as well as the Staging batch regression. Locally tested on PHP 8.3, with PHP 7.4-compatible source. Queue tests replace the WordPress installer boundary; no real Production installation or PHP 7.4/8.5 live acceptance is claimed by those tests.
