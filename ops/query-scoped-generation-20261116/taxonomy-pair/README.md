# MAD4B v1.4.3 — taxonomy/post-type scoped membership generation

Root cause observed on Tours.dev, 2026-11-16:
JetEngine Terms Query 291 selects the public destinations taxonomy with
destination_type=place_attraction and hide_empty=true. Old query generation
used a generic membership revision for each associated post type. Editing
the package_levels relation of a tour bumped tours-and-activities membership
and unnecessarily invalidated the separate destinations terms query,
its signed Lazy certificate, and related cache.

Fix: New membership_taxonomy dependency keyed by opaque taxonomy::post_type
pair. On a published post's changed term relationship, only the edited
taxonomy + associated public post type is invalidated. For publish/unpublish
and deletion, all taxonomies registered to this public post type are
invalidated conservatively, preserving multilingual hide_empty/count results.
Real term changes and term meta edits continue to rotate taxonomy revision.
Real post content and Elementor edits still rotate post-type revision, while
unknown or sensitive queries retain original global safety invalidation.

The generated attachment meta _mad4b_watermark_fingerprint_v1 is a periodic
media scanner fingerprint, not the underlying alt, image, rights or gallery
source, and no longer invalidates public query proofs. Other metadata
retains normal treatment.

Version structure changes once, so older certificates automatically fail
closed to SSR until verified again by the seven-viewport signed native
JetEngine AJAX/SSR worker. Old proofs are not relabeled as newer proof.

Test: bash ops/query-scoped-generation-20261116/taxonomy-pair/tests/run.sh
Also run original isolated scope/member event contract tests in PHP runtime.

Rollout: source SHA validation, backup, atomic deployment to Tours.dev staging,
verify live WP and public HTML. Wayfatours Production has a newer Governor
v1.4.5 and must not be replaced by this staging 1.4.3 file.
