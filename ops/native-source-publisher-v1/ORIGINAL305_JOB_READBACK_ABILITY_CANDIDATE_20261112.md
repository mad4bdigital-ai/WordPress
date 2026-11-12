# Original305/306 Current Job/Claim Readback — source-only WordPress Ability

**State: CANDIDATE ONLY — NOT BOOTSTRAPPED, INSTALLED OR EXPOSED THROUGH MCP.**

The main remaining legacy migration blocker is the lack of authenticated
current Original305/306 WordPress Job and Claim readback. Historical signed Host
FINISH and expired original Broker Grants cannot substitute for fresh Job state.

Added class-mad4b-scp-original-job-readback.php under
wp-content/plugins/mad4b-site-control-plane/includes as a separate,
deliberately unmounted, read-only source module.

## Proposed read contract

- WordPress Ability: mad4b/original-job-claim-readback, registered only when
  a future approved Control Plane release explicitly loads and boots the
  class. The current main plugin does neither.
- Requires authenticated manage_options plus verified exact Site Profile:
  canonical HTTPS origin, Site UUID, environment and deployment binding.
- Input accepts exactly company, Site UUID, environment, origin and up to 24
  original immutable Original306 command SHA256s. No Job IDs, commands,
  SQL fragments, release paths or new permissions are accepted.
- Scans the original WordPress Job option name prefix with a hard cap of 1000
  records. Denies on truncated result, database error or malformed Job.
  Reads Claim and route-gate only after command and company/site match.
- Verifies the original Broker contract, WordPress target, Job type, company,
  site UUID, origin and environment. Claim comparison means field correlation,
  NOT independent original312 signature verification.
- Returns bounded per-command state, expiry, execution and Claim indicators.
  Never returns Job ID, Grant, transport, nonce, signature, plaintext command,
  approval principal or raw serialized options.
- Every record is HOLD or READ_ONLY, and original312_eligible,
  signed_original313_receipt_verified, authority_created and
  job_or_claim_mutated are all always false.
- Never writes WordPress options, performs execution, runs WP-CLI, signs,
  creates a Job or issues/replays a Grant.

## Evidence

PHP syntax and the offline acceptance fixture run inside an isolated read-only
project PHP 8.5 Docker image. Acceptance covers valid/invalid Job and Claim,
wrong company and site, Host versus WordPress jobs, duplicates, invalid inputs,
role denial, unsafe Site Profile, DB errors, truncated scans, and proper
read-only WordPress Ability annotations. Python source tests separately assert
the module is not bootstrapped and contains no mutation APIs.

**No live Job/Claim read occurred.** The previous bounded live WP-CLI attempt
failed at the remote tool boundary without an authenticated Job result.
Original305/312 state remains unverified.

## Requirements for approved first-party integration

1. Reconcile tracked and installed Control Plane source and provenance; never
   replace newer live code with this candidate.
2. Approve read-only inclusion and bootstrap, without exposing unauthenticated
   public REST. Validate the real installed MCP read projection and role.
3. Canary on Source staging with 16 exact original command SHAs, no claim or
   deployment, and independently cross-check Job/Claim results.
4. Integrate only authenticated snapshot data into the fail-closed lifecycle
   classifier. Original312/313 signed readback remains separate.
5. Continue to hold source-provider enrollment until 15 legacy associations,
   worker parity, publisher CAS guards and Production evidence are reconciled.

This is a read surface candidate, not a new Original305 issuer or authority.
