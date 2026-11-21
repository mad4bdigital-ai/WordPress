# v131 Original318 — Central Staging site identity adapter

The Central source (`https://tours.dev.mad4b.com`) is the main platform, not a
Company Environment Registry V2 target. A v3 readback call for
`mad4b-central:staging` must therefore cross-check against the distinct
first-party Site Profile v2, with *separately pinned Root binding*.

This fixes only a false routing assumption in the additive Original318 v3
read-only loader; company sites still require the existing enrolled company
registry, and Central production cannot inherit the Staging exception.

For the exact Central site, the following must all match Root's immutable
trust binding before any signature verification:
- Pinned Root `company`, `environment`, `site_uuid`, `origin`, SSH host ID,
  SSH principal, host public-key fingerprint, and policy fingerprint.
- `get_option('mad4b_scp_site_profile_v2')` contract v2, version 2, site
  UUID, `staging` environment and canonical origin.
- The site's live deployment-binding digest, compared with Root's separate
  `MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256` constant.

WordPress Site Profile is a **cross-check**, not a signer, issuer or trusted
Root source. Neither the signed enrollment nor independent Original313
high-water can be fabricated by editing that option. Existing Original312/
Original313 independent signed verification remains unchanged; no default
Host Write or production authorization is added.

The existing Root Trust constants cannot be created by a WordPress admin
option. They must be installed by a first-party Root publication with actual
public signers and an immutable site binding, and protected from mutation by
the WordPress runtime. Do not install a signer key or unsigned v3 record in
WordPress to make a failing reader pass.

The following cases are verified in real PHP 8.5 fixtures: exact Central
success of *identity validation only*, Central UUID/drift/origin rejection,
Central Production isolation and original company registry acceptance/drift.

Live status in this PR: Source candidate only, not independently signed
Owner/Original312/313 v3 enrollment. Install this narrowed loader only after
exact SHA and fail-closed PHP regression tests; retain preimage for rollback.
