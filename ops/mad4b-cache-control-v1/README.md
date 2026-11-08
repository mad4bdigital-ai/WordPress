# MAD4B Cache Control v1

**Deployed to Tours.dev Staging and Wayfatours Production on 2026-11-08.**

A WordPress Tools → MAD4B Cache Control interface. It previews public
page, tour, blog post and taxonomy URLs, expands WPML translations,
and uses the already-enrolled local **FastCGI path generation socket**
to invalidate exactly the selected public URL paths. It does not
execute Redis FLUSHDB or delete the entire Nginx cache directory.

## User flow

1. Search by title or taxonomy term (or supply a known post / term ID).
2. Inspect all resolvable WPML-localized canonical paths and the policy
   readback. Native page dependency graph data is shown as **read-only
   hints** because graph coverage may be incomplete.
3. Preview token belongs to the current administrator, expires after
   600 seconds, and is used only once. The server recalculates the
   affected URLs and policy fingerprint immediately before apply.
4. The site-local `.mad4b-purge/origin.sock` advances the generation
   of exact paths; the Nginx `$mad4b_fastcgi_version` key then changes.
   Outcome and counts are stored as a WordPress admin receipt.
5. No WordPress price, booking or customer records are mutated.

## Cache inheritance and safety

- Tours.dev has `MAD4B_Company_Policy_Registry_V2`; the UI shows
  effective `content.cache.scoped` strategy and rule origin.
- Wayfatours does **not** have Registry v2 installed; the UI explicitly
  reports `NOT LOADED — local fallback only`. A manual selected-path
  operation is **not** evidence of complete company policy inheritance.
- Existing Page Dependency Cache policies are shown, but unknown edges
  never silently become an assumed complete path set.
- The separate pricing inheritance engine remains authoritative for
  cacheable *policy definitions*. Native quotation, currency, availability
  and checkout calculations stay private/dynamic.
- The 1800-second public FastCGI TTL remains configured by Nginx; this
  WordPress panel **does not change that TTL** or override Nginx bypass,
  cookies, sessions, Geo, market or WooCommerce rules.
- Safety gates include manage_options, nonce, same-admin preview token,
  one-time execution, same-host canonical URLs, strict path validation,
  private Woo pages and their WPML translations, localized cart/checkout
  endpoints, no arbitrary URL input, limited batch size (80).
- The socket response must confirm each versioned path; partial or
  unavailable responses are reported, not silently treated as success.

## Verified live behavior

- The identical PHP file is installed on both sites, with PHP syntax PASS.
- Tours.dev homepage preview includes /, /ar/, /fr/, /de/, /es/, /it/.
- Wayfatours homepage preview includes / and /es/. A published tour
  preview produced both EN and ES canonical URLs.
- The dashboard rendered in authorized simulated WP-admin on both
  sites with a preview action, nonce and readable labels.
- **Real socket path-generation tests**: a temporary, otherwise unused QA
  path was versioned twice on Tours.dev (size 1 → 2) and once on Wayfatours;
  its disposable generation markers were removed.
- A real authorized WordPress `admin-post` preview generated a six-path
  token for one published Staging tour.
- The follow-up `admin-post` **apply test was blocked by a safety gate**.
  No customer path was manually purged in this step and no end-to-end
  WordPress admin apply receipt has been proven. The observed receipt
  count was 0 on both sites at last inspection.
- Both public homepages returned HTTP 200 after deployment.
- Independent pricing cache observer previously returned PASS 2/2.

## Open work

An approved interactive WordPress admin confirmation should validate
the full Preview → Apply → Receipt workflow. Wayfatours still needs
independent compatibility review before full Dynamic Policy Registry v2
is enrolled; do not copy Tours.dev's unrelated pricing/adaptive modules.

**Do not assume this package is auto-enrolled for every future company.**
Company provisioning must verify the host socket and approved policy
registry separately. Do not merge over site-local code blindly.

VPS evidence:
`/opt/mad4b-company-provisioning/reports/cache-control-admin-20261108/`
