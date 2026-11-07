# MAD4B Distribution Connectors · Company-local channels

Version 1.3.0 · Distribution Core → Meta Catalog Feed Mapper / approved partner adapters.

## Default behavior

- Registers 11 channel definitions and two private catalog profiles, if missing.
- Channel onboarding is **company-local**; central Company Management may inherit definitions, never credentials or partner accounts.
- No external vault or external wallet service required.
- API tokens are encrypted at rest in a non-autoloaded WordPress option. Keys are derived from the company's WordPress authentication salts and bound to its canonical site URL/blog ID.
- Do **not** export or clone `mad4b_distribution_local_secrets_v1` when creating a company. If the database is cloned, ciphertext from the source cannot decrypt on a different site identity, but the stale option should still be excluded during provisioning.
- WordPress-salt rotation or site-domain migration requires re-entering credentials; back up and handle separately before rotations.
- An actor with full WordPress PHP runtime access can still access the WordPress salts. This is encryption against database-only disclosure, not a sandbox against compromised site code.
- No credentials are echoed via REST, admin interfaces or audit receipts; only saved/missing and readiness states are shown.
- Credential write/delete is restricted to authenticated WordPress administrators and CSRF-protected native admin forms or authenticated REST; no use of `wp --user` to impersonate owner sessions.
- Delivery and provider verification stay **OFF** unless separately approved and implemented by a compatible authenticated partner adapter.

## Live management

- `Tools → MAD4B Distribution Channels`: create marketplace, configure public account metadata, add a local encrypted credential, revoke or rotate it, link channels, inspect readiness.
- `Settings → Catalog Feed Studio`: edit existing feed mappings, plus the private tours/destinations profiles. Previously configured feed slugs, WordPress options and rewrite paths remain unchanged.
- Site-local REST `GET /wp-json/mad4b-distribution/v1/channels` for authenticated administrators.
- Site-local REST `POST|DELETE /wp-json/mad4b-distribution/v1/channels/{id}/credential`; never returns a token.
- Central Company Management: `Distribution & Marketplaces` holds provisioning plans only, not credentials, external provider identities or remote job receipts.

## Adapter contract

- `MAD4B_Distribution_Local_Credentials_V1::for_adapter($channel,$purpose)` is denied by default. Trusted site-local provider code must explicitly grant the purpose-bound `mad4b/distribution/allow_local_credential_access` filter.
- `mad4b/distribution/adapter/{channel}/verify` is a provider-specific extension point. No partner login or availability can be claimed without a real provider readback and consent.
- Google Things to do export eligibility remains gated by weighted-price/fees/occupancy/availability/checkout proof, plus provider enrollment and an authorized channel transport.

## Failure and rollback

The package is portable, and original source/target copies are backed up under `/opt/mad4b-company-provisioning/backups`. Do not copy source-company credentials to targets. Existing channels and feed mappings are preserved by idempotent re-provisioning. For full site restoration, keep the matching WordPress salts alongside the original encrypted options.
