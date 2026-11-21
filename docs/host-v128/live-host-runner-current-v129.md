# Host Runner v129 — signed live Current read (Staging only)

`site.code.current.read` extends the **existing** authenticated Host Runner
with one new **read-only** operation. It never writes WordPress files, never
issues a Host Grant, and has no generic shell or network capability.

## Required exact Host profile admission

The operation is unavailable until the *registered* site Host Runner profile
explicitly adds `site.code.current.read` to `allowed_operations` and
repins the new Runner source SHA/executor fingerprint. An old unmodified
profile must fail closed after a Runner source update. The host-private
Ed25519 key and pinned public key already supported by the Runner are
reused; **no new key generation is required**.

The new required `live_current_binding` is provisioned as first-party
configuration, **never from job input**:

```json
{
  "company": "company-slug",
  "environment": "staging",
  "site_uuid": "<exact 36-character site UUID>",
  "target_host_id": "registered-host-id",
  "host_key_sha256": "<64 lowercase hex characters>",
  "policy_sha256": "<64 lowercase hex characters>",
  "origin": "https://enrolled-staging.example.com"
}
```

Use actual per-site values from the enrolled registry, not the placeholders
shown above. The private key stays outside the WordPress document root
with owner-only permissions. No Host key, registry identity, Owner
approval, or site UUID may be copied across companies.

## Signed Current snapshot

The authenticated job input is an exact, sorted and unique list of up to
48 **allowlisted MAD4B code paths**:

```json
{"paths":["wp-content/mu-plugins/410-mad4b-original318-v3-readback.php",
          "wp-content/mu-plugins/class-mad4b-original318-host-v3-reader.php"]}
```

Every path is opened relative to the enrolled WordPress root using
`openat` and `O_NOFOLLOW` for each component; symlinks, traversal,
wp-config, uploads, secrets and other vendors' plugins are refused.
Existing files are bounded to 8 MiB each, with a 32 MiB total budget.
The file set is hashed twice to catch concurrent updates. Missing files
are explicitly marked `ABSENT`; they are not assumed present from Git.

The resulting `signed_current` record follows
`mad4b.host-v128.live-current-source.v1`, using canonical
`MAD4B_HOST_LIVE_CURRENT_SOURCE_V1\n` Ed25519 domain separation. The
independently pinned key is verified by `verify_current_receipt` and
then used to derive `Current` for the v129 three-way reconciliation
engine. A source repository candidate is still separate from Current.

## Live acceptance / rollback

1. Snapshot the registered Runner executable, profile and site binding.
2. Verify source SHA and key identity on the actual Staging host, not a
   copied ETG/ARE directory.
3. Admit the new read-only operation through the existing profile
   enrollment path with a new executor fingerprint and registered site
   binding. Do not widen existing write grants.
4. Execute an authenticated signed read job on the **same physical site**;
   verify the signed snapshot with the independently pinned public key.
5. Compare live hashes to the exact paths on that site, then re-read to
   establish a fresh Current. Confirm no write occurred.
6. Revert Runner/profile to prior snapshot if any identity/certificate or
   WordPress readback check fails; do not change the site's current code.

**Not yet claimed:** this source PR does not install the new Runner/profile
or create a live signed site receipt. The actual v3 desired-registry
writer and v129 atomic-group deploy executor remain separate guarded
tasks. Production is not enrolled by this operation.
