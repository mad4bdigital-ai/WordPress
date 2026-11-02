# External WordPress Native Executor — 306/312/313

## Current state

The original Central broker's remote-target discovery in 306 now follows
`MAD4B_Broker_Exact_SSH_Authority_V1::TARGETS` from 312. The broker's
separate owner-authenticated original grant, per-job claim, expiry, site and
transport checks remain mandatory. The local broker host is excluded from
external target discovery.

Live Central Staging PHP lint and 8 contract assertions passed. Both
Hostinger Staging targets also passed real, pinned Cross-SSH, read-only WP-CLI:
independently read `home` and an exact signed Site Profile identifier,
matching original Central 305 and Signed Host Proof for each target. The
reusable `read_only_precert.py` emits a root-only 0600 diagnostic report,
and its 7 isolated tests reject missing grants, site drift, Production
write authorization, and identity mismatches. **This is not an Original
306 Job and does not certify Full WP-CLI.**

## Remaining authoritative workflow

1. An original authenticated company-owner 305 command request with
   `wpcli.argv.json.v1` and a unique per-job correlation must be created
   and dispatched to 306. No Job is fabricated or auto-approved.
2. 306 claim and 312 independently verify the active Job, original persistent
   company grant, same exact host and site identity, signed host proof, actor,
   command digest, expiration and per-correlation consumption.
3. A transport-aware Native Executor must execute only that exact approved
   argv on the target selected from the root-owned company Host Scope and
   pinned SSH registry. The currently enrolled 305 broker target ID
   `srv2030908` is the signing/dispatch VPS, **not** the physical target
   Hostinger SSH endpoint.
4. 313 `wpcli-receipt` intentionally stores
   `PENDING_INDEPENDENT_NATIVE_READBACK`, never
   `native_executor_certified=true`. A separate native finisher must
   attest bounded actual execution, verify a fresh independent target-side
   readback and replay/rollback guarantees, then write an original protected
   receipt tied to the immutable Job and grant.
5. Certify at least one harmless read-only Job per company, followed by a
   reversible staging-only write and rollback acceptance, before asserting
   `full_wpcli_active`.

Do not upgrade a signed Host Proof or an SSH read-only canary to
`306/312/313` execution certification. No Production changes.
