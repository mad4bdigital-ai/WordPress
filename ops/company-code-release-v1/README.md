# MAD4B Company Code Release v1

General local-Git release lifecycle: Plan -> Prepare -> Apply -> Readback -> Rollback.

The source of truth is a reviewed full Git commit SHA; artifacts are read from immutable Git blobs, not mutable working-tree files. At execution, the original root-owned FAORE Native Operator dynamic resolver verifies the exact site profile, Coolify service and database, current PHP container, and Docker mount. The pinned plan must name the currently verified container: any container rotation requires a fresh Git plan and a new independent Broker grant. A stale static container alias in the host registry cannot be used as an identity shortcut. The release request identifies the company, environment, site UUID, exact target filenames and expected target preimage SHA256. The fixed root-owned policy constrains the source repository, company identities, runtime registry, Docker mount, destination files, and pinned Broker Ed25519 public key. The same policy pins one root-only receipt directory, preventing replay of a signed grant against an alternate ledger.

Plan is advisory and does not modify the site. Prepare stores a private persistent recovery backup without touching WordPress. Apply checks a separately issued signed Broker grant bound to the plan, action, company, environment, site UUID, expiry and nonce. It rechecks the native company runtime binding and container site mount, atomically installs files, performs hash readback and PHP lint, and automatically rolls back on failed verification.

Rollback requires a separate, valid Broker-signed action plus a successful previous Apply receipt, and refuses intervening edits. Receipts are independent host records: original_313_receipt remains false until the original company Broker issues its own 313 receipt.

No shell commands can be supplied in a release manifest. No WordPress database changes, publishing, pricing changes, user grants or broad file-system writes are authorized by this module.

## Company-agnostic behavior

The host policy maps an exact company:environment binding to target root, container, site UUID, canonical origin and explicit files. Staging, Production, QA and other registered environments use the SAME rules: there is no environment-name-based override or separate production_enabled switch. The release executor contains no Wayfatours-specific paths. Wayfatours is the first request fixture only.

## Capability ownership, not file retention (source/tenant parity)

`company_code_release.py` now calls the existing plan/confirm/apply/readback
path's **read-only** `capability_owner_snapshot` rather than using any protected
MU filename inventory. The snapshot is hashed into the immutable release plan;
`confirm_plan` rechecks it before an independently signed Apply and after write.
It neither changes the approved path catalog nor creates a grant.

The ownership graph is discovered dynamically from `mad4b-portability.json`
profiles (contract `mad4b.plugin-portability-profile.v1`) in **both**:

- the exact source Git commit's plugin manifests; and
- the enrolled tenant's current plugin manifests and MU/plugin file preimages.

`company_specific_legacy_mu_replacements` records describe a **capability**
transition: `legacy_file` -> `replacement`, scoped by the actual plugin
manifest and its reconcile contract. A legacy MU file is not protected forever:

- **Legacy owner still live:** continue bounded maintenance of the current
  legacy owner under the existing file CAS and signed Broker authority.
- **Canonical plugin already installed, MU physically retired:** updating the
  canonical module is permitted, subject to existing allowlist, signed grant,
  tenant binding, PHP/WordPress acceptance, and independent readback. The old
  file need not be restored to satisfy an inventory count.
- **Both files physically present:** file presence alone is **not** proof of
  runtime ownership. Replacing the plugin's owner module must stop until a
  separate original, read-only **runtime-owner and semantic-parity receipt**
  proves that the MU is not the active handler. The current generic file release
  deliberately cannot issue this receipt or migrate two active handlers.
- **Both owners in one ordinary file release:** fail closed. This executor does
  not implement atomic MU retirement / plugin activation as a filename rename.
- **Source-only migration metadata:** also applies to the tenant. Missing
  target portability metadata cannot silently hide a source MU->Plugin move.
- **Conflicting source and tenant owner mappings:** hold and reconcile the
  *capability*, not by overwriting either site or permanently pinning the MU.
- **Unrelated MU files:** not protected, blocked or auto-deleted by name/count.

