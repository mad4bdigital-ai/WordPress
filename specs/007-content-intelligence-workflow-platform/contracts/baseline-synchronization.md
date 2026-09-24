# Contract — Baseline Synchronization and Runtime Release Identity

Contract: mad4b.feature-baseline-sync.v2

## Purpose

Separate two identities that must never be conflated:

1. the repository parent reviewed by a Feature 007 pull request;
2. the deployable runtime release established by externally trusted package evidence.

A repository-only merge may advance `master` without changing runtime bytes. A runtime-affecting merge invalidates the deployable runtime-release selection until a new trusted package is recaptured.

## Repository baseline identity

Committed Feature metadata records:
- `baseline_branch`;
- historical `baseline_head_at_creation`;
- `last_reviewed_master_parent_sha`;
- `baseline_checked_at`.

`last_reviewed_master_parent_sha` is the exact PR base reviewed by the Feature branch. It is intentionally not described as “current master HEAD”, because a committed file cannot safely self-embed the SHA of the merge commit that contains it.

Current repository HEAD is external Git evidence.

## Runtime release identity

Feature metadata separately records a `runtime_release_identity`:
- source commit SHA;
- Control Plane version;
- build fingerprint;
- package manifest digest;
- canonical archive SHA-256;
- package workflow/artifact refs;
- state: `CURRENT_FOR_DEPLOYMENT` or `RECAPTURE_REQUIRED`.

Runtime release identity is exact and externally attested.

Repository HEAD equality is not required when all commits since the selected runtime release are proven non-runtime under the workflow-owned runtime path set.

## Hard gate

On every pull-request run:

1. obtain exact PR base SHA;
2. prove it is an ancestor of Feature HEAD;
3. require `last_reviewed_master_parent_sha == PR base SHA`;
4. prove runtime-release source SHA is an ancestor of the PR base;
5. compute runtime-path changes from runtime-release SHA → PR base and PR base → Feature HEAD;
6. if any runtime change exists, `CURRENT_FOR_DEPLOYMENT` is invalid and state MUST be `RECAPTURE_REQUIRED`;
7. if `RECAPTURE_REQUIRED` is set without any runtime delta, fail as contradictory metadata;
8. do not treat specification consistency as live-runtime compatibility.

## Runtime identity path policy

The workflow owns the runtime identity path set. Mutable Feature metadata mirrors it but cannot silently broaden or narrow the meaning of a runtime-affecting change.

At minimum this includes:
- Control Plane runtime/plugin bytes;
- MCP Adapter package;
- packaged WordPress runtime dependencies;
- build inputs that materially change the runtime package identity.

## Semantic review

When the PR base advances in architecture-sensitive paths, synchronization requires impact review.

Sensitive families include:
- OAuth/authority;
- grants/approvals;
- provider certification;
- MCP transport;
- schema/durable execution;
- runtime provenance;
- recovery;
- workflow providers;
- Feature 007 runtime services.

## Synchronization

Preferred repository synchronization:
- real merge ancestry preserving exact reviewed base;
- update `last_reviewed_master_parent_sha`;
- rerun specification/consistency/provider workflows;
- update affected references when semantics change.

Runtime release synchronization is separate:
- if runtime paths changed, keep `RECAPTURE_REQUIRED`;
- after governed merge, let the protected master package workflow build/attest exact bytes;
- record the new trusted runtime-release identity in a later evidence-only synchronization;
- never relabel an older artifact as a newer repository commit.

Copying files without ancestry does not satisfy repository synchronization. Version-label equality does not satisfy runtime identity.

## Freeze

A Critical Kernel live slice binds to one exact selected runtime-release identity and one exact live target. Repository-only descendants remain review provenance, not fabricated runtime provenance.
