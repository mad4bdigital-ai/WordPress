# MAD4B Add-on release ring — site-bound dynamic policy

An environment (local, staging, production) describes a WordPress
installation. A release ring (shadow, canary, active) describes the level of
independently certified capability. Environment alone must never promote ring.

The Company Portfolio Add-on now has release_ring=dynamic and the closed
site_bound_certified_pair policy. Release certificates must match the enrolled
Site Profile UUID and full profile digest, the enrolled origin, exact provider
and Add-on versions, extension-points SHA256 and installed Add-on main SHA256.
Each pair specifies its lifecycle ring. Missing or mismatched proofs fall
back to shadow.

Canary and shadow do not yield normal mutation execution bindings. Even an
active exact pair does not inherit Production release authority: the registry
retains production_authorized=false and all original mutation/owner gates.

Legacy real lifecycle rings remain supported during migration, while the
illegal value release_ring=staging is rejected. Environment reclassification
without an enrolled, re-certified site cannot mint any permissions.

The catalog currently has ZERO certified pairs. No live add-on writes are
authorized as a result of this source change.

Open independent issue: the full original Company Portfolio Manager source
tree is not in the current GitHub integration branch. Pre-Staging correctly
reports source_root_missing. Do not substitute a partial proxy or an installed
Staging file for a reviewed, complete repository release.

Tests include PHP 8.3 and PHP 8.5 exact-pair and negative cases, the
WordPress add-on source contract, the dynamic ring policy source contract,
and the read-only Pre-Staging report.

No WordPress site, account, role, App ID, or Production release was changed.
