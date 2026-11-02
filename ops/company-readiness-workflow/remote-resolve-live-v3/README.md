# MAD4B Remote Resolve Live Evidence Bridge V3

This integration is a **bounded, read-only runtime bridge**, not a new WordPress
or host authority issuer. It consumes Original Core and existing signed Cross-SSH
and Host Proof reports on the central VPS, where the trusted provisioning timers
already operate. It continuously emits an exact-company next action even if an
optional provider or a different company's site is unavailable.

* Source of authority: original Core registered company, environment, Site
  Profile UUID, Original 305/306/312/313. No staging-to-production identity copy.
* Source of connectivity: pre-enrolled pinned SSH/WP-CLI and signed host
  readbacks. Verified WP-CLI connectivity **does not imply permission to write**.
* **Production with missing Site Profile** stays in automatic identity-recovery
  state. The original site owner/profile issuer must supply signed, exact-site
  evidence before an executable grant can exist.
* One delayed provider does not prevent another company's refresh.
* Central Tours demo is a separate managed-site role from the central controller.
* Output is root-protected and atomic; no credentials or raw SSH output.
* No new grants, permission elevation, 306/312 dispatch, or 313 assertion.

## Acceptance

Run `python3 -m unittest discover -s ops/company-readiness-workflow/remote-resolve-live-v3 -p 'test_*.py' -v`
then inspect the original root-protected source reports. Installation is through
approved source/artifact promotion with Staging canary and rollback. Do not copy
unreviewed code directly onto Production hosts.

The service/timer manifests are templates and **not active just because source
is merged**. Activation needs the authorized MAD4B update channel, service
readback and a signed acceptance record.

## Remaining integration for completely unattended enrollment

Use the **existing original authenticated issuer**, not this monitor, for
site-owned Signed V4 profile bootstrap and exact owner/manager enrollment,
original 305 transport activation and 306/312/313/314 receipt verification. These
are distinct authorization operations. Until the original issuer accepts a
site-specific plan, retry safe discovery; do not mint site UUIDs or grant access.