A verified migration is a separate owner-lifecycle transaction: read current
hook/class owner, preserve live tenant settings and translated Elementor/JetEngine
conditions, compare behavioral contracts, check one active handler, then perform
an original signed tenant write, independent runtime readback, and rollback if
not equivalent. This code's snapshot is an admission fence, **not** a claim that
such a signed migration or full operational parity is already implemented.
It never changes the WordPress database; Elementor document writes remain with
the original native Elementor/CAS provider.

The owner index rejects unsafe paths, duplicate owners and unexpected profile
changes. Git Worktree changes cannot alter a pinned source migration, and a
concurrent MU reappearance invalidates an already approved canonical plugin
plan. The manifest's free-text `replacement_activation_check` is informational;
it is **not** executed or trusted as a runtime proof.

## Multiaxis release hardening (source-only, additive)

The canonical signed release path now checks more than a file SHA:

| Dimension | Release-time proof | Boundary |
|---|---|---|
| Functional ownership | Source-Git and tenant Portability Profile lineage, migration contract fingerprint, single-target owner graph | Does not infer active hooks or permit automatic MU retirement |
| Dynamic admission | Registered immutable catalog component → plugin or MU code/assets, plugin feature in tenant-portable profile | Requires unchanged per-company policy, signed Broker plan, exact source equals target for dynamic paths |
| Tenant state | Actual tenant portability profile SHA, plugin identity and current feature inventory | No imported source demo options, WPML settings or Elementor document writes |
| Dependency | Literal PHP `__DIR__` requires/includes resolved against the **target** runtime; missing includes denied before any write | Dynamic variable includes cannot be proven statically; mandatory post-apply WordPress boot remains |
| Concurrency | Target file preimages, non-target dependency SHA, source Git SHA, actual container/site binding and profile changes | Conflict means fresh plan, never silent overwrite |
| Readback | Executor-time original CAS plus independent file SHA, PHP lint, WordPress bootstrap | Native Elementor content must be handled by its separate original WP CAS provider |
| Incident & rollback | Protected backup, separate signed rollback grant, `ROLLBACK_UNVERIFIED` incident for concurrent edits | No success claim or nonce replay while rollback evidence is missing |
| Runtime owner observer | `runtime_owner_observer.php` observes loaded class origins and WordPress hook callbacks | Read-only, **not** an independent functional-parity certificate or signed migration grant |
| Regression automation | `.github/workflows/mad4b-capability-owner-release-contract.yml`, Python contract + PHP observer fixture | Host production rollout still requires original first-party enrollment |

### Dynamic catalog: portable native Plugins

Existing MU component catalog entries remain valid. A new native Plugin
component may declare `id`, `files` and `capabilities`, where each capability
must appear in that Plugin's **immutable Git** `mad4b-portability.json`.
The profile must identify the same plugin and explicitly require
`scope=tenant-portable`, `sensitive_central_authority_not_included=true`,
and `financial_or_customer_data_automatic_write=false`.
The catalog cannot bundle two Plugins into one component, duplicate a path,
include vendor/tests/uploads paths or redirect source blobs to another target.
No catalog entry on its own is write authority. A signed Broker grant,
original execution enrollment, host policy and precise CAS are still mandatory.
Source-only manifest review never means the tenant has already activated it.

### Observability and evidence

The read-only WordPress observer reports plugin and legacy file SHA,
actual `get_included_files()` membership, declared PHP class origins and
callback counts in the real `$wp_filter`. It distinguishes observed legacy,
canonical and dual-hook states, but does not execute a replacement, import
translations, inspect customer bookings or claim semantic parity.

A real Wayfatours readback observed 3 legacy hook callbacks vs 0 canonical
for the portable Options Bridge. Tours.dev's alias is implemented by the
native Plugin, without the legacy MU file. Neither state authorizes an
automatic owner transfer. The independent signed transition still needs
field/option parity, WPML group retention, one active handler, production
feature smoke tests and post-apply receipts.

