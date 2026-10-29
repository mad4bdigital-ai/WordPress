# MAD4B central company REST federation

Scope: tours.dev.mad4b.com is the central administrative Staging site. The three companies' WordPress databases and media remain owned by their existing hostings. There is no full database import.

## Pinned company endpoints

| Company ID | HTTPS root | Company home-page variant |
| --- | --- | --- |
| all-royal-egypt | https://www.allroyalegypt.com | multi_country_tours |
| egypt-tour-gates | https://egypttourgates.com | daytour_your_way |
| tourism-almallahgroup | https://tourism.almallahgroup-mg.com | discover_nile_your_way |

The verified third-company hostname is tourism.almallahgroup-mg.com, not tourism.almallhgroup-mg.com.

## Installation and contract

Central file: central/160-mad4b-central-companies-rest.php is a WordPress MU plugin enabled only when the site hostname is tours.dev.mad4b.com. Run the seed() method using authenticated local WP-CLI to create records in the Staging company registry.

Remote file: remote/160-mad4b-remote-company-identity.php must be placed in wp-content/mu-plugins on each enrolled company WordPress site.

Provision a different cryptographically random 32-byte shared secret for each company, transmitted over enrolled pinned-key SSH only, never exposed in GitHub or REST responses. The non-autoloaded WordPress option names are:
- Central: mad4b_central_company_rest_secret_v1_<company-id-with-underscores>
- Remote: mad4b_company_remote_rest_secret_v1

Central administrative REST:
- GET /wp-json/mad4b-central/v1/companies
- GET /wp-json/mad4b-central/v1/companies/{company-id}/status
- POST /wp-json/mad4b-central/v1/companies/{company-id}/verify

These require a locally authenticated WordPress administrator with manage_options. The remote endpoint GET /wp-json/mad4b-company/v1/identity requires HMAC-SHA256 (method, exact route, tenant, timestamp, nonce), 120-second time tolerance and one-time nonce replay prevention. The remote endpoint reveals no customer, account, database or order information.

Central remote verification uses exact hardcoded HTTPS URLs, safe WordPress HTTP client, TLS verification, zero redirects, bounded response size and exact host/company/contract checks.

## No broad remote authority

An authenticated handshake proves company_identity.read only. It does not grant remote plugin, user, price, order, billing, media or WordPress database mutations; these are explicitly disabled in identity and registry responses. It is not a generic remote command or REST proxy.

Historical Nile-cruise source records require separate narrowly authorized read contracts:
- nile_cruise_catalog.read: public tour records and itinerary references
- nile_cruise_categories.read: source taxonomy and translations
- nile_rate_history.read: historical rate source (reference_only/not_for_sale)

Those three content routes are planned, not implemented or granted by this pairing release. Prices must never silently be used in the current checkout. The source company remains the content authority.

## Safeguards and acceptance

Require source hash/PHP syntax verified, backup/rollback, unsigned remote access denied, exact site/company handshake, central admin-only company list, valid signed challenge for each company and 200 health for the three company websites. Avoid overwriting preexisting plugin files. Production cutover, DNS, media, full database transfer, user/order export and remote writes remain explicitly out of scope.

Rollback: remove newly installed MU files, invalidate the per-tenant pairing secrets and mark central status unverified. Remote WordPress sites and local content stay in place.
