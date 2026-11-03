# Wo Vacation DEMO company overlay (source governance only)

This is a **non-deploying source contract** for the Staging site
`https://tours.dev.mad4b.com`. It separates site branding from MAD4B core.

It does not alter WordPress options, Elementor widgets, JetEngine data,
Rank Math, WPML, media attachments or production installations.

- Identity: see `identity.json` (demonstration only).
- Company field sources: see `field-sources.json`.
- Live-vs-Git source inventory is held on the VPS, not copied into this
  repository or treated as canonical source.
- Legacy-branded media requires provenance/rights review before
  replacing or relabeling it. Do not erase origin, lie in alt text, or
  promote third-party samples to Wo Vacation's real catalog.
- For any cloned company: allocate a NEW Site UUID and per-company
  credentials; never inherit prior-company tokens, contact channels,
  testimonials, prices, or operational permits.
- A Wo Vacation demo at this staging domain does **not** authorize
  modifying `wovacation.com` production.

## Verification procedure

Use `docker exec -i etg-commerce-staging-php php <
ops/site-company-overlays/wo-vacation-demo/verify-live-identity.php`
from the Staging VPS. The checker exits with code 42 on wrong environment,
database, domain or Site UUID, and code 2 on conflicting source identities.

The checker deliberately returns `IDENTITY_ALIGNED_MEDIA_PROVENANCE_REVIEW`
while old-company attachment metadata exists; it is not evidence of
ownership transfer or a production-ready brand. Attachment metadata must
not be mass-replaced as a substitute for asset rights and media review.

The private, hash-only inventory under
`/opt/staging/mad4b-wo-vacation-source-governance-20261029`
is a **snapshot**, not a list of approved source files to import.

## JetEngine company Options Pages

Two source-backed page contracts are now keyed in `field-sources.json`:
`contact-and-help-info` (17 field keys) and `look-and-feel` (39).
Only field **names/classifications**, not stored option values, are included.
The 2026-10-29 discovered policy evidence is SHA-pinned and stays on
Staging VPS. `TENANT_FIELD` / `TENANT_MEDIA` values must be provisioned
for the new company, `RECONNECT_PROVIDER` requires new owner consent, and
unknown fields default to `BLOCK_REVIEW`; do not replace them wholesale.

## Site Profile rotation / authoritative binding (2026-10-30)

A fresh read-only profile verification resolved the current Staging Site UUID
to `e5e64bca-ed62-48d2-b2b3-dff693cd702a` (revision 8), with exact
domain/environment/deployment binding and no profile blockers. The former
observed UUID `4ef86619-35a5-4690-aa3e-6f298994965e` must not be used
for authorization, source-to-tenant mappings or publication. No site profile
change is made by this PR; checks read the live profile and block stale IDs.

## Runtime-free company provisioning source gate

`company-clone-gate.php` is a **pure, isolated dry-run planner**, not a hook
in the live provisioning service. It pins the existing 56 JetEngine field
classifications and the approved field-policy census hash. Unknown fields,
changed policy maps, foreign/stale Site UUIDs, production targets, or attempts
to submit old company field values are blocked. It emits *field source
decisions*, never source data, media bytes, credentials or an apply permit.
Source policies are validated at the exact known Staging site. Future
integration into the actual provisioning path requires a separately reviewed
service adapter and per-company acceptance tests.

Run tests using a locally installed PHP CLI or the Staging PHP container:
`php ops/site-company-overlays/wo-vacation-demo/test-company-clone-gate.php`.

## Homepage attachment provenance check

The CLI-only `audit-homepage-media.php` reads explicitly supplied attachment
IDs from a verified Staging WordPress instance, verifies environment,
database/domain and the latest authoritative Site UUID, and writes no database
rows. The summary in `media-provenance-review.json` is a **review queue**,
not an assertion that visible images are unlicensed. Five home-page images
still contain the previous brand in attachment title and alt text; zero
rights-related **attachment meta keys** were found on those five assets.
Other rights storage was not certified by this check, so no new usage rights
are inferred. The full read-only evidence stays locally on the VPS and
must not be mistaken for user authorization to relabel, remove or republish.