Full release acceptance should run the immutable Git + tenant CAS contract,
actual Company runtime metadata, original 306/312/313 authority, Elementor and
JetEngine semantic reconciliation, native WPML coverage, asset-cache/cold-load
checks, independent browser behavior and an approved rollback. This release
module handles **code files only**; it must not duplicate Elementor/JetEngine
DB writers or claim their success.

For the production fail-safe journal, just-in-time CAS, prewrite syntax
verification, interrupted Apply/Rollback recovery, review dimensions and
operational limits, see
[`docs/transaction-journal-cas-audit-v1.md`](docs/transaction-journal-cas-audit-v1.md).

## Files

- company_code_release.py: host release planner, original-305/312/313-aware non-authorizing doctor and signed executor.
- company_lifecycle_broker.py: generic per-company read-only bridge to original enrolled WordPress 305, original 312 verifier and 313 registered handler inventory; the old staging diagnostic is not a production execution permission.
- original_313_code_release_contract.py: pure, source-only exact original 306 Job / 312 claim contract with immutable Git+plan command digest and independent host readback projection. It does not sign or dispatch anything.
- original_313_enrollment_gate.py: pure candidate metadata projection; even an all-true test fixture cannot add a digest to the running original worker. It requires separate first-party enrollment and independent 313 receipt handler.
- tests/test_original_313_contract.py and tests/test_original_313_enrollment.py: original claim, signature-scope and unenrolled-provider negatives.

### Original 305/306/312/313 integration

The running 306 worker binds allowed operations to a SHA-256 digest of an exact WordPress CLI argv array, not a generic executor name. The candidate operation uses fixed argv ["mad4b","company-code-release","apply","--plan_sha256=<exact-sha256>"]. The canonical command SHA is computed using the same compact JSON-array hash as the original worker, then bound to one immutable local Git plan and an enrolled company UUID.

Before activation, original first-party authority must register the exact operation and its 313 independent handler and verify the existing WordPress Broker dispatch Ability. It must also have a real eligible original 306 Job and 312 command-hash authorization, a pinned host release policy, and a separately signed plan-specific release grant. The current original 313 worker has no company.code.release handler, and neither this source code nor synthetic metadata can register it. Do not copy a patch directly into the live worker as a substitute for original enrollment, or claim an original 313 receipt from a local hash readback.

A local source-only test never changes production. The previously denied Wayfatours Production Validator deployment remains unchanged and awaits that actual operation enrollment.

- tests/test_release.py: hermetic release, rejection, backup and rollback tests.
- policy.example.json: NOT an installed or execution-authorized policy. Its Wayfatours PHP container is the exact dynamically verified snapshot as of 2026-11-08, not a permanent alias. Refresh it through the original resolver before any new plan.
- operation-catalog-candidate.json: pending Broker enrollment descriptor; not live.
- overlays/wayfatours/: reviewed first release candidate code, committed to local Git.
- requests/wayfatours-content-readiness.request.template.json: fill in the full immutable local commit SHA when planning.

## Commands

For offline testing:
    python3 -m unittest discover -s tests -v
    python3 company_code_release.py plan --request /private/request.json --policy /private/preview-policy.json
    python3 company_code_release.py doctor --plan /private/plan.json --policy /private/preview-policy.json

After independent authorized native company Broker enrollment, using the fixed root-owned policy at /etc/mad4b/company-code-release-policy.json and an exact Broker-issued grant:
    python3 company_code_release.py prepare --plan /private/plan.json --receipts /private/receipts
    python3 company_code_release.py apply --plan /private/plan.json --grant /private/grant.json --receipts /private/receipts
    python3 company_code_release.py readback --plan /private/plan.json
    python3 company_code_release.py rollback --plan /private/plan.json --grant /private/rollback-grant.json --receipts /private/receipts

Acceptance checks are an allowlisted set of exact runtime checks: wp_boot and copy_coverage_readiness. The Wayfatours source request requires both, including the live Copy Coverage producer hook, the Readiness discovery contract and the bounded shape of emitted Copy Coverage advisories; a failed check triggers rollback. Other unrelated Content Readiness findings may legitimately make the site's global complete flag false, and must not turn a correctly installed Copy Coverage adapter into a false deployment failure. They remain visible to normal Content Readiness diagnostics. An attacker cannot submit arbitrary shell commands or PHP as an acceptance hook.

