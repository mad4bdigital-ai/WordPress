# Exact native company owner activation compatibility: rc.128 Staging

Tours.dev uses Control Plane 0.4.0-rc.128, while the legacy Company Portfolio
Manager owner activation admitted only 0.4.0-rc.109. The bundled JetEngine
page schema was missing from the addon directory. The exact canonical
data/company-communication-page-schema.json of the installed Control Plane
was verified against Git SHA256 6fc45de0..., 24 fields, and the expected
company-communication-operations slug, then restored into the addon data
directory without modifying the schema contents.

A minimal one-line source patch expands the version allowlist only to
the two exact known versions (rc.109 and rc.128). The entire original
Staging-only browser auth-cookie, REST nonce, explicit owner confirmation,
plan digest, DB lock, JetEngine schema and rollback guards remain untouched.

Live WordPress preflight after restoring data and applying this patch:
can_apply=true, blockers=[], installed release=0.4.0-rc.128.
The stored role migration is still v3 and no Owner approval, role change
or write grant has been performed.

This overlay is a source-only, non-authorizing audit for the original
Company Portfolio Manager addon (not the Control Plane class). Preimage
and patched SHA256 values, Staging backups and readback receipts are exact.
The human owner must open the native Tools > Company Activation page,
review the plan and provide the site's authenticated browser approval.

This is not an Original313 production release or a workaround for missing
Wayfatours App ID.
