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

## Tool and runner supply chain

Executables used by the Tool Execution Plane are supply-chain dependencies.

Where material, executor certification records:
- executable/package source;
- exact version;
- content hash/signature;
- resolved executable path;
- file owner/permissions;
- package provenance;
- dependency/runtime fingerprint.

Process launch MUST NOT trust an unqualified mutable `PATH` lookup when executable substitution would cross a privilege boundary.

Host Runner/Recovery Runner packages are built, distributed and verified like privileged control-plane artifacts.

Package/artifact inputs to host operations are content-addressed and verified before staging/apply. A URL or filename alone is not artifact identity.

Provider CLI updates invalidate affected executor certification until semantic mapping and security probes pass.

Secrets:
- remain in dedicated credential bindings;
- are not embedded in runner packages;
- are not serialized into plans/jobs/receipts;
- are not exposed through process listings or logs where avoidable.
