# Wayfatours 415 + site-owned Cron + V2 family source proof

Source-verified MU 415 was updated on Wayfatours Production to v0.3.29 from Git commit f7e4ddc40a768485358cafe5e9e2e7dd135c5cc4, SHA 75e305345f46af0ec4dbed36f40def63626108de85f938238853b27674c11794. The preimage and rollback receipt are retained. Current Home manifest is v1 with 28 certified CSS source entries, an original supported compatibility mode. After rollout, both EN/ES homepage native origin probes returned HTTP 200, Mobile CSS Ready 1 and inline critical CSS. Home-only FastCGI namespace was safely updated with nginx -t and reload.

The original health-audit assumed the retired global CSS Cron and an outdated Wayfa 415 pin. Current native scheduling is per-site with separate cron entries, a shared execution lock and independently enrolled WordPress dispatcher 429. The corrected audit validates the exact source-owned company cron text, worker path and root ownership, exact 429 Git PHP hash, site-local PHP UID 33 as applicable, WordPress next tick and retired systemd timers. Wayfa full audit now reports 0 errors and 2 advisory v1 stale-context warnings.

The legacy source-family registry ignored certs-v2. The new read-only family observer now checks actual immutable CSS sources, source file and critical slot SHA, first-party 422 runtime and consumer, locale/currency/market, current source epoch and generation and completed post-release browser QA. Provisional and revoked certificates fail closed. It grants only source-family observations, NEVER route CSS to a sibling tour. Three certified v2 route sources currently have different Elementor document fingerprints and remain distinct singleton variants. Resources prevented the latest scheduled family WP-CLI refresh; the ticket remains queued.

Private receipts:
- /var/lib/mad4b/company-code-release/receipts/wayfa-home415-source-20261120/
- /var/lib/mad4b/company-code-release/receipts/wayfa-css-site-owned-audit-20261120/
- /var/lib/mad4b/company-code-release/receipts/wayfa-css-v2-family-source-20261120/

Direct VPS deployment only. No signed Broker grant, no PR merge, and no source-staging production activation.

## Source-current CSS audit correction and First Fold closure

On 20 November at 17:53 UTC the live Wayfatours health audit
returned healthy_with_safe_fallback: 0 issues, 0 warnings.
The prior degraded warning was caused by legacy v1 historical
certificates for About Us and Interests using CSS Epoch 39, whereas
the current WordPress CSS Epoch is 252. Their ledger records retained
the old certified status, but the first-party source keys no longer
matched the current WordPress source identity. No historical v1 cert
was reactivated, reapproved or published.

Audit now only samples records with exact source_current route keys.
30 outdated rows are still recorded as stale_certified_source_records,
rather than interpreted as current CSS delivery failures.
Readback samples are selected across both EN and ES. Both actual
source-current public routes returned CCSS Ready=1 with exact
market-specific v2 certificates.

The audit now uses the same first-party verified Elementor template
variant index as the native company Cron family registry. It does
not merge different DOM variants into one reusable CSS grant.
The current site snapshot shows 239 routes, 24 source groups,
3 qualified singletons, 4 current certified v2 routes and
235 other routes without exact route certificates.

The source-corrected First Fold runner was independently observed
succeeding through WordPress Cron job 102. EN and ES homepage CSS
both passed, native event ledger committed and WordPress ACK sent.

Production Wayfa site renderer SHA after the health readback fix:
854a724f5c060fc72073d93682f38517b96dc1e1e6f4286dbe1aab2fff40765f.
Rollback backups and receipt:
 /var/lib/mad4b/company-code-release/receipts/wayfa-css-source-current-health-20261120/

The audit is read-only. Its result cannot qualify a different
route, bypass the native admission, or publish a CSS certificate.
No signed Broker grant, whole-site cache purge, PR merge, or
Tours.dev release was implied.
