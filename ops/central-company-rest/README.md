# MAD4B Central Company REST — Staging

Company registry: tours.dev.mad4b.com. Companies: www.allroyalegypt.com, egypttourgates.com, tourism.almallahgroup-mg.com.

## Approved identity protocol

Use the new EC P-256 public-key modules, not the original HMAC workflow:

- Central: `central/160-mad4b-central-companies-rest.php` (admin registry), `central/170-mad4b-central-company-public-key-pairing.php` (signed verification).
- Remote: `remote/170-mad4b-company-public-key-identity.php`.
- Each WordPress host generates its own private key locally; only public keys are exchanged through verified SSH. No shared secret or private key crosses a hosting boundary.
- Admin REST: GET `/wp-json/mad4b-central/v1/companies`; POST `/wp-json/mad4b-central/v1/companies/{company}/verify-public-key`.
- Remote REST: **POST only** `/wp-json/mad4b-company/v1/identity-v3`. Sign exact method, path, company, timestamp, random nonce and canonical host; validate both signatures. A SQL unique-key nonce claim blocks replay.
- Legacy HMAC pairing and GET identity paths are deprecated, unpaired and not an accepted authentication mechanism.

## Acceptance results, 2026-10-29

Registered all three companies on Staging. Central anonymous registry access: 401. Local administrator registry access: 200. All three central public-key verifies: 200, mutually authenticated, `authenticated_read_only`. No private keys transmitted or exposed.

A critical negative test discovered that the original GET v2 response was being served from LiteSpeed cache (`x-litespeed-cache: hit`) despite `Cache-Control: no-store`. The POST-only v3 endpoint replaces it. A fresh signed v3 request returned 200; repeating the exact challenge returned 403 on Egypt Tour Gates. Anonymous POST v3 requests returned 403 on all three sites in smoke testing. Targeted LiteSpeed URL purge on all three sites removed the stale v2 cache; old v2 GET now returns 404 (`rest_no_route`, cache miss) for all three.

## Authority boundaries

Authenticated identity is `company_identity.read` **only**. It does not authorize remote writes, raw WordPress database imports, orders/customers/credentials/media transfer, publishing prices, or automatically changing site content.

The next separate workflow is scoped access to the tourism source's Nile cruise catalog, taxonomies, and reference-only historical rates. That capability requires its own approved read contract; no historical price becomes checkout-authoritative.

Current deployed source is ahead of master in draft PR #631. Review and exact-head attestation remain required before merge/release. Company databases, WordPress public code and existing content are not bulk migrated.
