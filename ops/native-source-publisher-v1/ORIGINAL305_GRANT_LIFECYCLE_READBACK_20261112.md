# Original305 / Original312: Historical Grants and Job Lifecycle

Status: **SOURCE-ONLY / READ-ONLY / NOT AUTHORIZED FOR DEPLOYMENT**

The original code-release plan inventory contains 15 plans under the dynamic
Original306 store. The original worker continues to index those plans. Its
separate receipt reconciliation plan is not part of the 15.

## Root-owned, cryptographically verified historical grants

The source-only original_historical_grants_audit.py matches those 15 immutable
plan digests against original company-code-release Broker Grants. It uses the
installed first-party grant_message canonicalization, the pinned public key,
the exact original scope and historical issuance/expiry, and root-only files.

**Observed on 12 Nov 2026:**

- 13 original Grant files matched 10 distinct CAS plans
- All 13 matched the Broker's Ed25519 public key, scope and bound lifespan
- All 13 grants have expired
- 5 distinct plans have no matching original Grant in this inventory
- 2 plans have repeated historical Grant files; these account for 3 additional
  files beyond the 10 uniquely covered plans
- No invalid or conflicting grant file was found among the matched records

Count *plans*, not Grant files: 13 matching files are NOT 13 eligible plans.
Even a correctly signed, unexpired historical grant would not become fresh
Original312 authority without an independently admitted Original Job.

## Current job/claim status is still unknown

The WordPress Original305/306 Broker stores actual Job and Claim records in
WordPress options. The existing connected WordPress tool did not expose a
specific governed read Ability for these records. A bounded central WordPress
WP-CLI attempt failed at the tool transport boundary without returning an
authenticated Job result. The original worker should not be bypassed by raw
database mutation or manual token generation.

The source-only original_job_lifecycle_preview.py accepts an authorized
snapshot when one becomes available and *never* issues execution authority.
It separates the cases: queued and unclaimed; queued with stale Claim; fresh
claimed but needing independently signed Original312; expired queued;
expired claimed requiring receipt-only recovery; duplicate/conflicting
Jobs; incomplete Job identity; and uncertain final state.

Every classification is HOLD and non-authorizing. No signed claim, original
312 result, issuer certificate or original313 receipt was fabricated.

## Historical FINISH distinction

The separate original_finish_readonly_audit.py verified 10 historical
Broker-signed Host FINISH attempts, all against company.code.release.
FINISH is a Host execution artifact, NOT an independent Original313 final
certificate or fresh original312 proof.

Historical finish and historical grant matching may overlap but cannot
replace an authenticated readback of their original WP Job and Claim.
Do not automatically replay any expired or claimed original operation.

## Future admission requirements

When a first-party read Ability or original authorized WP-CLI path becomes
available, it must:

1. Read each exact original Job and Claim for the immutable command SHA,
   using the existing enrolled actor and the bounded original read channel.
2. Confirm all company/environment/site UUID/origin, broker host, exact
   command digest, original Grant, expiry and Claim state.
3. Preserve completed/expired plans as history; require independent Original313
   receipt reconciliation if completion is uncertain.
4. Only admit a genuinely fresh Original312 scope before *new* execution.
   Never turn an expired historical Grant into a new permission, and never
   re-sign original old Claims merely to unblock source release.
5. Validate the 15 existing plan/provider associations before registering
   a new source-family operation that shares their CAS plan contract.

Read-only report includes no Job IDs, nonces, raw signatures, secrets or Plan
SHA values. These source probes did not mutate any live WordPress property.

## Proposed governed current Job/Claim read surface

A strictly bounded, non-authorizing WordPress Ability candidate is now present
in the Control Plane source under
class-mad4b-scp-original-job-readback.php. It requires a verified Site Profile,
administrator permission, exact command digests and company scope, and reads
original Job/Claim options without mutations. It is now bootstrapped only in isolated Control Plane source; it remains uninstalled on the live site
and has not returned any real Job status. See
ORIGINAL305_JOB_READBACK_ABILITY_CANDIDATE_20261112.md.
