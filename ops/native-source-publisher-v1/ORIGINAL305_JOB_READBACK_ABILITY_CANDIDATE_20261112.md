# Original305/306 Current Job/Claim Readback — source-only WordPress Ability

**State: INTEGRATED IN ISOLATED SOURCE — NOT RELEASED, INSTALLED OR VERIFIED THROUGH LIVE MCP.**

The main remaining legacy migration blocker is the lack of authenticated
current Original305/306 WordPress Job and Claim readback. Historical signed Host
FINISH and expired original Broker Grants cannot substitute for fresh Job state.

Added class-mad4b-scp-original-job-readback.php under
wp-content/plugins/mad4b-site-control-plane/includes as a separate,
read-only module, now bootstrapped only in the isolated full-runtime source. The installed site is unchanged.

## Proposed read contract

- WordPress Ability: mad4b/original-job-claim-readback, registered only when
  an approved Control Plane release installs the updated full-runtime
  bootstrap. The branch now loads and boots it, but the live plugin does not.
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
2. Reconcile the isolated source-level read-only inclusion/boot with the
   canonical Control Plane release, without opening public REST. Validate the
   actual installed MCP read projection and role.
3. Canary on Source staging with 16 exact original command SHAs, no claim or
   deployment, and independently cross-check Job/Claim results.
4. Integrate only authenticated snapshot data into the fail-closed lifecycle
   classifier. Original312/313 signed readback remains separate.
5. Continue to hold source-provider enrollment until 15 legacy associations,
   worker parity, publisher CAS guards and Production evidence are reconciled.

This is a read surface candidate, not a new Original305 issuer or authority.

## Redacted-lifecycle classification compatibility

The read-only WordPress output now includes the verified canonical origin
and observation epoch in addition to company, Site UUID and environment.
This exposes no Job ID, token, Grant or command contents.

Added compact_job_readback_classifier.py as a non-executing source-only
adapter. It consumes exactly the redacted Job/Claim view, checks the immutable
command digest, site identity, company, bounded observation age and
no-authorization output flags. It classifies queued, claimed, expired,
ambiguous, duplicated, conflicting and terminal records; all classifications
remain HOLD with execution_authorized=false. It refuses input that claims
Original312 or Original313 verification.

The existing original_job_lifecycle_preview.py remains usable for richer
future authenticated first-party internal snapshots, but must not require
the public-facing read-only Ability to expose Job IDs or private Grants.

The source-level boot integration does not prove installation. No live WordPress
readback, installed Ability, signed Original312, signed Original313, source
publish or Broker Job was created by either classifier.

## Original306 live inventory scope correction (read-only)

The currently installed first-party Original306 index contains 16 commands,
all bound to **wayfatours / production / https://wayfatours.com**: 15
`company.code.release` and one `company.code.release.receipt.reconcile`.
A Source/Tours.dev staging Ability cannot be used as a proxy for those jobs.
An exact 16-digest read-only request was derived from the installed index
and stored only as a root-private VPS report, not in Git. This is a canary
request, not a completed WordPress read or Original312 proof.

The new Control Plane bootstrap modifies an earlier source-integrity-pinned
plugin entrypoint; the full-suite additive semantic provenance test is
expected to reject this unpublished delta until the canonical release review
explicitly incorporates it. Do not rewrite the old provenance attestation.

### 2026-11-12: bounded batch and provenance review

`compact_job_readback_classifier.classify_batch` rejects partial, extra,
repeated or out-of-scope command digests and cannot claim an independently
verified Original312 receipt. Its `disposition` distinguishes safe description,
reconciliation, and rejected conflicts; all remain HOLD and non-executable.

`control_plane_readback_provenance.py` separately checks the exact five-line
Control Plane boot insertion against pinned before/after SHA256. This is a
source-review artifact, **not** an owner signature or release approval. The
original 69-conflict provenance test remains deliberately unmodified and its
newly failing source-lineage assertion is a deployment gate, not an ignorable
false-positive. The production-only 16-digest readback cannot be claimed
until the ability is actually installed, authenticated and independently read.

### Source-only pinning audit (2026-11-12)

`control_plane_readback_provenance.py` now checks both the exact five-line
Control Plane boot delta and a SHA256 pin for the included PHP readback module.
The original historical source-provenance gate is deliberately unchanged and
still requires an independent release review. This candidate was not installed
on Wayfatours; the private 16-command request remains unexecuted.
