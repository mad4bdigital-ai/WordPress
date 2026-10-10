# MAD4B single-source dynamic Host identity (PR #258)

**Status: code paths implemented; host-service enrollment and real Staging acceptance NOT yet certified.**
This is a non-authorizing alternative to manually provisioning a second
`MAD4B_SCP_DEPLOYMENT_BINDING` secret. It does NOT bypass any old Host
operation that still requires a legacy HMAC binding.

## Design: one root for each fact, never copies of a root

- WordPress **Site Profile** is the canonical site policy and exact origin/revision.
- The **existing, separately enrolled Host Runner** is the sole trusted source
  for physical Host identity. Reuse its existing private Ed25519 signing key,
  public key already pinned as `MAD4B_SCP_HOST_ENVIRONMENT_RECEIPT_PUBLIC_KEY_B64`,
  its enrolled runner profile/target fingerprint, and its private OS identity.
- The local Unix socket is a **transport**, not a source of truth or a second
  identity registry. No secret is generated/stored inside the WordPress DB,
  GitHub, chat, media library, cloned Site Profile, or MCP responses.
- A separate private key is NOT created. If the existing Host Runner has no
  enrolled signer, this mode fails closed; a Host operator must complete the
  original Host Runner key enrollment, not invent a WordPress fallback.
- The signed challenge includes the **actual installed WordPress physical target
  fingerprint** computed by the same canonical Host Bridge payload as the
  existing Host Runner enrollment: site UUID, effective environment, real
  WordPress root and current wp-config SHA-256. Host Runner refuses to sign
  another fingerprint. This closes the same-URL copied-profile/different-root
  case without inventing a second identity registry.
- Multiple simultaneously authoritative roots are denied. When both the legacy
  deployment secret and live signed proof are present, Autopilot shows
  `blocked_multiple_host_identity_roots`; an operator must migrate rather than
  silently pick a winner.

## Live request flow

1. WordPress sees exact, explicitly configured **Staging** with matching origin
   and Site Profile. It creates a cryptographic 32-byte nonce for this request.
2. WordPress connects to **only** the Host-enrolled Unix socket named by
   server-owned `MAD4B_SCP_HOST_IDENTITY_SOCKET`. Only paths
   `/run/mad4b-host-runner/<name>.sock` (or `/var/run` equivalent) are accepted.
   A configured broken socket never falls back to a second provider. The
   compatibility Host adapter is used only when no socket is configured, and
   must return a signature from the **same** enrolled Host signing root.
3. The signer runs as a separate non-root OS user. Unix `SO_PEERCRED` must
   identify the exact non-root WordPress worker UID determined from the enrolled
   WordPress root; no root/foreign users. The private signing key is owned by
   the signer OS user, outside WordPress, mode 0600 or stricter. The socket
   directory is owned by the signer and is not group/world writable. Socket
   permissions are 0660 (or stricter), with carefully enrolled shared group
   membership allowing the exact WordPress worker to connect.
4. The Host Runner signs only the fixed `mad4b.host-identity-challenge-proof.v1`
   data: SHA-256(nonce), enrolled site UUID, exact HTTPS origin, Staging,
   current profile revision/digest, enrolled runner ID and physical Host target
   fingerprint, issued/expires timestamps. Expiration is 30 seconds.
5. WordPress independently verifies Ed25519 with the **already pinned public
   key**, fresh nonce, exact Site Profile/WordPress environment, no extra fields,
   and timing. It does not store the reply or elevate any permission.
6. The read-only `mad4b/site-autopilot-status` reports
   `host_identity.state=fresh_host_identity_verified` only for a current
   verified signature. Missing socket, untrusted key, unavailable signer,
   replay, clone, mismatched profile/host or stale proof remain blocked.

## Staging Host service bootstrap (separate authorized OS operation)

On a **real isolated Linux Staging host** with an already enrolled Host Runner
profile and Ed25519 signer, use a service manager under the distinct non-root
signer identity. Provision `/run/mad4b-host-runner` with signer ownership and
permissions 0750 or stricter. Configure a dedicated group allowing the
WordPress PHP pool UID access to the socket, but never to the signing key.

Host operator service command example (adjust secure profile path to this
*already enrolled* Host Runner; do not paste secret contents):

