# Contract — Privacy-Safe Content Addressing and Deduplication

Contract: mad4b.privacy-safe-content-addressing.v1

## Principle

Plain SHA-256 provides integrity, not confidentiality or authorization.

The platform MUST NOT treat a hash as a secret.

## Deduplication scope

Policy selects:
- NONE;
- SITE_SCOPED;
- TENANT_SCOPED;
- GLOBAL_PUBLIC_ONLY.

Sensitive/confidential/personal artifacts MUST NOT participate in cross-tenant existence-revealing global deduplication.

## Existence oracle

ArtifactStore APIs do not expose "does blob hash X exist globally?" across authorization boundaries.

## Scoped identifiers

For sensitive scoped deduplication, implementations MAY use:
- tenant/site namespace;
- keyed HMAC identity;
- encrypted object-store keys;
while retaining an internal integrity digest separately.

## Low-entropy data

Hashes of low-entropy sensitive values are treated as sensitive because dictionary/brute-force recovery may be possible.

## Encryption

Encryption policy is independent from content addressing.
Encryption keys/rotation and ciphertext storage cannot change logical Artifact identity semantics.

## Access

Possession of blob ID/hash never grants read access.
Authorization is evaluated through Artifact identity/site/tenant scope.

## Deletion

Cross-reference GC cannot reveal other tenant references to the requester.
