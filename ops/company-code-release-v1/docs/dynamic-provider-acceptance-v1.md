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

## Deepening: per-release witness, installed-producer admission, and dispatch

The contract is now challenge-bound. After file postimage CAS and WordPress
bootstrap pass, the Host samples the exact installed file-SHA map and
generates a cryptographically unpredictable 256-bit challenge. The postimage
fingerprint includes exact plan SHA, native Site UUID, and file digests. An
evidence producer must attest the challenge, postimage SHA, nonce, current
catalog SHA, Git commit, Site UUID, exact check IDs, and its own enrolled
original operation ID with Ed25519. The private evidence file's **actual**
SHA-256 is recorded in the terminal Host receipt. Original 313 verifies
the same bytes, the same postimage and valid signature again independently.

The first-party root policy declares each eligible Provider's allowed
capabilities, public Ed25519 verification key, producer_operation_id, and
producer_manifest_sha256. The existing first-party Dynamic Registry must
resolve that exact operation with matching Provider identity and canonical
manifest hash BEFORE any WordPress file change. A disabled but unused Provider
does not interfere with unrelated company releases; a disabled selected
Provider cannot authorize a release.

**Important execution boundary:** Registration is not dispatch. The immutable
Original 313 Host handler must also supply a first-party, separately authorized
producer dispatcher. The generic release engine will refuse the plan with
CAPABILITY_ACCEPTANCE_PRODUCER_DISPATCH_NOT_ENROLLED before any file write if
the dispatcher is absent. The catalog must never specify Python or PHP
callbacks, WP-CLI argv, URLs or commands to run. Dispatch must be owned and
audited by the original native Producer adapter, not by tenant code.

The dispatcher contract receives the exact signed plan, original policy,
root-private receipt directory, Apply nonce, postimage verification timestamp,
fresh challenge and postimage fingerprint. It may only emit a Provider-signed
read-only evidence result through its authorized Host-side transport. A
WordPress user may not be given root receipt-directory write permission to
satisfy this contract. If dispatch or verification fails *after* the first
file write, original guarded rollback applies. An external concurrent write
during functional acceptance remains preserved as ROLLBACK_UNVERIFIED and
requires separate signed recovery.

Provider observation (e.g., Elementor, JetEngine, WPML, Booking, Cache)
remains with its own original Provider. This infrastructure verifies
authenticity/provenance and observed file version; it does not manufacture
actual business-semantic correctness. Those domain-specific acceptance
producers have not been enrolled into the installed registry yet.

### Acceptance before production cutover

1. First-party Provider producer implementation, independent read-only probe,
   and signed evidence authoring, enrolled through the original Registry.
2. Original 313 Host adapter supports the bounded authorized dispatcher and
   proof transfer into root-private receipts. Timeout/cancellation semantics
   must be verified under a held receipt lock before enabling the operation.
3. Separate first-party Source Publisher registration and immutable admission.
4. Site-specific HTTP/OPcache cutover, multilingual/commerce/browser parity,
   tenant writable-code migration and historical tenant-sync drift closure.

No grants, runtime enrollment, site files, or WordPress DB changed in this
source-only review.

## Exact original provider package closure

The source operation manifest at
ops/dynamic-operation-registry-v1/providers/company.code.release/manifest.json
is version 4, now pins capability_acceptance.py in addition to the existing
Executor, Host Handler, independent Verifier and first-party dependencies.
A regression test checks each source byte digest before a Provider package may
be considered installable. The **currently installed** root-only index and
manifest remain unchanged; first-party enrollment must generate and verify a
new index digest and full original 306/312/313 dependency closure. A source
manifest update does not create runtime authority.

## Follow-up hardening — source-first reconciliation and capacity

- First-party Dynamic Registry now returns the exact SHA-256 pinned by its
  root-owned index for each enrolled manifest, rather than an alternate hash
  of a reserialized JSON dictionary. No developer-selected provider name is
  coded into the acceptance engine. This requires first-party installed Registry
  update before enabling capability acceptance in the root policy.
- A new source-parity read-only audit reports any deleted or changed paths
  between the protected VPS Git commit and a candidate branch. The pinned
  preservation inventory includes the 14 files missing from the old PR #815
  plus reviewed security-guard changes, all with byte-level content digests.
  No central source overwrite is inferred from a green CI result.
- Per-Site or global root policy can set resource_limits.backup_json_bytes.
  This limit is signed into the release Plan, compared at execution, enforced
  *before the first target write*, and reused by recovery backup reads.
  Existing unsigned/legacy plans retain the historical 100 MB default.
- An optional require_impact_coverage policy gate enforces complete
  (target, Provider, capability) relationships from a declarative source
  Impact Graph. A single check for an affected file can no longer cover
  other required capabilities. The graph remains declarative, not proof that
  Elementor, WPML or JetEngine dependencies were discovered automatically.
- Optional require_witnesses enables Evidence v2 with read-only, byte-pinned,
  root-private, typed Observation Witnesses. First-party policies define each
  check's measurement types and exact expected values; the original
  independent readback rechecks the same signed proof and witness bytes.
  Evidence v1 stays compatible for existing un-enrolled plans.
- Original 313 Host handler passes an explicitly first-party supplied
  Provider dispatcher. It does not conjure one or accept executable callbacks
  from the release request. Until a genuine Provider transport is enrolled,
  dynamic releases are held before modifying WordPress files.

NOT IMPLEMENTED BY THESE SOURCE PATCHES: coherent HTTP/PHP-FPM+OPcache
cutover, a real installed producer transport with enforced subprocess timeout,
a signed 313 finalizer-completion certificate for the next overlapping plan,
an indexed cross-site durable ledger, PHP worker write-privilege migration,
or resolving the two stale tenant-sync source files. Production still requires
Original Source Publisher admission. Never bypass it by direct copying.

## Coherent-activation admission (source-only opt-in)

A company/site policy may declare activation_policy.require_coherent_cutover
as a boolean. The immutable release Plan pins the affected plugin/MU owners,
all selected files and whether PHP spans multiple target files. When coherent
activation is required for a multi-file PHP release, the existing file-level
writer denies BEFORE creating any Apply Intent or modifying WordPress.
Changing this policy after planning also invalidates the signed Plan.

This is a conservative HOLD, NOT an implemented atomic whole-plugin switch.
Real activation requires a first-party signed versioned-cutover Provider,
request-bound PHP-FPM/OPcache verification and a coherent rollback. The
root policy is currently unchanged; no Production cutover is claimed.

## Opt-in prior Original 313 finalization boundary

The Site Profile may set finalization_policy.require_prior_original313=true.
The choice is plan-pinned. Before any target backup or write, a new Apply
inspects prior Host APPLIED_VERIFIED receipts for exactly overlapping files
and matching native Site UUID. Host success is not an Original 313
certificate. Host self-certification is always rejected.

A first-party Original 313 signature-verifying bridge must verify each
exact prior Host receipt SHA and produce a tightly bound verified result.
An absent or mismatched bridge fails closed. No tenant callback, grant or
local policy may invent Original 313 authority. Disjoint Site/files do not
block unrelated releases.

This policy is not enabled in Production. Historical Host receipts in the
current ledger do not establish Original 313 certification. Activating
this policy before an original enrolled verifier exists will intentionally
block overlapping new releases; it does not turn historical Host receipts
into certificates.
