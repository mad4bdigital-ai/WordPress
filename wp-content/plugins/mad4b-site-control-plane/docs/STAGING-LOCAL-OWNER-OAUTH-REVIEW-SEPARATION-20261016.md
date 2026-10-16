# Local owner Staging Write review: cookie/OAuth identity separation

## Confirmed defect

The WordPress wp-admin exact-owner page calls Convergence::plan_for_local_owner(). The outer convergence class already permitted an enrolled administrator, read-only and only within an isolated request-scoped flag. But nested Staging_Write_Grant_Reconciliation_Plan::plan() unconditionally required verified OAuth bearer and Agent_Registry::resolve_agent() from an OAuth subject. Thus a valid WordPress session was blocked from displaying its review plan and surfaced mad4b_owner_plan_not_ready without precise blockers.

## Fix and boundary

- Only the explicitly enrolled Staging owner, logged in on wp-admin with manage_options, can call Grant_Reconciliation_Plan::plan_for_local_owner().
- The nested plan uses a request-scoped try/finally local-preview mode to read the exact enabled Staging canonical governed-write agent from the governance registry, bound to the enrolled owner ID. It does NOT synthesize or persist an OAuth identity, grant or token.
- Ordinary Grant_Reconciliation_Plan::plan() and its remote can_plan() still require a verified OAuth bearer; the convergence Apply path still requires a verified ChatGPT client with authority step-up, exact digests, grant reconciliation and owner ticket.
- Production, non-enrolled users, inactive/wrong-owner/wrong-environment agents, non-admin views and reentrant calls fail closed.
- Owner Review returns bounded blockers and exact_grant_blockers on incomplete plans; the wp-admin page escapes and renders them. It does not render an approval form while the plan is blocked.
- The local approval form continues requiring the original WordPress nonce and exact confirmation and only records a short-lived intent. The signed receipt is not an execution ticket.

## Evidence

- tests/staging-write-owner-grant-local-preview-runtime.php: PHP 7.4 and 8.3, including normal remote bearer required, exact owner preview, registry identity mismatch, old environment denial, exception-scoped flag reset, wp-admin-only admission.
- Existing staging-write-owner-intent-runtime.php, staging-write-local-owner-preview-runtime.php, staging-write-grant-plan-read-lane-runtime.php, Enrollment/OAuth and Convergence contract remain passing.

## Independent acceptance

The source fix is not deployed until an exact-head package is installed on ETG Staging and All Royal Egypt Staging and its live build is read back. The owner must open the wp-admin review page with their WordPress session; this is NOT an OAuth login substitute for the subsequent governed remote Write Apply. Full end-to-end tests should assert the page renders the exact review and fails with visible blockers instead of only mad4b_owner_plan_not_ready.

WPML and Rank Math notices are unrelated to this flow.
