# Host v128 — persistent connection by default, operation-bound execution

## Decision and precise scope

**Default for new v3 issuer input: `persistent`.** An enrolled host identity
has no calendar expiry (`expires_epoch: null`) and stays enrolled until
revocation, host-key rotation, Site Profile drift, or loss of independently
verified trust. This is **not** an unlimited write grant, an auto-approval,
a bypass of Original305/306/312/313/318, or a renewal of expired Revision 1.

`operation_scoped` remains available explicitly. Its connection is
**not active merely because a signed host enrollment exists**; it requires
the current independently verified, exact-scoped operation grant and signed
activity. Active work has **no absolute-duration clock**: signed progress can
continue for hours or days. It ends immediately on `COMPLETED`, `FAILED`,
`CANCELLED`, `REVOKED`, host revocation, key/profile drift, or missing
authenticated heartbeat longer than the granted idle budget (30–900 seconds).
The idle budget is a dead-worker safety mechanism, *not* the duration of
the entire operation.

## Why v2 cannot be silently changed

The old v2 first-Anchor issuance contains two independent clocks:
- Broker revision ledger: **45 seconds**.
- WordPress Anchor signed by Original312: **900 seconds**.

Both include time fields in signed material, enforced by distinct readers.
Changing either unsigned clock, declaring the old expired revision permanent,
or treating v127 historical Final Receipt as active authority would invalidate
the signed trust chain. The original v2 signed certificate remains historical.
A new v3 enrollment requires an authorized, independently signed issuance and
a newer monotonic revision/high-water readback.

## V3 source contract (implemented and testable)

`ops/host-authority-v128/persistent_enrollment.py`:
- `prepare_new_enrollment(..., mode='persistent')` creates **unsigned**
  issuer input only; never creates a signature or WordPress authority.
- `verify_connection(...)` checks exact Ed25519 Host issuer + independent
  current high-water signatures, the host-key binding, and the first-party
  Original318 current WordPress readback. Only persistent enrollment is a
  standalone connected identity; scoped enrollment stays pending.
- `verify_operation(...)` requires two additional independent signed
  artifacts: the exact operation grant and its latest activity. It binds
  action, company/environment/site, revision, nonce, job ID, activity
  sequence/digest, heartbeat, and an independently signed current checkpoint.
  On any change/terminal state, previous proof is rejected. It returns
  **admission verification**, never an executable generic Host Write grant.

Signed records use separate canonical domains, exact field shapes and
unambiguous `v3` contracts. Old v2/expired records cannot pass the v3 verifier.
All record signing is performed outside this verifier by pre-existing approved
first-party signers; no keys are stored in Git. This module is source-only,
not an installed WordPress MU plugin or a live Root issuer.

## Required controlled integration to make this LIVE

1. Preflight and snapshot the **actual** All Royal Egypt Staging filesystem on
   `hosting-2`; do not use the ETG site-copy path from the older v122 report.
   Pin release SHA, live Original318 PHP source digest, WordPress site UUID,
   Host site key, approved owner identity, and independent Root signers.
2. Add an **explicitly versioned v3** signer to Original312 and fresh
   Original313/high-water snapshots. The independent high-water witness must
   be obtained fresh on each authorization, without reissuing connection
   enrollment; never treat a cached witness as permanently authoritative.
3. Integrate Original318 current v3 readback and the atomic monotonic
   revision/operation activity store; read after write, and recheck after
   reservation before every execution. Terminal state and revocation must
   invalidate old snapshots; verify no other jobs can inherit the grant.
4. Wire an admin mode field with `persistent` as the **default on new
   enrollments**, and `operation_scoped` as the alternative. Existing
   v2/expired entries stay unchanged until an explicitly approved fresh
   enrollment and independent signed revision occurs.
5. Implement signed heartbeat updates from the actual executor only, with
   monotonic activity sequence and terminal reconciliation even after crashes.
   Do not interpret a missing receipt or transport failure as permission to
   retry an already armed host write.
6. Stage/rollback with exact-content backup of the existing Original318
   and owner UI files; prove v3 persistent readback, 30+ negative gates,
   no broad Host Write, dynamic long-running operation, terminal closure,
   offline fail-closed, and production unchanged.

**Activation truth:** source code, tests and a GitHub PR are not proof of
installation, signed enrollment, operational credentials, or live authority.

## Source tests

```bash
python -m pip install 'cryptography>=41,<48'
python -m unittest discover -s tests/host-v128 -p 'test_*.py' -v
python -m compileall -q ops/host-authority-v128
```

Ephemeral Ed25519 keys only. Never install unsigned v3 proofs on Production,
edit the original v2 signature, or extend the existing v2 expiry by policy.
