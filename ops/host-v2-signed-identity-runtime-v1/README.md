# Genuine first-party Host v2 identity signing: root-only, on demand

Root-owned stable VPS entrypoint:
/usr/local/libexec/mad4b-host-signed-identity-attest-v2.py

This retains the original Host-v55 identity admission behavior with one
additional check: the independently signed Host policy/current Central
principal ledger must be current before signing each identity proof.

For every company, the first-party operation checks the exact Central
Hostinger read-only router, exact pinned SSH Production Site Profile and
matching original Central profile digest, then uses the existing root-owned
pinned Broker Ed25519 key. WordPress independently verifies the signed
identity and records SIGNED_HOST_IDENTITY_READBACK_RECENT (up to one hour).

The proof is an identity-only Central record, not an authority anchor, an
Original305/328/312/313 Grant, a Production write, or a certificate.
Use this entrypoint on demand before approved operations rather than
creating repetitive hourly signers. Dry-run is explicitly supported.

18 Nov 2026: tested live on Egypt Tour Gates, Tourism Almallah and All Royal
Egypt Production. Each original Central WordPress readback showed verified
identity with remote_write_authorized=false.

The independent Host Authority v2 issue/revoke providers are not yet live.
Their draft handler explicitly refuses execution; do not falsely certify
an anchor or bypass the original publisher and signed operation boundary.
