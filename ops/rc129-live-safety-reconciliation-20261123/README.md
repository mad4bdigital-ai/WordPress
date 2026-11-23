# rc129: restore confirmed live rc128 safety boundaries in isolated source

**Source branch:** `fix/host-v150-original-live-staging-source-20261122` at `d23cf0a39d1b19074bae6b2c4fc679807a029fcb`, 2026-11-23.

**Scope:** source-only. No live WordPress files, active plugin metadata, credentials, update channel, FastCGI cache, WooCommerce state or Elementor templates are modified by this patch.

## Why this change is necessary

Both Tours.dev and Wayfatours have a signed Control Plane `rc.128` installation with newer safety capabilities than the unqualified `rc.129` source candidate. The live source identity is different per-site (`e18c8090ad217d467296bbfa327fc238799393e0` on Tours.dev, `3e6ce498b75390bc900c2c083f92891b8433b774` on Wayfatours). An earlier local source comparison found 195–224 divergent common source files, depending on the candidate/site pair.

The reviewed `host-v150` candidate was missing two release protections:

- `MAD4B_SCP_Self_Update::trusted_master_release_candidate()` plus the exact root-trusted manifest binding in the Staging native upload path.
- `MAD4B_SCP_Self_Update::reviewed_archive_no_regression()` plus a **last-admission invocation** before replacement, rejecting archives that lose enrolled site-bound Addon Registry and signed host Source Admission.

Four execution protections were also absent:

- `execution_attempt_sha256()` (actually referenced by the candidate's checkpoint path).
- `execution_attempt_state()` (durable audit and unknown/reconciling states).
- `execution_boundary_verified()` (exact trusted PHP Closure identity).
- `local_approval_planner_execution()` (actually referenced by the candidate's write wrapper; local-only bypass must not be used by `mad4b-chatgpt` or `mad4b-write` transport).

The installed `class-mad4b-scp-addon-registry.php` code is **byte identical on both live sites**. Its verified new behaviors (site-bound dynamic release pair, duplicate certificate quarantine, shadow fallback, type-safe provider evidence) are restored to the candidate without changing its public method signatures or deleting existing methods.

## Tests completed on the isolated VPS build

- Four changed PHP files lint under PHP 8.5, no syntax errors.
- `tests/live-safety-rc129-reconciliation-runtime.php`: **18/18** isolated, read-only tests pass. Includes valid and tampered master release manifests, exact candidate archive witnesses, missing archive entries, host admission refusal before replacement, stable execution hash, durable audit recovery denial, remote planner bypass denial, and trusted versus foreign Closure verification.
- `tests/wordpress-addon-execution-guard-contract.php`: existing exact-pair guard tests pass; extended to deny duplicate dynamic certificates, mismatched site origin/profile and fail-open unknown-pair release policy.
- `tests/wordpress-addon-registry-contract.py`: passes.

## Deliberate open gates — NOT A PUBLISHABLE RELEASE

1. The host-signed `MAD4B_Release_Source_Admission` implementation is an existing **per-site MU-plugin**, not part of this Control Plane source. A newly staged package must bind to current signed source identity on each site; without it the new last-admission check deliberately refuses a replacement.
2. `origin/master` and the existing fixed GitHub release pointer still identify old `rc.104`. This branch does **not** switch WordPress update channels or publish a new signed `rc.129` archive.
3. Many other live-versus-local differences remain beyond these safety methods; a thorough code/source compatibility reconciliation, exact source SHA release certificate, valid issuer trust anchor and independently signed Release Verdict are still required. The structural archive guard is only one layer and cannot substitute for behavioral proof.
4. Elementor provider `widget_settings.bounded-write` is still `DISCOVERED/shadow` and requires bounded write/readback/exact rollback evidence and current site-specific grants before remote mutation.
5. Never copy the signed public issuer PEM into untrusted package output, falsify provenance fields, import a `rc.128` Production state as automatic `rc.129` deployment authority, or bypass original owner approval.

The new Git changes are a **small audited reconciliation slice**. Additional release-source checks and signing/deployment should be built on an immutable later SHA rather than replacing the live plugin from this branch.