The production CLI deliberately ignores user-supplied policy paths for mutations. It requires a fixed owner-protected policy and a separately signed grant, never issues its own grant, and does not accept a GitHub PR as production authorization.

## Current rollout boundary

This local Git branch implements the module, tests and release artifacts. It does not install the privileged host operator, register the native Broker Ability, install an authority policy, mint a signed grant or deploy Wayfatours Production. Earlier security denial remains binding. Catalog registration and independent 313 receipts are a separate required deployment gate. A Git commit or PR merge is not proof of running production functionality.


### Original 313 Host & Independent Readback — source candidate

The source-only module original_313_host_handler.py includes a fixed-plan host CLI for an ALREADY registered and original-312-verified Code Release Job. The production entrypoint re-verifies the LIVE original worker and 312 authority itself: callers cannot pass a boolean claiming enrollment or a fabricated 312 response. Before any execution it checks the real 306 worker digest table AND the original 313 executor operation choices, then verifies the exact company/site/origin identity, a real original 312 grant tied to the same job, an independently signed host release grant containing original_job_id, a root-owned pinned production policy, and a one-use private receipt ledger. It has no API to issue a Job or to enroll itself.

The source-only original_313_independent_readback.py separately checks the original 312 claim, the signed plan grant, exact pre/post release SHA-256, root-only host receipt, current runtime/Coolify binding, and WordPress acceptance. Its success state is HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313 — not a certified central 313 receipt. Original 313 WordPress finish/receipt endpoint still requires first-party enrollment and separately signed receipt evidence; it is NOT silently installed or implemented by a local readback.

The environment-neutral host policy at /etc/mad4b/company-code-release-policy.json, original worker handler, original provider dispatch Ability, independently signed 306/312 Job, and original 313 finish are NOT active for this operation. Do not invoke the candidate executor via SSH as a substitute for an enrollment refusal. All tests are fixture-based or local temporary-file tests; they are not a Production acceptance run.


### Environment-neutral Code Release lifecycle

The host release executor does not special-case staging, production or QA.
A valid exact company:environment entry is required, but labels do not
change eligibility. The original 305 enrollment, exact original 306 Job,
original 312 signed scope, independent broker release grant, unchanged Git
source, exact target preimage, pinned protected host policy, and original 313
receipt remain mandatory for every environment.

The existing generic MAD4B enrollment dispatcher remains Staging-only
and explicitly denies Production mutation for *all* of its other registered
operations. The environment-neutral Company Code Release must use its own
originally enrolled native Broker execution lane instead; this source
change is not a waiver of the dispatcher and does not install a new
Production authority or route around a previously denied release.

Tests cover staging, production, QA, wrong environment signatures and
non-enrolled targets. Tests use isolated temporary fixtures; none is a
live Production Apply.


### 313 proof contract gap (verified against original live Central)

The original Central WordPress 313 proof verifier loaded from mad4b-platform
currently advertises mad4b.native-313-independent-host-proof.v1. The
original 306 worker lists exact read and plugin install operations, not
company.code.release. A host-side SHA readback is NOT an original 313
WordPress receipt; installing the new handler alone is not sufficient.
The original first-party 313 WordPress proof contract must separately
admit the code-release operation and its independently signed evidence.
No local preview or fixture marks this complete.

### Dynamic 306/313 integration
The Host handler accepts an actually installed first-party dynamic registry and immutable enrolled plan, or legacy original Worker registration. A local source preview alone is not authority. See ../dynamic-operation-registry-v1/README.md.

### Original 305: Native CLI without WordPress --user

The first-party 305 intake must not reject all WP-CLI solely because WP_CLI
is true. integrations/patch_305_native_cli.py provides a separately signed
Native VPS route for existing company wpcli.full grants, not a WordPress
user impersonation.

The original REST owner, nonce and Unified Administrator paths remain
unchanged. The signed Native path checks the exact company, environment, site,
host, original Grant, enrolled actor ID, method, route, body SHA-256,
short expiry and one-use nonce. It then evaluates the original delegated
operations.write entitlement. No wp_set_current_user or --user is required.

