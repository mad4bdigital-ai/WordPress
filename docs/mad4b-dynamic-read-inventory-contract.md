# MAD4B Dynamic Read Inventory Evidence — Contract

**Status:** staged read-only diagnostic enhancement; not a permission, write certification, or site release.

## Why

`MAD4B_SCP_Site_Capability_Discovery::observe()` previously reported `discovery_complete=false` when WordPress plugin or theme version headers were unavailable. That must remain a blocker for established version-sensitive workflows, but it should not hide a trustworthy bounded inventory of **plugin names, post types, taxonomies and provider recognition** needed for read-only troubleshooting.

## Additive fields

- `read_inventory_state`: `observed`, `observed_without_version_attestation`, or `partial_or_unavailable`.
- `read_inventory_observed`: true only if no non-version inventory, origin, identity, schema or budget blocker exists.
- `read_inventory_blockers`: all blockers **except** missing plugin/theme version evidence. Does not erase the canonical `blocking_reasons`.
- `read_inventory_non_authorizing=true` and `read_compatibility_certified=false` on every response.

**Preserved without changes:** `discovery_complete`, `blocking_reasons`, `plugin_versions_complete`, `theme_version_complete`, `snapshot_sha256`, certification and mutation-policy semantics. No new provider, route, OAuth scope, write or release gate.

## Security model

A diagnostic observation is neither proof that a plugin is clean nor evidence that its methods conform to a certified behavior contract. A version change may be benign, but read **compatibility** must still be established by existing `Provider Compatibility Certification` and `Provider Health View` (separate PR #405). Missing/unsafe plugin basenames, foreign origin, inventory overflow and invalid taxonomy/post-type schemas remain `partial_or_unavailable`.

## Tests

`php wp-content/plugins/mad4b-site-control-plane/tests/site-capability-discovery-contract.php` passed in hermetic PHP 7.4 and PHP 8.3 fixtures for known versions, missing theme/plugin versions, unsafe path, invalid origin and inventory overflow. `validate_spec.py` passed. This is **not** live Staging provider testing.

## Rollout

Independent draft PR to `master`; CI/owner review required before merge. Then inspect consumer call sites and prove read-only behavior in Staging with the same Site Profile identity and resource limits. Production mutation remains forbidden without explicit owner authorization.
