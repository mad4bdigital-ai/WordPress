# PR #814 — Site Profile Feature Re-enrollment: Dynamic Environment Execution Contract

**Test contract reconciliation only; no Site Profile permissions or live writes changed.**

The older `site-profile-feature-reenrollment-contract.py` expected
`mad4b_site_profile_feature_reenroll_environment_denied`, a retired
Staging/Production hardcoded rejection. Current `can_execute()` instead
requires an enrolled Site Profile, authenticated WordPress administrator,
verified ChatGPT OAuth authority-step-up scope, non-empty syntactically valid
current environment ID, exact enrolled origin/home/site identity and HTTPS.
The test now checks those **actual** independent authorization requirements
and explicitly rejects resurrection of that historical fixed environment
case in `can_execute()`.

New hermetic PHP runtime scenario confirms an exact enrolled
`development` environment and HTTPS origin can complete the bounded
Acceptance/Skills Phase A when independently authorized, while `write` and
`production_write_confirmed` remain false. Existing Staging, Production,
replay-revision, profile-digest, source-build, wrong-actor, mixed-mode,
audit-failure and URL-mismatch assertions remain unchanged.

An important remaining distinction: `chatgpt_step_up_tools()` and broader
ChatGPT catalog presentation still contain environment-specific visibility
rules. **This change does not assert these presentation rules are fully
provider-policy-driven or that every environment exposes the operation.**
That requires a separate governed policy/descriptor migration and live
readback to avoid accidentally advertising permissions before registration.
It is not authorized by a static CI string replacement.

Local standalone source contract PASS; isolated PHP 8.5 reenrollment runtime
PASS including enrolled development and no write admission. First-party
Original313 historical postimage drift remains an independent Release HOLD.
