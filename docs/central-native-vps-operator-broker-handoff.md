# MAD4B central Native VPS Operator + existing Broker handoff

## Scope and policy

MAD4B has a **shared central Staging** at `tours.dev.mad4b.com`. A company-specific Staging site is not mandatory. `local_git` or `remote_git` artifacts can be distributed without a separately certified MAD4B Package, provided the actual exact source, Staging acceptance, deployment authority, independent company compatibility and rollback checks succeed.

The operator has two distinct modes:

1. Authorized **administrative SSH/Shell** for a named human or central administrator. SSH login/host authentication alone does not grant a standing automated Production deployment.
2. **Managed release execution** through the pre-existing signed Broker, Central Authority Verifier module `312`, and Remote Readback module `313`. Approved standing grants can cover multiple jobs; they are not reapproved per command when the exact operation remains inside the grant. Revocation, expiry and target scope must be enforced at execution time.

The Broker host and SSH target are **distinct identities**. The Broker must not interpret an authenticated connection to its own VPS as authorization for the target company's server.

## Read-only handoff now implemented

The ability `mad4b/central-native-operator-handoff-plan` accepts only `release_sha256`. It reads the company target's **actual** enrollment and version state and independently verifies the central Staging readback. It returns a non-executable handoff descriptor binding the exact artifact SHA-256, manifest hash, Git source commit and type, company site UUID, site origin, environment and plugin path. It never accepts shell commands, target hostnames, grant claims, authority flags, Staging PASS, or production policy from the caller.

- Requires company compatibility PASS, correct `rollout_scope=company_production` and `deployment_role=company_adapter|company_plugin`.
- Refuses the `mad4b-content-hub` Central Controller, Staging canaries, unverified receipts, wrong company, hash mismatch and unsafe target plugin paths.
- Declares the required Broker contracts as `central_authority_verifier_312` and `remote_readback_313`, but does **not** guess their PHP method names, private token formats, server addresses or operation parameter schema.
- Explicitly returns `broker_signed_preflight_verified=false`, `claim_and_delegation_verified=false`, `grant_verified=false`, `ssh_target_resolved=false`, `command_digest_verified=false`, `remote_executor_registered=false`, `remote_readback_verified=false`, `dispatch_eligible=false` and `production_mutation_authorized=false`.

## Existing Broker integration still required

The deployed Broker must generate and bind all fields **on the server**, not from model/caller input:

1. **Central verifier 312**: validate operator's standing grant, claim, company delegation, source artifact, requested operation, site/environment and exact SSH target and command digest. Use existing `Broker Signed Preflight` response and journal.
2. **Native SSH remote executor**: currently not deployed due to a previously reported security denial. Do not use an unrestricted root-shell tool or a different transport to circumvent that denial. Publish the executor only through a legitimately approved channel and review the deployed source.
3. **Remote Readback 313**: verify the installed target plugin path, version, file manifest, activation state and observed result; use the same artifact/source digest and site binding. The Broker journal must preserve the original authority and execution chain.
4. **Company Production batch**: bind the verified 312 result, exact registered executor and checked 313 result in the existing `MAD4B_SCP_Production_Automation` framework or approved replacement. Do not treat signed Staging evidence as Production authority by itself. Existing legacy test certificate checks remain in force until an approved source-backed replacement is installed and independently tested.
5. **Failure**: deny missing/expired/revoked grants, new tenant, changed SSH target, changed command digest, failed target preflight, failed backup, incompatible schema/missing dependencies, missing postcondition or any unreconciled journal/rollback state.

There is no universal Production prohibition: Production is an eligible environment when a valid standing grant authorizes a specific target and operation, and central Staging plus tenant-local preflight have passed.

## Concrete current status

The user-provided VPS handoff reports the signed Broker preflight and 312 authority checks PASS, 313 contract installed, 6/6 authority tests PASS, and `mad4b-dispatch-v1` healthy. It also reports the **Native SSH remote executor not deployed**, no eligible remote SSH queue job and no Production mutation. These are reported status details and have **not** been independently reverified by this source change. The attempt to inspect live operator files and abilities was rejected by a tool safety check; this PR does not work around that rejection.

The new read-only handoff is a source-only additive component with a hermetic test and no Shell transport. No customer site or Production mutation is performed by this change. The commercial end-to-end release remains **PENDING_NATIVE_REMOTE_EXECUTOR_AND_313_READBACK** until the approved deployed executor is available and has completed a real remote operation.
