# MCP runtime recovery and Site Profile updates

The same certified Adapter entrypoint may coexist with foreign builder, validator
or DTO classes from Rank Math or another Jetpack package. A successful OAuth login
does not certify this PHP class set or its tool catalog.

The managed MU loader now uses the regular Site Profile's pure early binding.
An exact valid enrolled profile can override WordPress's implicit Production
DEFAULT. Explicit host Production, foreign origins, invalid profiles, disabled
managed-runtime and reenrollment-required profiles remain ineligible.

On the exact diagnostic AJAX POST, MU scope pins the certified classes but leaves
singleton arming and REST materialization to the worker after its nonce,
administrator, target and build checks. Foreign frontend/AJAX requests bypass the
loader. Developer and developer-breakglass MCP routes use the same class set;
transport permission and grants still determine access.

All executable pin files and the package autoloader are hashed before execution.
An already declared foreign class cannot be replaced in the current request.

## Lifecycle

- Connection > MCP Endpoints offers a separate `Repair MCP runtime for next request`
  POST protected by update_plugins, manage_options, nonce and build fingerprint.
- Recovery validates the installed certified disk set, owns the shared maintenance
  lease, refreshes recognized managed MU bytes and arms the class set with audit.
- Plugin update/activation schedules a new-code cron request. Profile saves schedule
  recovery only for exact non-production managed-runtime enrollment. Production or
  disabled enrollment cancels that scheduled recovery.
- Existing post-update convergence runs this phase under its existing lease after
  schema convergence. It cannot carry forward or repair write authority through
  this phase.
- The redirect/fresh request is required. Run the endpoint diagnostic afterward;
  the repair result reports `armed_for_next_request`, never external certification.
- First deployment from a version without these hooks may need the explicit repair
  POST. Recovery never disables Rank Math, rewrites foreign package bytes or grants
  Production/write/OAuth authority.

## Site Profile environment changes

The form includes Production-write confirmation even while the current profile
is Staging. It shows the fields when the SELECTED environment is Production and
write is selected. With JavaScript disabled, the fields and explanation remain
available. Production without write needs no write acknowledgement. Production
with write requires both the checkbox and the exact phrase
`ENABLE GOVERNED PRODUCTION WRITE`.

Environment rebinding creates a new site identity and may reset its revision to
1. AJAX verifies the committed UUID, revision, digest, origin and environment,
without assuming the revision must exceed that of the old identity. The whole
profile workspace refreshes after success, including the displayed environment.

FormData is captured before controls are disabled; one-time acknowledgement fields
are cleared after successful persistence. Staging does not retain a Production
write acknowledgement.

## Update channel states

`mad4b_self_update_manifest_not_cached` means the explicit channel check has not
populated its cache. The Plugins row presents it as an unchecked state and keeps
its signed refresh action. Passive page loads still make no outbound requests.

`mad4b_self_update_continuation_prior_authority_drift` is an authority gate. The
existing candidate-drift bootstrap applies only when its exact persisted grants
are clean and the candidate binding alone is stale. No broader drift is bypassed,
and Production classification does not become an update-policy escape hatch.

## Verification

CI includes 18 fresh-process MU boundary cases, 16 lifecycle/recovery cases,
settings submission regressions and Staging/Production rebind/readback tests.
The real WordPress 6.9/latest mixed-class fixture removes WP_ENVIRONMENT_TYPE to
model the implicit Production default, verifies first-request fail-closed repair,
then verifies CLI and HTTP-style AJAX recovery with all 26 tools. The AJAX fixture
keeps external certification false and checks runtime bytes/plugin inventory are
unchanged by diagnosis.
