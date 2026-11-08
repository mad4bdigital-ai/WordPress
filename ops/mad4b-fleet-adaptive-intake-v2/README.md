# MAD4B Fleet Adaptive Intake v2.2.2

Goal: collect missing input for registered MAD4B operations from ChatGPT/agents without navigating WordPress admin. The first-party form URL is optional fallback. The original change always runs through its governed executor.

## Agent workflow

1. Discover company, environment, canonical origin and execution route in MAD4B Company Registry and Registry Infra.
2. Call ability mad4b/fleet-intake-auto with an exact ability_name, or a task if the site's Adaptive Operation Router selects a verified candidate.
3. On WAITING_USER_INPUT, the agent presents the capability-schema fields in chat; only nonsecret values may be entered.
4. Call mad4b/fleet-intake-save with the returned checkpoint_ref, descriptor_sha256 and typed values.
5. Call mad4b/fleet-intake-status to read back INPUT_SAVED, saved nonsecret values, input digest and correlation_ref.
6. Submit the result to the ORIGINAL governed operation with its normal authorization, approval and independent readback. Intake never issues a 305/306/312/313 native receipt or bypasses the original execution route.
7. Passwords, API keys, bearer tokens and private keys use a certified native secret provider; never enter them in chat or generic intake.

The first-party REST endpoints are:
  POST /wp-json/mad4b/v1/fleet-intake/auto
  POST /wp-json/mad4b/v1/fleet-intake/plan
  POST /wp-json/mad4b/v1/fleet-intake/save
  GET  /wp-json/mad4b/v1/fleet-intake/status/{ref}
These require a valid site administrator identity. Browser forms under admin-post.php are optional.

## Schema support and encryption

The WordPress Ability input schema is authoritative. Handles strings, booleans, integers, numbers, enums, dates/time, email/URI and nested objects and arrays using JSON fallback. Optional sensitive governance metadata is excluded; required secrets fail closed. Capabilities not registered on a site cannot be invented.

Per-site binding requires exact site origin, environment and registered Site UUID. The checkpoint is stored as AES-256-GCM ciphertext in a non-autoload WordPress DB option, with per-site HKDF key derived from server WordPress AUTH_KEY and SECURE_AUTH_KEY and the Site UUID. Checkpoint access is also tied to the original authenticated WordPress actor. The key is never in the database or conversation. Checkpoints expire after 24 hours, have bounded cleanup and one-shot CAS-based completion. This is NOT a password vault and cannot store credentials. Original write execution has its own approval rules.

## Live acceptance as of 8 November 2026

- Tours.dev Staging: nested forms, enums, dates, bad input rejection, encrypted option write/readback, secret-pattern rejection, one-shot replay rejection, and first-party Agent auto->save->status with nonsecret values: PASS.
- ETG Staging: enrolled hosting-3 SSH, canonical WordPress home, Site UUID and module/ability registration confirmed. Plaintext v2.0 nonsecret save/readback passed before the encrypted upgrade.
- ARE Staging: enrolled hosting-2 SSH, canonical WordPress home, Site UUID and module/ability registration confirmed. Plaintext v2.0 nonsecret save/readback passed before the encrypted upgrade.
- Both company staging sites are now upgraded to v2.2.1, exact source SHA verified, server crypto/key capability verified. Fully authenticated company post-upgrade write/readback was not completed because a tool safety restriction blocked the test.
- Production rollout: held because central Company Registry is missing Site UUIDs for Production site identities; no fake IDs were issued.
- ChatGPT WordPress MCP direct session: verification blocked by missing Mcp-Session-Id header, requiring a transport integration fix.
- Anonymous REST: Tours.dev and ARE Staging returned HTTP 401; ETG Staging timed out in an external probe.

## Versioned deployment

The deployed script tools/company-fleet-adaptive-intake-deploy-v1.py requires a pinned registered SSH account, matching canonical WordPress home and Site UUID, and exact SHA compare-and-set for upgrades. Staging is default; production requires its own explicit parameter and identity proof. It uses the Hostinger company routing source introduced by MAD4B PR #784.

## Current limitations

Do not claim any original resource write was executed. Do not infer that an authenticated Agent can execute a WordPress Ability simply because its input checkpoint exists. The native governed executor owns all permission, step-up, transaction receipts and independent readback. Sensitive values need a separate certified secret store and lifecycle.

## v2.2.2 acceptance

- Support governance-injected context fields on plan, auto and status without accepting them as authorization for the original operation.
- Encrypt the actor ID inside the site-bound checkpoint and reject read/save attempts from another identity; the checkpoint reference alone is not a bearer grant.
- Central WP CLI acceptance: nested typed values, encrypted-at-rest, secret-pattern denial, replay rejection, agent auto->save->status and anonymous-actor isolation all PASS.
- Re-deployed the identical v2.2.2 code to ETG and ARE Staging through registered SSH with independent source-SHA readback and ability registration.
- Direct ChatGPT MCP session and company post-upgrade interactive save remain not independently verified. Original write executor is unchanged.
