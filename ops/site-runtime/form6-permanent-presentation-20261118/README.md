# MAD4B Form 6 — permanent source-owned accommodation presentation

This is an **exact live-source change slice**, not a wholesale rewrite of the
Control Plane, pricing authority, Elementor template, or Form 6 schema. It was
reconciled on Tours.dev and Wayfatours Production on 2026-11-18.

## Decision authority
- WordPress shared_form6_accommodation_policy_v1 emits
  shared.form6.stay-ui.v1 per **verified Rate/stage**, not inferred from
  single/double/triple, tour type or calendar alone.
- The selected Tour Rate remains the owner of a sole Property. An inquiry-only
  simple_or stage with one Property and zero nights has no separately
  selectable room/meal details, while its source-verified Property ID remains
  available for staff quote review.
- Real multi-Property / multi-stage / included or additional stays retain the
  server-verifiable verified_catalog_only path. The browser only creates
  room/board controls after the first-party stay catalog returns valid units.
- Selection, supplier prices and booking availability remain server-validated;
  the UI contract is **not** a commerce authorization.

## Why the file has a hash
The canonical presentation-fluent-quote-v1.js is the only editable source.
Its content-addressed, immutable .js copy is generated automatically.
The PHP loader computes the hash and serves that file if verified. A new source
revision changes the public asset URL, so month-long Chrome cache cannot keep
the old Form 6 controller.

## Automatic operations
runtime/company-form6-asset-cache-reconciler-v1.py (host root only) checks
the canonical JS, PHP stage policy, and loader, verifies syntax, atomically
publishes the content-addressed copy and uses the existing **enrolled public
route generation socket** to invalidate affected HTML only when source bytes
change. Site-specific .path watchers and .timer fallbacks are enabled on
both hosts. They do not modify WordPress DB/Fluent submissions/Woo orders.

## Safe Git reconciliation
master contains older package snapshots than the enrolled live source.
patches/source-live-form6-policy.patch is grounded in the exact before and
after live bytes with SHA256 preconditions in manifest.json. Reconcile this
slice into a newer source-code release with 3-way review; **do not** deploy the
older master module as-is. Review the related package, runtime mirror, and
company-specific loader independently, preserve other live changes, and run
the test suite before any governed release.
