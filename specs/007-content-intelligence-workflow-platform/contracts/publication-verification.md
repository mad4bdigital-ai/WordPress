# Contract — Publication Verification Plane

Contract: mad4b.publication-verification.v1

## Purpose

Prove that the intended content is actually visible and correctly represented beyond the WordPress database mutation.

## Verification stages

1. origin object readback;
2. origin HTTP fetch;
3. public/edge URL fetch;
4. rendered HTML extraction;
5. content fingerprint comparison;
6. SEO/indexability checks;
7. structured-data checks;
8. language/canonical/hreflang checks;
9. media availability checks;
10. sitemap inclusion/removal where policy expects it;
11. cache/CDN propagation evidence;
12. final PublicationEvidence.

## Verification profile

Configurable by content/site:
- expected status code;
- eventual-consistency window;
- required canonical;
- robots/indexability;
- title/meta requirements;
- structured-data types;
- required content markers/fingerprint policy;
- language cluster expectations;
- sitemap expectations;
- edge locations/providers optional.

## Eventual consistency

A publication can be:
- PENDING_PROPAGATION;
- VERIFIED;
- DEGRADED;
- FAILED.

Bounded retries are allowed during the propagation window.
After expiry, unresolved required checks fail verification.

## Cache/CDN

Verification distinguishes:
- origin state;
- edge/public state;
- stale cache.

Cache purge is a separate governed capability where supported.

## Rollback/containment

If a high-risk publish verifies incorrectly:
- policy may unpublish/rollback through existing governed mutation;
- or activate a publication kill switch;
- evidence preserves both intended and observed states.

## Search engine indexing

Actual third-party indexing may be asynchronous and is not claimed merely from publish success.
IndexStatus is a later observation artifact.

## Acceptance

WordPress post_status alone is never sufficient PublicationVerification=PASS.
