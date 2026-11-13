# MAD4B Immutable Runtime — Source Implementation v1

## Ownership
The existing first-party Original 305/306/312/313 authority and Native Source
Publisher v2 remain the only execution authority. This is an additive image
candidate and fail-closed Blue/Green plan, never an alternative issuer.

## Real output (Wayfatours isolated build)

- Source artifact TAR SHA-256: f1bbe125343ab1a89426c46cecf3d12459651c2c3c35b6f2a49383409050c830
- Runtime plan SHA-256: 8ad52483514b49dd0ab1fa03a2f77b3abd3dfc26de3e0edcc7443b7ae7e07b71
- Base image ID: sha256:d72350f363109341f20d31e074b262f3b8a6cb3a8d165239f05efc64438319f8
- Candidate image ID: sha256:b08aad3689a987d1ec3cdfcfdc4298ec74c537e673d7ef878ab141f73e776fa6
- 23 real immutable PHP package files; no diagnostic PHP tests directory.
- The exact base image ID is resolved through a digest-derived local alias and
  checked before and after build; no registry pull or network dependency.
- Code path: /opt/mad4b/immutable-runtime/wp-content/plugins/ in the image,
  outside the existing live /var/www/html bind mount.
- Real isolated smoke: PHP lint, 41/41 schema and signed-authority negative
  checks. With UID 33, code and plugin dir are root-owned and not writable
  in a read-only, network-isolated container.

## Source contracts
1. Existing generic Git Artifact Builder: root-private TAR + digest manifest.
2. immutable_runtime.py: site-bound multi-layer OCI runtime plan.
3. oci_candidate.py: digest-checked OCI Docker build context.
4. runtime_controller.py: Blue/Green read-only admission; extensible dynamic
   provider role bindings and independently pinned first-party Registry
   manifest digests. Simulates fault recovery without changing real traffic.
5. Native Source Publisher v2 binds operation plans to the exact raw Provider
   Manifest SHA enrolled in first-party Registry, not a reserialized JSON hash.

Source-only code and registration metadata cannot issue an Original Grant.
Publisher, runtime start, health, traffic switch, rollback and independent
finalization each require an enrolled first-party operation. Additional
company-specific providers may be required by Site Policy.

## Production HOLD / next integration

This image contains one tenant Plugin layer, NOT WordPress core and all
remaining company packages. It is not a deployable replacement for the
current production WordPress container.

An authorized native runtime publisher must assemble full shared WordPress
plus company overlays while preserving separately writable uploads, cache,
configuration and database volumes. It must then create version B, prove
actual WP/Elementor/WPML/forms/booking and database compatibility, drain
requests on A, switch requests, preserve A for rollback and obtain real
independent signed Original 313 finalization. An OPcache reset or per-file
replace is not coherent cutover.

The source Publisher is not enrolled in the active Original root Registry.
No production Docker service/Traefik configuration, MySQL, WordPress files,
site mounts, grants or signer keys were changed. Only isolated build images,
root-private reports and source files were added.

The generated source.code.release Manifest is a source proposal.
Its pinned Evidence Schema is staged as source bytes under the corresponding
dynamic-operation-registry provider path, but no active Index was changed.

## Verified isolated HTTP Blue/Green experiment

The reproducible manual QA command is
ops/native-source-publisher-v1/tests/isolated_bluegreen_http_qa.sh.
It reads an already recorded site-bound runtime candidate and image ID,
creates ONLY disposable Docker QA containers on an internal, nonpublished
network, and switches a disposable Nginx configuration between PHP server
slots A and B. It always removes QA containers and the QA network afterward.

The executed Wayfatours *offline test harness*, with no WP database, used:
- Original PHP image A (local original Base ID).
- Root-protected candidate image B (local immutable OCI Image ID).
- Test-only PHP health script (mounted read-only into each container).
- QA-only Nginx, with a separate configuration bind mount.
- Four observed HTTP responses: initial A, post-switch B, in-flight A
  completing on the original pool, and post-rollback A.
- Evidence report SHA-256:
  dc147c7a96f74f24c6dd76eff9ac25e025064fd9488a936df82cfeb7a6346a2f.
- No published host port, no production Traefik modification, no WP writes,
  and no Original grants or signing keys issued.

It is a *real local HTTP routing test* and a useful integration fixture,
but **NOT** real WordPress/FPM/caching/DB/schema correctness proof. PHP used
the CLI built-in development server for QA; Original 312/313 and a real
site-scoped front-door traffic provider remain required for Production.


## Actual isolated PHP-FPM + OPcache HTTP experiment

An additional QA harness \`tests/isolated_bluegreen_fpm_qa.sh\` uses *real
PHP-FPM pools* in separate A and B containers, a disposable Nginx FastCGI
router, root-owned read-only plugin files and a private Docker network.
It is executed only when the already-built, exact-plan-labeled candidate
Image is supplied; no Production router or running service is touched.

A private permission bug discovered and fixed before successful QA: PHP-FPM
workers run under UID 33 and cannot traverse a root-private QA directory
mounted as their script root. Test code is now staged in a separate
readable, test-only \`public/\` subdirectory while evidence remains private.

The real HTTP observations are again A → B → in-flight A → rollback A.
The subsequent hardened run also asserted \`PHP_SAPI=fpm-fcgi\`, effective
worker UID 33, OPcache running in both FPM pools, and root ownership and
non-writability of candidate plugin PHP from the PHP worker. Evidence SHA:
\`cc3e6d80d5982aaf3b0e0758943e48c8554cc97bca4ee5323735bd71043cb67f\`.

This proves only the isolated FastCGI routing fixture, not the Production
PHP-FPM pool, the complete WordPress application, persistent data,
PHP/JS cache coherence, or original signed 312/313 release authorization.
