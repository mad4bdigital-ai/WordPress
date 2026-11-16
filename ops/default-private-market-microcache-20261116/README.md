# MAD4B safe-default private market FastCGI microcache — 2026-11-16

The original production-grade market safety hold correctly blocked shared
HTML, but also blocked all anonymous public HTML. The staging WordPress
browser policy v1.3.0 contained a literal USD check, rejecting actual WCML
EUR market visitors even when Nginx could isolate them.

The forward-reconciled v1.3.2 policy uses first-party WCML geolocation
(country + currency), requires currency identity to match current WooCommerce
runtime, and denies mismatched, missing or user-forged market headers.
When a trusted site-origin FastCGI parameter
MAD4B_IP_MARKET_CACHE_GATE=v1 is present, eligible public HTML becomes
the default even in Production. A site without this attestation retains the
original production off default unless separately opted in. Existing
canonical-route/locale, session, cookies, auth, payment and private
commerce exclusions remain active.

Tours.dev's source-owned Nginx snapshot keeps the uncertified shared-market
HOLD enabled; its private FastCGI key partitions by public client IP,
full user-agent, Accept-Language, route/device/Accept variant and content
revisions. Nginx strips visitor-supplied country headers. Invalid and private
proxy client IPs cannot share cache. Origin eligibility, upstream private
responses and Set-Cookie still veto storage. The anonymous cached HTML is
never publicly browser/CDN-cacheable: private,no-store,max-age=0.
The internal per-IP FastCGI expiry is only 120 seconds.

Live evidence on Tours.dev for one IP and Chrome Mobile:
  EN cold MISS 4527ms; repeated HIT 20–24ms.
  ES cold MISS 4507ms; repeated HIT 19–23ms.
  Auth/currency/cookie requests BYPASS. Forged X-Forwarded-For kept
  the proper real-client cache key (HIT).
  Nginx syntax PASS; WP policy isolated identity mock assertions 10/10.
The cold PHP request is not faster yet. Wayfatours Production is unchanged.

The single-file Nginx bind mount is read-only: editing the host pathname
and reloading Nginx will NOT change the running container's pinned inode.
Recreate the company-owned Nginx service through its existing Coolify Compose
and confirm live config SHA after the new container becomes healthy.

On Tours.dev source, the Lazy Loading controls
mad4b_secondary_grid_lazy_policy, mad4b_secondary_grid_lazy_public_context_mode,
and mad4b_secondary_grid_lazy_heavy_terms_mode are explicitly set to auto.
The native renderer already uses auto defaults when absent. Missing
SSR/AJAX parity proofs continue to fall back to native SSR, preserving
conditional listing visibility and preventing CLS; no certificates are forged.

Preimage/rollback artifacts:
  /opt/mad4b-company-provisioning/ops/default-ip-market-cache-20261116/
  nginx.rollback.conf original SHA fe9105f74c2d4bb351594b5d8e42c1dc60fc42f046660e55f89d8b7aba84e48b.
  browser-html-policy.rollback.php original v1.3.0.
Do not copy the site-specific Nginx snapshot to other tenants; derive the
host, trusted proxies, markets and WCML runtime for each enrolled company.
