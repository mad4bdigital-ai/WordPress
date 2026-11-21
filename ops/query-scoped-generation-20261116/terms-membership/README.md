# Scoped query generation: published taxonomy membership versus post content

On Tours.dev, query 291 is a JetEngine terms query against destinations
with hide_empty=true and destination_type metadata. It legitimately
depends on taxonomy terms and published content membership, but not
a draft tour's arbitrary price/content meta. The old scoped-generation
algorithm included a post-type content revision for every post type
registered against the taxonomy. A background write to a draft tour
on 2026-11-16 07:15:35 UTC rotated this revision and rejected previously
valid EN/ES Lazy proofs even though no destination card changed.

Version 1.4.3 separates source revisions by taxonomy and public post type:
- post_type remains sensitive to real post data (for posts queries).
- membership only rotates on real publish/unpublish status changes,
  published object/term assignments and published post deletion.
- Taxonomy terms and term meta continue rotating their own scopes.
- append=true never invalidates for an existing term reassertion, and
  reordered but equivalent relations are ignored.
- Unknown/public-permission/policy changes still trigger global safety
  invalidation; nonpublic/personal/commerce data never share cache.

The current listing Query Builder group is intentionally rekeyed once,
because membership now has a different dependency contract. Old browser
proofs must never be upgraded by merely copying their version field.
A new exact browser SSR-vs-signed-AJAX parity audit is required to
re-enroll any affected listing.

The isolated fixture tests 22 query-dependency assertions and 10 event
behavior assertions, including draft metadata, draft -> publish,
publish -> draft, no-op set/append terms, real term append and published
post deletion. Together: 32/32 passing.

The live staging source and the local Git copy must match by SHA-256.
The Wayfatours production source is left unchanged and must be reconciled
separately with its own ownership, version and live QA.
