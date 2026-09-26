# Contract — Artifact Storage and Retrieval

Contract: mad4b.artifact-store.v1

## Purpose

Separate artifact metadata/lineage from potentially large immutable payloads and provide deduplication, lifecycle and retrieval without binding the domain to one storage product.

## Logical layers

Artifact Registry:
- identity;
- schema/version;
- lineage;
- payload hash;
- payload location/ref;
- classification;
- retention.

ArtifactStore:
- immutable blob write/read;
- existence/hash verification;
- tiering;
- deletion/tombstone;
- quota accounting.

RetrievalIndex:
- optional keyword/entity/semantic/metadata retrieval;
- never source of authority;
- rebuildable from authoritative metadata/blobs.

## Content addressing

Where practical:
blob_id = sha256(canonical bytes).

The same immutable payload may be referenced by multiple artifacts without duplicate storage.

Artifact identity and blob identity remain distinct.

## Storage classes

Policy may define:
- HOT;
- WARM;
- ARCHIVE;
- EXTERNAL_REFERENCE;
- TOMBSTONED.

Storage class does not change payload identity.

## Integrity

On read:
- verify length/hash according to policy;
- corrupted/missing blob marks dependent artifact unavailable/corrupt;
- retrieval index result must resolve back to an authoritative artifact/blob.

## Quotas

Track:
- logical artifact bytes;
- physical deduplicated bytes;
- tenant/site usage;
- artifact count;
- retrieval-index footprint.

Quota exhaustion is explicit and cannot silently discard evidence.

## Garbage collection

Blob may be physically deleted only when:
- no retained artifact references it;
- retention/legal hold permits;
- deletion plan records expected references;
- tombstone/evidence policy is satisfied.

## Search/retrieval

Retrieval may support:
- metadata filtering;
- lexical search;
- entity/topic search;
- semantic/vector search;
- hybrid ranking.

No particular vector database is required by the contract.

## Sensitive content

Storage backend, encryption, access and indexing follow data classification.
Secrets are not indexed.

## Portability

Store exposes export/import manifest with blob hashes and artifact mapping.
