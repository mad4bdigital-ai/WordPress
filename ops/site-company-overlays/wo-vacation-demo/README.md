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
