# Host Authority v128 — Original312 / 313 / 318 additive integration

## Implemented source layers

- `original312_v3_issuer.py` signs a brand-new v3 enrollment **only** after
  separate signed Original305 Owner approval, fresh independently signed
  Original313 predecessor evidence, exact site/host identity, and monotonic
  revision+1. It does **not** call the Root signer or persist anything itself.
- `original313_v3_checkpoint.py` creates a fresh independent predecessor
  witness and post-install high-water checkpoint using a pinned Root reader.
  The live reader must prove the original312-signed enrollment is actually
  installed in Original318, and the current Root ledger moved to the new
  revision. No Owner/Host token is generated or inferred.
- `class-mad4b-original318-host-v3-reader.php` reads a v3 enrollment and
  a current Original313 checkpoint with **real independent Ed25519 verification**,
  exact company/env/site/host/key binding, 30-second evidence freshness and
  nil write authority. It never considers a v2 Anchor current.
- `410-mad4b-original318-v3-readback.php` is an **additive read-only**
  WordPress CLI connector. It cannot issue, renew, modify, revoke or commit a
  Host grant. The sibling PHP reader's SHA-256 is pinned in the loader, which
  fails closed when a deployment is partial or the source hash changes.
  It deliberately has **no REST, AJAX or generic write exposure**.

## First-party runtime requirements (not auto-provisioned)

The following must be supplied by the registered first-party site installer,
not copied from another company or accepted from a caller:

1. A certified `MAD4B_HOST_V3_PINNED_BINDINGS` mapping
   `company:environment` to that site's exact company/environment UUID,
   host ID, SSH principal, public Host key hash, policy hash, and origin.
2. Current Original312 and independent Original313 public keys in
   `MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64` and
   `MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64`, provisioned from signed
   original Root registry with correct issuer provenance. No private key in WP.
3. A verified WordPress company registry and exactly matching live Site
   Profile. The fallback is **MISSING_OR_INVALID**, not silent acceptance.
4. A first-party registered Original305 Owner Request for
   `company.host-authority.enrollment.v3.issue`. The new Owner proof must be
   bound to the exact proposed v3 enrollment, predecessor and source HEAD.
5. Original313 prestate from independent Root ledger, **not** the WP option,
   even when that option contains a valid historic expired v2 signature.
6. An approved and pinned Original306/328 execution pathway to install
   signed v3 enrollment, advance the independent revision, publish signed
   checkpoint and verify both post-write readbacks. All steps need bounded
   recovery and independent confirmation. A manually generated JSON object
   cannot be a substitute for Root signatures.
7. A current signed checkpoint refresh mechanism. **Persistent** means the
   enrollment itself has no calendar expiry; operations must still use fresh
   current Root evidence. If refresh is missed, trust is not silently extended.

## Exact manual Staging rollout sequence

1. Select a trusted fleet site. Confirm its real WordPress root, UUID, host
   key, independent Root key identities and existing v2/v3 high-water.
2. Prepare all files under an isolated staging candidate. Compute hashes and
   copy existing files/options to an immutable rollback snapshot; preserve
   Original318 v2 and existing Owner UI unchanged.
3. Install both read-only PHP files **atomically** into that site's MU root,
   with the exact root-only binding constants. Check `php -l` on the target
   PHP version before enabling. An invalid or missing dependency does not
   break the site, because the loader refuses activation.
4. Query `wp mad4b-host-v3 current --company=<exact> --environment=staging`.
   Expected before signed issuance: `MISSING_OR_INVALID`, no Host Write.
5. Obtain a **new** Owner-approved v3 enrollment. Never change expiration
   fields inside an already signed historical v2 Revision 1. Use a newer
   monotonic revision and fresh signed predecessor witness.
6. Publish the signed enrollment with authorized Root transport, validate
   its installed-only proof, advance Root ledger, sign independent
   Original313 checkpoint and insert via exact bounded apply.
7. Verify fresh Original318 v3 readback, independent Original313 high-water,
   and `write_authorized=false`. Then test separately signed operation
   grants, long-running heartbeats, terminal closure, revocation, collision
   and replay denial, Site Profile drift, and failed-transaction recovery.
8. If a postcondition fails, roll back only the newly installed v3 files
   and options with recorded preimage equality; do **not** reactivate v2.
   Production stays untouched without its own explicit approval.

## Rollout/activation truth

This integration source is not automatically executed by Git, the Root runner,
WordPress or the registered Host Bridge. Original312/313 historical Root
signers and Original318 v2 remain active until an approved first-party
installation and freshly signed v3 enrollment are completed per site.
`release_qualification=optional` relaxes manual Staging **artifact release
certification** only; not signed issuance, site identity, owner approval,
independent Root verification, or write authorization.
