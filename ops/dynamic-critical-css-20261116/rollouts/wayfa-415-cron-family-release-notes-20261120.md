# Wayfatours 415 + site-owned Cron + V2 family source proof

Source-verified MU 415 was updated on Wayfatours Production to v0.3.29 from Git commit f7e4ddc40a768485358cafe5e9e2e7dd135c5cc4, SHA 75e305345f46af0ec4dbed36f40def63626108de85f938238853b27674c11794. The preimage and rollback receipt are retained. Current Home manifest is v1 with 28 certified CSS source entries, an original supported compatibility mode. After rollout, both EN/ES homepage native origin probes returned HTTP 200, Mobile CSS Ready 1 and inline critical CSS. Home-only FastCGI namespace was safely updated with nginx -t and reload.

The original health-audit assumed the retired global CSS Cron and an outdated Wayfa 415 pin. Current native scheduling is per-site with separate cron entries, a shared execution lock and independently enrolled WordPress dispatcher 429. The corrected audit validates the exact source-owned company cron text, worker path and root ownership, exact 429 Git PHP hash, site-local PHP UID 33 as applicable, WordPress next tick and retired systemd timers. Wayfa full audit now reports 0 errors and 2 advisory v1 stale-context warnings.

The legacy source-family registry ignored certs-v2. The new read-only family observer now checks actual immutable CSS sources, source file and critical slot SHA, first-party 422 runtime and consumer, locale/currency/market, current source epoch and generation and completed post-release browser QA. Provisional and revoked certificates fail closed. It grants only source-family observations, NEVER route CSS to a sibling tour. Three certified v2 route sources currently have different Elementor document fingerprints and remain distinct singleton variants. Resources prevented the latest scheduled family WP-CLI refresh; the ticket remains queued.

Private receipts:
- /var/lib/mad4b/company-code-release/receipts/wayfa-home415-source-20261120/
- /var/lib/mad4b/company-code-release/receipts/wayfa-css-site-owned-audit-20261120/
- /var/lib/mad4b/company-code-release/receipts/wayfa-css-v2-family-source-20261120/

Direct VPS deployment only. No signed Broker grant, no PR merge, and no source-staging production activation.
