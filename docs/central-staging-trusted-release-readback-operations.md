# Central MAD4B release readback — operational setup

This document covers the evidence **transport**, not MAD4B Package Certification. Packages from `local_git` or `remote_git` can use this transport without `certified-providers.json` or a separately issued Package Certificate.

The issuer is the central Staging server recorded in `config/central-staging-source.json`. Every company receives the same site-neutral plugin; its `mad4b/central-company-rollout-plan` calls the configured endpoint and performs company-specific compatibility checks.

## Central issuer

The central Staging VPS uses the existing protected, **read-only** container mount:

- WordPress reader: `/run/mad4b-native-abilities/central-release-readbacks`
- Host-owned journal directory: `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/native-ability-authority/central-release-readbacks`
- Private signing key: `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/credentials/central-release-issuer-private.pem` (root-owned 0600, outside WordPress)
- Pinned public verification key delivered to every company from `config/central-release-issuer-public.pem`

The host directory and signing key have been provisioned and cryptographically self-tested on Staging; **the journal is empty** until authentic release-install/smoke/rollback receipts are available. No site-local `wp-config.php` constants are required for this current architecture.

The WordPress GET route `/wp-json/mad4b/v1/central-release/<sha256>` runs **only** on the site whose actual URL matches the config origin, whose real WordPress environment is Staging, and whose Site Profile origin is enrolled. It only **reads** the protected journal. It cannot create a test record or sign an assertion.

The trusted host executor produces a release record only after independent evidence confirms all of the following **for the exact same built artifact**:
1. Immutable Git source commit, code tree and file-manifest hash.
2. Exact installed artifact and version on the central Staging site.
3. Successful installation smoke and rollback rehearsal.
4. Bounded release requirements (PHP/WP minima, dependencies, target plugin, database migration rollback support when needed).
5. A recent successful test result. The signed record is tied to the source commit and artifact hash.

The issuer signs the canonically key-sorted UTF-8 JSON of the `receipt` object with RSA-SHA256 (2048-bit or stronger). The public envelope is:

```json
{
  "contract": "mad4b.central-release-evidence-envelope.v1",
  "receipt": {
    "contract": "mad4b.central-staging-release-readback.v1",
    "staging_origin": "<configured central Staging origin>",
    "environment": "staging",
    "source_type": "local_git",
    "source_commit": "<40-hex commit>",
    "artifact_sha256": "<64-hex artifact>",
    "file_manifest_sha256": "<64-hex manifest>",
    "staging_pass": true,
    "tested_at": 0,
    "checks": {
      "install": true,
      "smoke": true,
      "rollback": true
    },
    "requirements": {
      "target_plugin_file": "mad4b-example/mad4b-example.php",
      "target_version": "1.0.1",
      "php_min": "8.1.0",
      "wordpress_min": "6.5.0",
      "required_plugins": [],
      "requires_schema_migration": false
    }
  },
  "signature_b64": "<detached RSA-SHA256 receipt signature>"
}
```

The numeric timestamp above is a *schema illustration*, not a valid release or test result. Never treat this document as evidence that the actual Staging tests passed.

## Company verification

Companies receive only the **public** issuer key inside the reviewed site-neutral plugin build, never the private key. The trusted fetcher:
- accepts only the release SHA-256 from the caller; its URL comes from the repository configuration;
- uses WordPress safe HTTPS with redirects disabled and bounded responses;
- verifies the central origin, exact artifact hash, source commit, fresh test timestamp, successful test flags and RSA-SHA256 signature;
- locally obtains each company's Site Profile, WordPress/PHP version and installed plugin versions;
- outputs a **read-only, non-authorizing** compatibility plan, not a Production write token.

If the journal, readback endpoint, trusted public key, independent Staging results, tenant identity, package integrity or compatibility data is unavailable, deployment remains ineligible and existing sites remain unchanged.

## Production and remaining connections

The existing Production batch automation still uses its legacy signed test-attestation gate. The new central evidence is meant to supersede an unnecessary **per-company Staging** step, **not** to bypass Production's existing authorization. An exact-artifact Production dispatcher with backup, rollback and independently verified readback must be connected before deployment. The production deployment executor may be a managed VPS agent or local Git executor, but it must not treat a file's mere presence in the journal as installation permission.

After central evidence and company deployments are operating and observed, existing per-company Staging instances may be retired individually. Do not delete or disable them as part of this PR.

## Present state

- Signed readback **consumer and public read-only endpoint**: implemented in source and hermetically tested.
- Host signer, independent Staging test receipt producer, issuer key provisioning and journal population: **not connected**.
- Production installer integration and source transfer: **not connected**.
- No change to live Staging or Production was performed by this PR.
