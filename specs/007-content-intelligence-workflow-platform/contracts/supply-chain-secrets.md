# Contract — Supply Chain and Secrets

Contract: mad4b.supply-chain-secrets.v1

## Executable artifact provenance

For MAD4B packages, WorkflowProviders, MCP Adapter and other executable provider packages record where available:
- source/release locator;
- exact version metadata;
- archive SHA-256;
- extracted tree SHA-256;
- critical-file hashes;
- build/source commit identity;
- package/dependency lock metadata;
- signature/attestation if upstream supplies one;
- acquisition timestamp.

## Source policy

Provider/package sources are allowlisted by policy.
Unexpected update source or package digest drift causes quarantine/review.

## SBOM/dependencies

Release process SHOULD produce or preserve an SBOM/dependency inventory for executable packages.

Known vulnerability/advisory impact is mapped to affected capabilities, not ignored because functional tests pass.

## Safe extraction

Package install/extraction rejects:
- path traversal;
- absolute paths;
- escaping symlinks;
- unexpected executable locations where policy forbids them.

## Secret storage

Secrets are never stored in ordinary artifacts, logs, prompts, provider status or Git.

Credentials have:
- owner/provider;
- environment;
- scope;
- creation/rotation time;
- last-used metadata when available;
- revocation path.

## Least privilege

Provider credentials are scoped to the minimum APIs/resources required.

A ContentJob never carries raw provider credentials.

Workflow definitions do not embed reusable platform secrets when a managed credential reference is available.

## Rotation

Credential rotation is independent of job/artifact identity.
Jobs reference credential binding IDs, not secret values.

## Redaction

Logs/evidence have structured redaction before persistence.
Redaction failure for sensitive fields is a hard evidence-persistence blocker where leakage risk exists.
