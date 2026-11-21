# Host v128 — `current` comes from the installed live site

## Source selection contract

The `current` variable is **not** `master`, a Git commit, an old snapshot,
an archive on the central VPS or an environment-name guess. It is a **fresh,
read-only, signed, exact-site Host Runner readback** of the files presently
installed by WordPress.

`candidate` is the proposed source delta (e.g. selected Git HEAD). A Git
commit or successful CI test must **never** update `current`.

The code now expresses this separation directly:

```python
current = read_current(
    trusted_site_binding, original_host_runner_reader,
    pinned_host_runner_public_key, expected_live_origin, now=now,
)
plan = plan_candidate(current, exact_site_candidate, trusted_site_binding)
# This is a read-only plan. An independently authorized Host Runner must
# obtain a FRESH pre-commit current readback and execute an exact approval.
# Only a fresh POST-commit site readback can change current:
current = confirm_new_current(
    current, exact_site_candidate, trusted_site_binding,
    original_host_runner_reader, pinned_host_runner_public_key,
    expected_live_origin, now=now,
)
```

The signer public key must be supplied by the first-party enrolled Host
profile, **not** by user input, Git data or WordPress options. The reader must
run at the actual registered WordPress root, hash each exact allowed code
file, and sign the canonical observation with its enrolled Host Runner
identity. Any lack of an authenticated readback fails closed. Nothing in
this source module signs a receipt or deploys files.

Every `current` is scoped to one immutable (company, environment, site UUID,
host ID, Host key hash, policy hash, live HTTPS origin) binding. The selected
current file set may include the additive v3 MU-plugin files or existing
`mad4b-*` plugin code; it excludes `wp-config.php`, uploads, other vendors'
plugins, private key material and arbitrary filesystem paths.

## Publishing with the live source as reference

1. Read **source site's** live `current` from its authorized Root/Host reader,
   not from Git. For MAD4B, the central source may be Tours.dev when its
   registered site identity and signed receipt verify.
2. Independently read **target site's** live `current`. Never reuse the
   source site's site UUID, approval, domain, policy or key on the target.
3. Compare reusable code fields against the target's current preimage;
   keep company configuration/data and site-specific extensions isolated.
4. Construct a targeted `candidate` with a declared exact source HEAD,
   relevant files, and the target site scope. Generate an exact preimage-
   pinned plan, with rollback evidence required.
5. Before any write, re-read the target current fingerprint through the
   authorized Host Runner. Reject stale or changed preimages.
6. Use only an approved, site-specific execution to apply the delta. A
   signed observation does **not** grant write or owner permissions.
7. Independently re-read the actual target after installation; update
   `current` **only** when its signed file hashes equal the expected candidate.
8. Close the operation with a separate exact execution receipt, or roll
   back using saved target preimages and verify the rollback readback.

## What was actually observed on 21 November 2026

A **read-only, local filesystem and WP-CLI observation** of
`https://tours.dev.mad4b.com` on the central VPS found its installed
`mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php`
with SHA-256
`76ba920416dec16db8f6f53c63bfa749a7eb6277dd5190cc6ce6c74ebb49fc23`
(49,380 bytes). This is useful evidence of the then-live `current`.
It is **not** the required signed Host Runner receipt. It must be refreshed
before any later operation. It does not establish All Royal Egypt Staging's
current files or allow publishing to that site.

## Activation limits

Source tests use ephemeral Ed25519 keys to verify the fail-closed
contracts. They are **not** signed operational evidence. The existing
Host Runner's `filesystem.hash.read` is read-only but its signed
multi-file `current` receipt is not yet wired to the v128 adapter.

Until the registered Host Runner emits and independently verifies that
receipt, the runtime must report `CURRENT_UNVERIFIED`; no fallback to a
Git checkout, unsigned snapshot, old revision, or raw SSH file copy.

The option to make *release artifact qualification* optional on manually
approved Staging flows never turns off signing, host identity, site-specific
write permission, rollback, or Production's separate authorization.
