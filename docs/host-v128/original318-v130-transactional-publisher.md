# Host v130 — Original318 v3 exact-group transactional publication

Source-only Root library: exact_staging_publisher.py

This code is not an exposed Host Runner command, WordPress REST endpoint,
MCP Write method or automated timer. It cannot execute without first-party
trusted signers for Host v3 enrollment, standing Write, current Owner grant,
Original313 high-water, signed Desired and the exact operation.

## Exact current scope

Supports only the pair of additive Original318 v3 WordPress MU files:

- 410-mad4b-original318-v3-readback.php
- class-mad4b-original318-host-v3-reader.php

The loader includes a pinned hash of its reader file. This intentional
fail-closed dependency prevents activating an incomplete pair.

## Before any mutation

1. Verify Original305 signed standing-Write entitlement and live independent
   313 high-water. The entitlement is indefinite but revocable.
2. Verify the Original312 v3 persistent enrollment, Original318 signed
   readback, exact signed per-operation grant, and fresh activity heartbeat.
3. Verify an independently signed atomic-group plan and independently
   signed actual preimage backup. No unsigned booleans are accepted.
4. Reconcile a separate signed Desired from the Root-private approved source
   registry against exact candidate artifact hashes and the pinned Git SHA.
5. Independently read Current with Host Runner's pinned public key.
   Actual code must exactly match the signed Current preimage.
6. Create another Root-private emergency backup, preserving existing
   bytes or explicitly marking previously absent files.
7. Obtain a fresh second signed Current with a new nonce, identical file
   hashes, immediately before the commit attempt.

## Bounded write and automatic recovery

- Only verified MU pair file paths, each max 2 MiB.
- Open files through directory descriptors with O_NOFOLLOW to avoid
  symlink/path escape and detect concurrent changes.
- Lock the first-party publisher with an exclusive private flock, stage
  bytes in hidden temporary files, flush, then replace each file.
- No general-purpose filesystem edits, arbitrary shell, database mutation
  or WordPress role changes.
- The installer must get a passing first-party WordPress boot/health test,
  then an independent freshly signed postwrite Current with expected bytes.
- Any failed check after mutation automatically restores the exact
  previous states and verifies a fresh signed rollback Current.
- If a separate writer changed files during recovery, do not blindly
  overwrite that change: mark RECOVERY_REQUIRED instead.

The actual Group update is a bounded two-file transaction with automatic
recovery, not a single filesystem-atomic swap across two separate paths.
Other site code families require an independently designed runtime
quiescence/locking protocol before equivalent activation.

## Live status

Tours.dev Staging already has these two additional PHP files installed,
with a saved absence preimage and signed Current verifying their hashes.
That existing installation was a separately authorized manual source
operation and is NOT evidence that the v130 automatic publisher has been
enabled.

The real Original305 v3 Owner signature, Original312 v3 enrollment,
independent Original313 high-water and first-party standing Write/Desired
approvals are still not present as a verified set. The source module
cannot and does not generate them.

The only active Host Runner profile still has
allowed_operations=[site.code.current.read]. There is no Host Write runner
endpoint here. Production remains untouched.