```bash
python3 tools/mad4b_host_runner.py serve-identity \
  --profile /HOST-PRIVATE/EXISTING-STAGING-RUNNER-PROFILE.json \
  --socket /run/mad4b-host-runner/identity.sock
```

In Host bootstrap configuration (not a WordPress option) pin:

```php
define( 'MAD4B_SCP_HOST_IDENTITY_SOCKET', '/run/mad4b-host-runner/identity.sock' );
```

The existing `MAD4B_SCP_HOST_ENVIRONMENT_RECEIPT_PUBLIC_KEY_B64` must already
contain the enrolled public key only. Do not generate a new identity secret or
copy a Production signer. Keep socket activation, PHP pool ownership, service
lifetime and deployment isolation under Host governance. On shared hosting
without a separate non-root runner/Unix peer isolation, this mode is
**unsupported**, not a reason to weaken the peer check.

## Compatibility and migration (not automatically authorized)

The older `MAD4B_SCP_DEPLOYMENT_BINDING`-based Host Bridge and package update
flows still rely on HMAC proof and a recorded binding digest. A fresh Host
signature cannot silently replace this HMAC value: doing so would weaken the
existing approval and clone safeguards.

Consequently, Autopilot reports
`fresh_host_identity_verified_legacy_consumers_pending` with action
`migrate_legacy_host_operations_to_signed_proof` until those exact consumers
accept the new protocol using the **same enrolled Host Runner signer** and
independent native tests. No Developer, Breakglass, raw SQL, arbitrary shell,
Production promotion, selected-HEAD package update, or release acceptance is
implicitly allowed.

## Negative acceptance matrix

- Wrong/absent/expired signature, wrong nonce, replay, changed profile revision,
  changed origin, foreign/Production site, wrong Host signer, public key supplied
  by a caller, extra JSON fields, untrusted Host socket path, symlinks,
  group/world writable socket directory, root/foreign PHP peer, signer UID
  shared with WordPress, private key inside WordPress root or unsafe mode,
  missing sodium or cryptography, unsafe stale signer profile, and concurrent
  host identity roots: **FAIL CLOSED**.
- A successful PHP fixture or locally mocked Python signer is not live Host
  attestation, nor WordPress/MariaDB/browser/release acceptance.
- If the Staging artifact is on an older source HEAD, do not claim that this
  functionality is deployed just because the PR code exists.

The Host Runner source SHA changes when this feature is installed, so the existing profile's pinned `expected_runner_sha256` needs an independently reviewed exact-head update. Do not bypass the pin or let WordPress rewrite it. An existing Host Runner signer, its public trust pin and a distinct PHP pool UID are prerequisites, not automatically created by this source change.

## Read-only migration inventory and aligned WordPress semantics

`mad4b/host-identity-migration-plan` now returns a fail-closed, non-authorizing
inventory of legacy consumers, their original HMAC/job integrity boundaries,
whether a single Host signature has been freshly verified, and the next
migration step. Every migrated consumer currently remains
`new_signed_protocol_accepted=false`; obtaining a signed read proof alone
never creates an approved mutation plan.

WordPress `WP_ENVIRONMENT_TYPE=staging` observed explicitly with the exact
Staging Site Profile is independently reported as `host_aligned` even when
the old `MAD4B_SCP_DEPLOYMENT_BINDING` is missing. This is **an environment
observation only**, not host-operation authorization or clone-safe acceptance.
The exact separate `deployment_binding_configured`,
`same_origin_clone_protection`, `host_identity.verified`, and current
Write/Developer acceptance fields remain separately reported.

Autopilot now proposes discovering the **existing enrolled Host Runner signer**
when no legacy binding exists; it does not recommend a second secret by default.
If the trusted signer/socket is unavailable, it remains BLOCKED, not repaired
by falling back to a WordPress-stored credential.

## Remaining security limits

The physical install fingerprint + Unix peer isolation + private Host signing
key can distinguish separately rooted sites. It cannot prove arbitrary tenant
isolation when multiple sites intentionally share the same physical WordPress
root, exact wp-config, PHP worker UID and Host Unix permissions. Those shared
tenancy configurations require stronger Host enrollment / pool isolation and
are not certified by this protocol alone.

Host Runner signer/challenge source, PHP verifier, and read-only migration
inventory are implemented in PR #258; Host Runner/Unix socket activation,
signed write-intent migration, native runner acceptance and any deployment are
still independent acceptance tasks. CI fixtures are not a signed Host receipt.
