# Multi-company WordPress deployment — private candidates (2026-10-29)

## Outcome
Two **private, non-serving code candidates** have been created on the companies' existing Hostinger hosting accounts. This is **NOT** a completed cutover or an authorization to switch the sites. The active `public_html` directories remain untouched. Original company databases and their individual site identities remain in place.

| Site | Hosting target | Private release directory | Existing WordPress | Code candidate |
| --- | --- | --- | --- | --- |
| All Royal Egypt | hosting-2 | `/home/u948953904/domains/allroyalegypt.com/.mad4b-release-candidate-20261029` | 7.1.4 / PHP 8.5.12 | ~774 MB |
| Egypt Tour Gates | hosting-3 | `/home/u783534439/domains/egypttourgates.com/.mad4b-release-candidate-20261029` | 7.1.4 / PHP 8.5.12 | ~1.1 GB |

The private candidate is protected with mode 0700. Its `site/` is a baseline of **each company's own existing code**, with a private overlay of nine selected MAD4B system components sourced from the current VPS Commerce Staging code. The baseline preserves its company's WordPress 7.1.4 rather than downgrading to Staging 7.1.3.

### Source and file proofs
- System module bundle: nine named component directories, **980 files**, SHA-256 `013fe32aaa7b010f315dfe65288976d29780e1d28946754d98bc94fa29221ef1`.
- Original per-file SHA-256 manifest preserved on the VPS and each private company candidate; manifest SHA-256 `9c52c275c77940da5e507f0fa46e6d409f36a5ef19c9e1705abbc2f1bcc8a95a`.
- All 980 file hashes matched in **both** extracted private candidates. Candidate core 7.1.4 retained. Selected PHP entrypoint lint passed.
- Generic shared MU candidate: **34 files**, SHA-256 `4a815d06d007fa9df7b88b1557d4feefdc1ac9a498dd10c17c5f4cfe6be43ee2`, placed in `pending-review/` only. These files have **not** been installed or activated, to prevent importing environment/brand-specific hooks prematurely.
- No `wp-config.php`, database credentials, media uploads, backups, QA media, or account data was imported from the VPS Staging candidate into the company system bundle.

### Separate current Production DB backups
Source snapshots were taken via the enrolled pinned-key SSH credentials. The native MariaDB client was used because Hostinger disables `proc_open` for PHP, so `wp db export` is unavailable. Database data was **read** with a single-transaction dump; source WordPress databases were never changed.

| Company | Current database tables | Private source SQL.gz | SQL.gz SHA-256 | Isolated restore |
| --- | ---: | --- | --- | --- |
| All Royal Egypt | 162 | ~25 MB | `c1c7974b3cf20c941280b58e80af7d37b0d69668da3be7ec17055c44ee6afe84` | PASS: 162 tables |
| Egypt Tour Gates | 190 | ~34 MB | `9ce1cf93222c383619d732b2f406953c70a71b4b1df3d5f1974c464ff7249031` | PASS: 190 tables (after increasing test-only net timeout) |

The private, mode 0600 production SQL backups remain on the respective hosting account under `evidence/SOURCE-DB-20261029.sql.gz`. Encrypted transmission over pinned-host-key SSH was used for a second **root-only** backup copy at `/opt/mad4b-company-release-prep/20261029/private-db-snapshots/` on the VPS. The second backups were SHA-matched and imported into isolated temporary MariaDB containers with Docker `network=none` and no published ports; temporary database containers and scratch directories were removed on successful verification. This is not an independently off-site disaster recovery solution.

### No-go deployment gates
1. Review module provenance against the accepted GitHub commit. This package is a **VPS Staging-derived review candidate**, not an approved master release. PR #611, PR #614, PR #616 (and the general release governance) remain separate review/approval tracks.
2. For each company, create a separate NEW database and install its **own** production content there. Do not connect the candidate to the old live database or combine the two tenants. Do not seed with ETG Staging QA records.
3. Company-specific compatibility: WPML identifiers, tours, properties, transfers, rates, WooCommerce checkout, Fluent Forms, Fluent SMTP, MCP/Switch User authorization, scheduled jobs, webhook isolation and plugin licensing.
4. Public media origin: source media stays on the VPS. Secure per-tenant public media catalogs, identity binding, DNS/TLS and URL projection remain **unactivated**; never copy source media into the company hosting directories.
5. Once independent QA passes and an owner-signed exact-head operational permit is issued, prepare a **separate short-window switch**. Only then rename the old `public_html` to a timestamped legacy directory, move the validated candidate into `public_html`, install per-company secrets securely, and verify HTTP/SEO/admin/orders, with a scripted rollback.
6. No automatic link publishing, no unreviewed Production database change, and no DNS change during the private copy stage.

## Current verdict
`PRIVATE_CODE_COPIED / LIVE_SWITCH_NO_GO`. Websites `https://www.allroyalegypt.com/` and `https://egypttourgates.com/` both returned HTTP 200 after preparation. The old active site files were not renamed or overwritten. No Production runtime changes were made; private inactive files and backups were created in Production-hosting accounts by request.

See `evidence/PRIVATE-HOSTING-CANDIDATES-20261029.json` for the machine-readable source manifest and checksums.
