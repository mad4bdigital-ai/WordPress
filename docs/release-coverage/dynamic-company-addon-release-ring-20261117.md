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

The original Company Portfolio Manager source tree was recovered in #837:
86 first-party source files (82 PHP), pinned by exact SHA-256. Pre-Staging now
correctly rejects 99 raw mutation/HTTP/DB/file textual matches in 33 PHP files.
These matches include a few test-only or non-write expressions, but real host,
role, DNS and WordPress writes still require original owner operation-level
review. No certified provider/Add-on pair exists and unknown sites stay shadow.

A non-authorizing original-PHP test checks company domain scope, exact browser
nonce/session, and restricted Cloudflare REST write routes. It was tested on
PHP 8.3 and 8.5 with network disabled and WordPress writes mocked to fail.
The Pre-Staging runner executes those security tests separately and retains
the explicit mutating-source admission failure until the original operations
have independent authority and negative tests.

Tests include PHP 8.3 and PHP 8.5 exact-pair and negative cases, the
WordPress add-on source contract, the dynamic ring policy source contract,
and the read-only Pre-Staging report.

No WordPress site, account, role, App ID, or Production release was changed.