Native CLI can only use the enrolled commands and command-readback routes;
it cannot mint grants, register transports or revoke owner authority.
Original sealed-argv, Broker 306, signed 312, Host Policy and independent
313 remain unchanged. Signature replay, revoked Grant and changed body
fail closed. Isolated hermetic PHP tests under tests/ use synthetic keys.

### Permanent pinned recovery guard

The actual original 305 has a first-party, known-hash restoration guard running
at the beginning of the existing 30-minute original source-release timer.
Its reference source is the pinned version of
integrations/305-firstparty-native-no-wp-user.php, with SHA-256
55a305f2b216f06344f8686e44b2d41b3b2c50a4accc8e1c9eb50fe66efec1e3.
Only two known older source preimages can be restored automatically; a newer
unknown revision is never overwritten. The registered guard's source and timer
are in integrations/guard_original_305_no_user_release.py and
integrations/mad4b-original-source-release-with-305-first.service.

Read-only checker:
python3 ops/company-code-release-v1/integrations/check_original_305_no_user_regression.py

The scheduled one-shot source-release job also manages other independent
modules; a failure in one of those later modules must not prevent its
first original 305 guard from running.

### Original 313 v4 / 306 signed preclaim source guard migration

The 30-minute original source-regression timer previously pinned the legacy
313 v3 verifier and pre-dynamic 306 Worker. This made the original unit stop
with UNKNOWN_RELEASE_SOURCE_DO_NOT_OVERWRITE, even though the actual VPS
was running independently accepted 313 v4 and job-bound signed Grant preclaim.

integrations/guard_original_313_v4_release.py pins the independently
validated 313 v4 verifier to the exact Git snapshot at
ops/dynamic-operation-registry-v1/php/313b-first-party-dynamic-v4.php.

integrations/guard_original_313_worker_preclaim_release.py pins the current
306 source to the same, independently checked, no-grant-fails-closed Worker
snapshot at ops/dynamic-operation-registry-v1/integrations/claimed-job-worker-signed-grant-preclaim.py.

Both continue to restore only specifically known older preimages, preserving
private rollback receipts. An unknown/newer hash is never overwritten.
The original scheduled source-release service is updated to select v4 and
signed-preclaim guards by name and to retain the full original guard chain.
These guards do not issue 305 Jobs, Broker Grants or 313 receipts.


### Original status probing without another release engine (2026-11-13)

Use the existing read-only lifecycle bridge to diagnose the slow Central
Original312 status independently, without a full sequential 305/312/dispatch/
306/313 status chain:

`python3 ops/company-code-release-v1/company_lifecycle_broker.py status --company <company> --environment <environment> --probe original312_status`

Supported diagnostic probes are Original305 scope, Original312 status, WP
dispatch, legacy Original313 handler and dynamic Original306/313 registry
readback. **These are diagnostic stages, not static Provider allowlists.**
Each external status subprocess has a 15-second upper bound, and failures
return `HOLD_ORIGINAL_STATUS_PROBE_UNAVAILABLE` with the sanitized exception
class, not private stderr or tokens. A complete lifecycle report preserves
independently verified probes, but any error puts it in a HOLD state.
Every probe, including a positive Original312 status, returns
`release_execution_allowed=false` and `production_mutated=false`.

On 2026-11-13, the isolated Original312 status probe returned
`observed_true=true` for Wayfatours Production. It did **not** issue a Job,
Grant or signed Original313 receipt. The existing First-Party Registry
already has 15 matching Company Code Release Production digests.
Historic host receipts remain 313-uncertified. The narrow first-party
historical readback of the covered Tenant Sync release was denied because
the live PHP postimage differed from the old pinned version. Do NOT revert
newer company PHP merely to manufacture an old receipt; require fresh
correctly scoped source reconciliation and real independent 313 authority.

The Provider source manifest pins the changed diagnostic module by SHA
and must pass source closure before first-party enrollment. It does NOT
update the installed root Index or authorize mutation.
