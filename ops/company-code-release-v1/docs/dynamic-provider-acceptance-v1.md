# Company Code Release — dynamic original-provider capability acceptance

Implementation: live VPS source worktree, not installed Original 313.
Extends the existing company.code.release plan/apply/rollback/readback;
does not create a new execution authority or accept commands from Git.

## Ownership and trust boundaries

- Immutable Git catalog defines WHAT capabilities and exact target files require
  functional acceptance. Source cannot introduce executable code, PHP, shell,
  URLs, arbitrary test callbacks or trust keys.
- Root-owned existing Host release policy enrolls named provider identities,
  allowed capabilities and Ed25519 verification keys. Source and tenant cannot
  authorise their own provider keys.
- Each release plan binds catalog digest, catalog path, coverage mode,
  selected checks and a fingerprint of the active provider enrollment.
- Default for enrolled policies is full selected-file coverage. Uncovered
  files, duplicate checks or unknown providers block plan generation.
- A provider must independently observe the runtime, then write a root-private
  signed evidence document for the plan SHA, Git commit, Site UUID, Apply
  nonce, catalog digest, provider and required check IDs.
- The Host verifies this evidence AFTER the real Apply and WordPress acceptance.
  Absent, stale, failing or unsigned evidence is an Apply failure, subject to
  original CAS-protected automatic rollback and incident journal.
- Independent Original 313 readback independently verifies it again before
  finalization. A Host receipt is not an Original 313 certificate.
- Capability acceptance cannot issue Broker grants, override 306/312/313,
  switch environments or mutate WordPress DB/content directly.

## Growth without hardcoded operations

For a new Provider, enroll its operation via the existing first-party Dynamic
Provider Registry and register its verification key and capability inventory
under the protected policy's optional capability_acceptance section.
The immutable catalog maps checked capabilities to relevant target files.
No hardcoded list of Providers is added to the release engine.
The historical wp_boot and copy_coverage_readiness paths remain only for
backward compatibility while new business acceptance uses Provider contracts.

The Provider-specific read-only evidence PRODUCER must still be implemented,
signed and enrolled through its original lifecycle. Catalog entry alone
does not prove a live producer. Until producers are present, a configured
dynamic release FAILS CLOSED and performs the original guarded rollback.

## Unchanged production boundaries

- No writes to installed Original 313, Broker grants, WordPress files/DB,
  company site ownership or installed dynamic registry from this worktree.
- The first-party Source Publisher remains required. Directly copying files
  into enrolled runtime breaks the Registry's pinned dependency SHA contracts.
- Per-file atomic writes are NOT whole-plugin atomic cutovers.
- www-data-writable PHP code and external concurrent writers need separate
  approved permission migration and HTTP/OPcache cutover tests.
- mad4b-tenant-sync preimage failures remain explicit unresolved release gates.
