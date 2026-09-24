# Contract — Local OAuth Key Lifecycle

Contract: mad4b.oauth-key-lifecycle.v1

## Keyring
Local authority supports:
- current
- next
- previous

Private key security requirements remain:
- outside web root
- restrictive file/directory modes
- no DB plaintext private key
- atomic creation/update
- public JWK-derived kid

## Rotation
1. create next key.
2. publish current + next public JWKs.
3. switch signer to next; old current becomes previous.
4. continue publishing previous during overlap.
5. wait at least max access-token TTL + clock skew + configured JWKS cache safety window.
6. retire previous.
7. persist audit/evidence.

## Failure handling
- failed next-key creation does not replace current.
- restart preserves keyring/kids.
- unknown kid triggers bounded controlled JWK refresh behavior on verifier side, then fail closed.
- rotation never creates broader scopes/resources.

## Status
Expose non-secret:
- current_kid
- next_kid optional
- previous_kid optional
- rotation_state
- last_rotated_at
- overlap_until
- key_store_health

No private key material is returned.
