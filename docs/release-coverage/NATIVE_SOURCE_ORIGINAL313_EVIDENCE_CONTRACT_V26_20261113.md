# V26 — Native Source evidence contract parity with first-party Original313

2026-11-13. Source-only, unsigned, without first-party Registry upgrade.

The existing first-party `MAD4B_Dynamic_WP_313_Readback_V1` explicitly
requires seven fields in **every** enrolled signed Dynamic evidence schema:
`result_status`, `output_sha256`, `expected_readback_sha256`,
`readback_sha256`, `grant_id`, `transport_id`, and `production_mutation`.
V25 source-code-release evidence missed `output_sha256` and would therefore
fail `approved_record()` regardless of successful 306/312 or a valid signature.

V26 adds `output_sha256` as an exact SHA-256 to the source manifest's evidence
schema and populates it in independent host readback from the same freshly
verified target content digest (not from an incoming flag). The file count and
postimage digests remain present. The manifest generator updates SHA pins.

An offline PHP fixture loads the original first-party Dynamic313 verifier and
WordPress reader, confirms strict schema compliance, rejects missing output,
wrong booleans, negative file count, malformed hashes, and wrong success
state; confirms the synthetic unapproved provider is still not operational.
No proof signature is emitted, no Central evidence is ingested, and no
Original313 finalization/production mutation occurs.

Next gate: first-party signed-source finalizer using the independently enrolled
Original313 signer/transport and exact original305/312 Job evidence. The
source schema fix is NOT itself an authorized finalizer or approval to enroll.
