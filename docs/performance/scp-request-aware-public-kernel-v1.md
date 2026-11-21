# MAD4B Control Plane — Request-Aware Public Document Kernel v1

Status: implemented in the source branch, disabled by default. No live
site has been switched to the kernel through this branch.

## Architectural reason

The Control Plane loads hundreds of PHP definitions on a normal visitor GET,
while only a small subset participates in site identity, market, presentation,
policy and booking. Its previous early-exit contracts already protect foreign
REST, AJAX and certain administrative reads, but not canonical public tour
documents.

This implementation separates route admission from security, capability and
commercial policy. Request ownership never grants authorization.

## Invariants

The original entrypoint loads managed AI credential restrictions, deactivation
lifecycle and Tour Highlights hooks before the optional public kernel. An
ambiguous, unauthenticated-but-stateful or private request always uses the full
bootstrap. A public GET must have no query string, cookies, authorization,
nonce, speculative navigation, client-selected market or other tenant-specific
headers; its origin must match the configured site, with an enrolled and
correctly bound Site Profile and no pending mutation or quarantine.

The request owner comes from site-local options and exact path verification,
not hardcoded company domains, company IDs or Elementor template IDs.

The kernel retains the same Site Profile, MCP Request Scope, Market Profiles,
Switcher Sources, Market Context, Market Preferences and Company Operations
Policy write fences. Season Calendar is included as a definition only: its
admin-menu boot is not executed on frontend. The real market hook test asserts
the currency action, locale redirect, nonprivileged market submission,
metadata policy, and post-content policy remain registered.

No Nginx, FastCGI, Redis object-cache, public HTML response, travel booking,
WooCommerce, WPML or role policy configuration is changed by this patch.

## Site-local opt-in (separate approved change)

All three WordPress options must be deliberately prepared by a site operator:

- mad4b_scp_public_document_kernel_mode_v1: off by default.
  Allowed active states are canary (staging only) or on.
- mad4b_scp_public_document_kernel_prefixes_v1: explicit public singular
  permalink prefixes for that company, maximum 16, validated at runtime.
- mad4b_scp_public_document_kernel_verified_paths_v1: exact previously
  verified public paths for mode on, maximum 256.

A staging-only canary also requires the exact request-local header
X-MAD4B-SCP-Public-Canary: v1. A request header never grants a role,
commercial market, location, pricing entitlement or private session.
Production mode on is still limited to the exact paths already verified;
there is no wildcard bulk rollout in v1.

To disable, set the site-local mode to off; the full Control Plane codepath
is preserved and is selected automatically. Any source-level fault also
requires normal rollback of the changed source file to its pinned predecessor.

## Deploy and test, by increasing risk

1. Verify the exact source head and source parity. Run the kernel source
   workflow on PHP 7.4 and 8.3, including the 40-way request-classifier
   matrix and the standalone runtime and hook tests. Do not mix the old
   local master with the newer rc129 source branch.
2. Deploy the source update through the site's governed update mechanism
   with backup and attested exact hashes. This ships with the kernel off.
3. On a designated staging company, explicitly enable canary and the
   post-type prefix. Compare the same canonical no-query public URL with
   and without the staging header, bypassing HTML cache only via an
   independently approved cache testing contract. Confirm identical
   traveler-visible prices, currency, nationality, country selection,
   tax, deposit/quote UI, child/infant policy, form state and booking.
4. Validate visitors with ordinary cookies, signed country state, logged-in
   users, WooCommerce cart and checkout, and all admin/REST/AJAX/Cron/MCP
   endpoints. These must never enter the reduced public kernel.
5. Approve selected exact routes per production company, release with
   post-deployment readback, and revert mode to off on any correctness
   mismatch. Performance validation is post-release and non-authorizing.
6. A later feature can derive exact verified paths dynamically from a
   family-based acceptance registry; an unverified family must still
   use full bootstrap.

## Performance measurement

Prior read-only Wayfatours WP-CLI inspection measured 667 Control Plane
files, 494 WordPress SQL requests and approximately 248 ms between the
Control Plane entry marker and hook registration marker. Separately,
an isolated skip-plugin diagnostic showed about 0.87 seconds difference
in WP-CLI wall time, but that is not a prediction for HTTP TTFB.

The proposed initial engineering target, not an achieved result, is at
least 30 percent lower PHP processing time at p75 for equivalent
uncached public pages with the same OPcache, load and language. Judge
correctness independently: zero pricing/booking/auth/regional regressions,
zero missing security hooks and zero new PHP fatal errors.

## Limits of the current patch

The source code, manifest and hermetic tests are complete for an opt-in
singular public route kernel. An approved staging browser parity test and
a production deployment have not occurred. Other WordPress plugins and
Elementor/JetEngine rendering still account for much of the cold response.
This work must not be described as already reducing live Production MISS.
