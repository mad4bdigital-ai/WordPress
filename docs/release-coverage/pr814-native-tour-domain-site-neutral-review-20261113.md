# PR #814 — Site-neutral review of optional Tour Highlights domain integration

**Status:** source-only policy review, non-authorizing / Production HOLD.

Site Neutrality CI reported two reviewed Tour Highlights files as
`FIXED_BUSINESS_MODEL`. The existing MAD4B site-neutral scanner already
supports *exact per-file tourism-domain reviews* and deliberately forbids
global allowlists or hardcoded business-model defaults in generic runtime.
Tour Highlights is an optional JetEngine/Elementor consumer whose current
Tour-specific editor save scope is `tours-and-activities`. Making it operate
on arbitrary CPTs just to pass a source scan would instead expand the
postmeta write surface beyond approved Tours, violating the principle of
least privilege and the current Company Term Eligibility checks.

The change adds only the two reviewed files to the existing
`reviewed_business_models` evidence registry with their exact literal Tour
CPT, documented as domain-specific rather than a core site-wide default.
The scanner itself is **unchanged**. No tenant URL, global CPT default,
private key, new ability or executable provider is introduced. The current
Tour CPT, editorial `needs_review` status, WPML approval and source link
checks all remain fail-closed. New company Tour CPT aliases can be adopted
through a future reviewed Company/Service Catalog descriptor rather than
unbounded runtime inference; this CI review does not register such aliases.

The entire Site Neutrality scanner passed over 826 runtime files, and 3
additional scoped regression tests confirm exact file approvals, rejection
of an unreviewed tourism taxonomy and non-tour files, and preservation of
Tour editor-only write and inactive suggestion behavior.

No WordPress sites were modified; no new release authorization, Source
Publisher Provider, Original312 Grant, or independent Original313 receipt
was created. This does not waive exact-head Owner attestation or live
production readback; PR #814 remains Draft and RELEASE HOLD.
