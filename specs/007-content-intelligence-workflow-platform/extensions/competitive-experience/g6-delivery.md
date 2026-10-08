# G6 repository delivery — AI, Knowledge, and Compiled Operations

PR #287 targets Integration Hub #258 at `51e1f08ad5aafbea1656a0325f1070b438586c98`.

G6 remains a **non-authorizing repository foundation**. Do not treat its tests as live provider, vector, Production or browser certification.

## Implemented in reviewed source

- CPT-aware typed operation DAG planner with exact job/revision/site/generation/permission/effect pins, native handoffs and redacted private review.
- Non-executing AI proposal and model routing with explicit privacy class, residency, cost ceiling and consent/certification blockers.
- Metadata-only licensed knowledge source admission and citation evaluation; no ingestion, embeddings, external document fetch or cross-site reads.
- Owner/site-scoped encrypted conversation vault with a **dedicated server-side 32-byte libsodium XChaCha20-Poly1305 key** and key identifier; optimistic revision/CAS, 1–30 day retention bounds, internal-only exact-revision owner export and live ciphertext tombstone deletion.
- **Capacity and non-resurrection:** 16 active conversations are allowed; deleted identifiers do not consume active slots. Up to 256 distinct identities are retained, including content-free tombstones. Deleted identities cannot be reused or silently evicted. Reaching 256 identities fails closed pending reviewed migration to a server-issued monotonic identity scheme. This is a bounded protection, not unlimited capacity.
- **Full-transcript integrity:** a separate server-keyed, domain-separated HMAC head authenticates the complete ordered ciphertext message list together with site, owner, classification, and expiration. Status, export, and append validate this head and catch suffix truncation and forged message counts. AEAD v3 protects individual message content and metadata.
- **Storage acceptance condition:** retrieval and writes must reject ambiguous WordPress `user_meta` rows and malformed CAS revisions; published readback of an in-record MAC is not an independent rollback anchor. The read-only status explicitly exposes `whole_registry_rollback_certified=false` and `tombstone_authenticity_certified=false` until an independently stored append-only checkpoint is certified.
- **Limitations:** a full rollback to an earlier valid *sealed* registry snapshot is not detected without an independent append-only monotonic anchor; migration of older unsealed messages, key rotation, scheduled purge and backup erasure require separate certification.
- Seven private Admin metadata/review abilities; **no REST/MCP ability exposes raw conversations or performs an append/export**. Restricted-data conversation storage, automated purge, key rotation and backup erasure are not yet certified. Missing keys or crypto fail closed.
- Read-only durable DAG record inspector: matches idempotency scope, request SHA, plan SHA, durable completed status, result digest, expiry, ContentJob revision and current runtime generation. It **does not** claim provider postconditions from a completed DB record. Dependent dispatch remains denied without current certified provider postcondition, owner approval and governance frames.
- Disposable WordPress Admin smoke, PHP 7.4/8.3 G6 tests and exact-file delivery digest integrity.

## External and end-to-end gates not yet fulfilled

1. Certify live AI account/consent/region/cost readbacks and uncertain-charge/revocation outcomes; no model is called by this branch.
2. Validate dedicated secret injection, storage encryption, key rotation/recovery, purge/backup retention and adversarial export/delete on authorized Staging.
3. Certify prompt library, encrypted conversation UI, tool-approval cancel/replay and rights-controlled media generation.
4. Certify licensed PDF extraction and live vector backend tenant isolation, source revocation, generation refresh and deletion readback.
5. Bind certified provider postcondition reads and fresh owner approvals to durable DAG completion, including compensation and rollback acceptance.
6. Run representative Staging browser/provider acceptance, Hub cumulative closure and exact-head CI before owner attestation and separate merge authorization.

None of the G6 task IDs are promoted to DONE by this document.
