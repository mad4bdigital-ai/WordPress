# MAD4B Woo session public-shell shadow: site-specific Nginx adapter

Scope: Wayfatours Production, 17 Nov 2026, Nginx loopback listener
127.0.0.1:18081 inside the Nginx container. This is a read-only isolated
proof, NOT public Woo-cookie cache authorization. Traefik routes port 8080.

## Two genuine original WooCommerce guest sessions

WC_Session_Handler created two durable synthetic empty-cart guest sessions
with original valid Woo HMAC cookies. Each session ID was independently
verified against the real Woo Store API Cart-Token user ID, while HTTP
responses remained private/no-store. No product, order or payment was made.
Three synthetic test rows (including a failed earlier case) were then
deleted via the native WC_Session_Handler::delete_session method.
All cookie files were destroyed and never committed to Git.

Normal public listener 8080 returned BYPASS + private/no-store for signed
Woo sessions. An isolated canary marker plus a signed-shaped single Woo
cookie, mobile GET, no query/auth/sensitive headers and exact home path
/ or /es/ allowed a read-only HIT of already-certified anonymous HTML on
the local-only listener. Four combinations (2 x 2) returned bytes exactly
identical to the anonymous HIT and retained browser private/no-store.
The original fastcgi_no_cache rule was NOT modified and disallows storing
a private upstream response on a cache miss.

Seven negative cases remained BYPASS: real Production port 8080 despite
canary header; missing header; invalid cookie; Authorization header;
currency header; extra unknown cookie; archive route. The canary has no
public Traefik entrypoint.

The HIT object delivered exactly one Critical CSS tag, one async CSS link
and one inline CSS loader. Direct origin with Woo session contained 136
CSS links and 82 script src tags on one home sample versus approximately
2 CSS and 12 JS for the optimized anonymous shell. These are HTML tag
counts, not measured browser Web Vitals.

## Six-route semantic parity (does not authorize public cache)

Two real sessions and two ordinary non-Woo control HTTP requests covered
EN/ES home, EN/ES archive and EN/ES one published tour. Across 12
comparisons, visible text multisets, public link multisets, forms and
selected options matched. Hidden inputs matched in 9/12. The only changing
hidden field was the signed MAD4B age-context token in Fluent Form #6;
the same token varies between independent ordinary anonymous responses
because its expiration is based on current time. Existing Tour JS refreshes
it using the separate private/no-store age-occupancy form-policy API
on every page load.

This is not proof for nonempty cart, authenticated users or future market,
pricing, tax, or Elementor changes. It does not authorize cache policy
changes on archives, tours, checkout, forms or payment endpoints.

## Contract, source and rollback

The audited wayfa-shadow-edits-v1.json and build-loopback-config.py
generate a source candidate but refuse to overwrite an active Nginx
configuration. They bind the exact original and proposed SHA-256
files. The change adjusts a read-side key suffix and
cache bypass ONLY when every local loopback canary gate matches.
At public port 8080, old cache keys, cookie veto, browser headers and
FastCGI write-veto rules are unchanged. Never widen the selector to 8080
or remove the canary marker without separate nonempty-cart browser QA.

The host-only rollback script:
 /opt/mad4b-company-transport/cart-shell-shadow-20261117/rollback-loopback-only.sh
restores the exact preimage and reloads Nginx after syntax verification.

Company-neutral engine name is MAD4B; the shadow adapter references existing
Wayfatours Nginx variables because the underlying running config is local
to that company. It is not to be deployed blindly to other companies.

The source generator only writes a separate candidate file after an
exact original SHA-256 check. Deployment to another site must start from
its own independently verified Nginx preimage and privacy proof.
