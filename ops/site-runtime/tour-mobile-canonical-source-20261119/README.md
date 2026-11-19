# Canonical WordPress MU source — mobile interaction fixes

This change promotes only the five live-proven first-party MU files
preserved by MAD4B PR #895 into the canonical repository path
wp-content/mu-plugins. Reference head:
5dade4d249ba828627a7445412e1f1cf27b2ac8d.
It does not change the attested PR #895 or overwrite later live code.

## Scope and ownership

The five MU files cover Elementor Sticky lifecycle and immutable JS,
JetEngine native Rate listing lazy footprint, Elementor Mega Menu direct
links, and lower booking/inquiry CTA visibility while the mobile/tablet
menu is expanded (through 1024px).

Original Elementor/JetEngine files, prices, WooCommerce, payment gateways,
booking forms and visitor preferences are untouched. Sticky replacement
only activates for the exact tested upstream vendor digest; unknown
vendor source falls back to original behavior. Other guards are
template/DOM-scoped and do not intercept clicks globally.

## Independent host deployment boundary

Host-specific systemd services, timer/path units and the bounded origin
cache reconciler remain recorded in PR #895. They are not WordPress MU
plugins. No host-specific paths or services have been copied here.

These WordPress MU files will automatically load wherever they are
subsequently installed. This PR is SOURCE ONLY, not deployment authority.
No WordPress or Production mutations occur from this Git worktree.

## Verification

Run in an environment with PHP and Node.js installed:

    python3 ops/site-runtime/tour-mobile-canonical-source-20261119/verify-source.py

The verifier matches exact source SHA-256 hashes, checks four PHP files,
three JavaScript scripts (one external plus two inline) and three
public/admin WordPress head-hook pairs. It rejects tenant/host coupling.
On this VPS, where the host PHP CLI is absent, the enrolled PHP container
may be passed via the MOBILE_QA_PHP_CONTAINER environment variable;
there is no WordPress or database bootstrap in these tests.

Browser acceptance evidence is in PR #895: EN/ES mobile, tablet menu and
dock behavior, direct links with native dropdowns, lazy Rate geometry,
and archive price fallback. This source step does not manufacture new
live acceptance or claim the intermittent physical Chrome all-click
freeze is cured.

## Release prerequisites

Before a governed runtime release, recompare latest installed bytes on
Tours.dev Staging and Wayfatours Production, avoiding any overwrite of
newer live updates. Review the new exact branch head with its own
authorized owner attestation. Independently certify browser interaction,
performance and bounded rollback. PR #895 approval is not transferable.
Manifest explicitly sets source_only=true and auto_deploy=false.
