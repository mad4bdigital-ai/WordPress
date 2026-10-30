# Independent Authority to MAD4B Control Plane: Staging read-only bridge

This is a nonauthorizing tenant-scoped readiness readback, not an operational CA or permit issuer.

## Architecture
The central Independent Authority has a private MariaDB database and a dedicated authority_runtime account with SELECT-only grants. An operator-owned Python projector reads live policy, real permit/event counts, operational CA enrollment and owner MFA principal counts through Docker and the private DB. No credentials or signing keys leave Authority. It writes an atomic 0640 root:www-data status JSON to an export directory. The tenant Staging PHP container mounts only that directory as read-only. The generic PHP bridge checks source contract, tenant hostname, environment, independent mode, fallback disabled, nonauthorization flags and freshness (maximum ten minutes).

## Control Plane read-only API
- GET /wp-json/mad4b/v1/operational-authority/status
- GET /wp-json/mad4b/v1/operational-authority/release-readiness
- Native nonpublic WordPress Ability: mad4b/independent-authority-status

All require logged-in administrator and existing MAD4B_SCP_Policy::can_read, work on Staging only, and have private no-store responses. No POST/PATCH/PUT/DELETE; no generic dispatch, source pin self approval, sign, deploy, publish, or write capability.

## Trust semantics
The selected independent_authority mode comes from the central Authority database. GitHub may host and review source, but cannot issue local operational permits. github_governed remains a separately selected workflow, never an automatic fallback. The bridge never returns authorization, permit, or completion certificate success, even if raw DB policy changes.

A fresh status projection is informational, not proof of a signed permit. Real authorization requires genuine owner/platform owner MFA, operational CA custody, independently signed QA, exact site UUID and artifact binding, atomic one-time permit, runtime verification and independently signed completion. All remain separately gated. No diagnostic QA key is used for operational signing.

## Tenant onboarding
Install the generic PHP MU plugin in the company's Staging site. Place a tenant-specific root-only projection-config.json based on consumer.example.json in the central Authority operator folder. Create one private export folder per company. Run a central SELECT-only projection every five minutes, and bind its export into PHP at /run/mad4b-independent-authority:ro. Never mount DB secrets or CA private keys into WordPress. Test admin auth, replay/stale/tenant/mode/fallback guards and cleanup, then separately review source and exact-head governance before wider release.

## Woo Lab Staging evidence, 29 Oct 2026
- Central authority DB healthy; preferred mode independent_authority, automatic fallback off, actual permits zero, MFA principals zero, operational CA zero, one diagnostic-only runner.
- Current authority state BLOCKED for independent_ca_and_mfa_not_enrolled.
- PHP status and readiness return BLOCKED and no permits/certificates; four site routes HTTP 200 after PHP-only recreate.
- Real REST: anonymous 401, subscriber 403, admin 200/no-store, POST 404; pure safety checks cover stale, wrong tenant/mode, automatic fallback, issuance flag, forged auth and malformed projection. No site changes.
- Dedicated host cron every five minutes refreshes the projection, with logrotate. No Production mutation.

## Rollback and limits
Remove only this Staging MU module; restore the saved Staging compose configuration, recreate PHP only, disable the dedicated authority projection cron. Preserve existing WordPress/Control Plane modules, booking data, and central Authority database. This integration does not change GitHub PR branch protections, historical release verdicts or production release policy. It is an observer, not a universal write gate.
