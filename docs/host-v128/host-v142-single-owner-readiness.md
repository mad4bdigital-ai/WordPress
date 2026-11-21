# Host v142 — Single Owner first-party readiness, read-only Root watchdog

## Requested authority model and restrictions

The sole MAD4B Owner has stated that one person controls final approval across
all MAD4B companies. This establishes a requested model, not a registered
independent Owner key or an Ed25519 approval. The existing Root
standing-write policy already uses Persistent as its default only after
the exact signed enrollment. Company/site/environment/job grants and
separate Production approval remain necessary.

The Owner has submitted a WordPress review. It must not be repeated while
the Root issuer awaits independently signed Original313 predecessor evidence.

Original313 current archive has one cryptographically verified v2 chain
belonging to All Royal Egypt; it cannot prove that Tours.dev never had a
previous revision. An absent record is not a signed revision-zero witness.

The November 18 Original305 ambiguous-write HOLD is preserved. Do not retry
the failed signing operation or infer signed receipt commitment from a log.

## Small implementation

Root-side source ops/host-authority-v142/single_owner_readiness.py verifies
the exact pinned, already installed v132 Original305 preflight and v134
independent cryptographic archive reader. It checks the v133 standby
manifest and the original Host Runner Root-private signed Current summary.

It produces six explicit Root trust checks and always reports
write_authorized=false, even after those conditions become true. Subsequent
Original312 issuance, Original318 independent readback and exact grants
must occur in their own already governed original first-party channels.

Mode --check reports JSON without writing. Mode --publish writes only the
fixed root-private latest.json, atomically with O_NOFOLLOW and fsync,
inside an exact root-owned 0700 directory; file mode 0600. The module
cannot sign, install a key, update WordPress, grant a privilege, or clear a
Root HOLD. Unexpected source changes, foreign scope or unsigned Current
make the monitor fail closed.

The tightly sandboxed systemd oneshot/timer refreshes every 30 minutes.
A normal missing predecessor is reported as a blocked state and does not
restart any signer or retry an Owner request. Operational faults cause
a nonzero journal-visible failure; no bypass or fallback to broad SSH.

## Installation and rollback

Install only exact Git-pinned sources into
/opt/mad4b-company-transport/host-authority-v142-single-owner-readiness-readonly,
plus two new exact-prefixed systemd units. Ensure source SHA match, read-only
helper pins, signed-current summary, no WordPress/Production mutation,
successful --check and private --publish, followed by systemd unit verification.
Enable the timer only if all tests pass.

Rollback disables only the v142 timer, removes only its two exact systemd units
and the new v142 diagnostic directory, and leaves all Original305/313 artifacts,
WordPress review records, signing identities and authorization grants untouched.

## Next human action

Once Original313 issues a genuinely signed offer and the independent Owner
identity has been enrolled through a first-party verified registration, the
Owner will sign the exact bound plan on their own device. Their private key
must not be sent over chat or stored on the VPS. A WordPress review alone
never authorizes Write.
