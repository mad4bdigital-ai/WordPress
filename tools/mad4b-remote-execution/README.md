# MAD4B Remote Execution (shared code)

This directory contains **tenant-neutral source**. Production and unrestricted Shell / Raw SQL are **not enabled** by this source layout.

## Required per-site binding

1. Enroll a MAD4B Site Profile with an exact site UUID, origin and environment.
2. Pin `MAD4B_OPERATOR_RUNNER_PROFILE_ID` in that site's **private**, externally managed configuration. It has **no default**.
3. Pin the same Runner Profile ID, current signed Site Profile fingerprint and exact `site_uuid` at the **external worker**. The worker refuses a correct RSA signature for a different profile or site UUID.
4. Keep SSH identities, DB credentials, signing keys and executable paths outside source control and outside the website. Example profiles under `examples/profiles/` are non-functional templates, not credentials or enrollment actions.
5. Validate OAuth, Developer exact grants, worker identity, session state, approval state and receipt signature through the governed MCP path before considering any execution available.

## Read-only diagnostic runner

`readonly-consumer.py` is a **separate bounded diagnostic pilot**, not the general Operator executor. It requires explicit `--site-root`, `--runner-directory`, `--wp-cli-binary` and `--runner-profile-id`. Do not run an existing production queue or use a worker account with cross-site access. The old ARE-specific signed receipts remain historical artifacts and are not rewritten.

## Tests

`python tools/check_mad4b_remote_portability.py` rejects hardcoded customer domains, Hostinger home paths and fixed site-specific runner identities in shared PHP runtime and tooling.

CI uses two synthetic, separately pinned site profiles and verifies that a valid signature with the wrong Runner Profile ID or `site_uuid` is rejected.
