# Site-owner source provenance (non-deployable)

This directory records **the currently active original PHP owner files** on
registered company runtimes, where one function is implemented by a MU file on
one tenant and by a native plugin module on another.

It is deliberately **not** an executable release catalog or an alternate plugin
loader. The verified snapshot can be used as an input to the existing original
MAD4B 305/312/313 Company Code Release workflow, but it is not a grant or a
signed independent readback. Its release_boundary is strictly non-deployable.

## Contract

The registry.json file discovers registered capabilities, their PHP function
symbols, site/environment variants, actual runtime paths, owner kind and
expected SHA-256. Each variant points to a byte-for-byte versioned source
artifact. No runtime paths, capability identifiers or company names are
hardcoded in the validator.

The validator rejects duplicate active owners, duplicate function ownership,
unauthorized paths, symlinked sources, modified bytes and any attempt to relax
the source-only release fence. It never changes WordPress, files, permissions,
caches, settings, bookings or customer data.

The current distinct owners are the destination_activation capability
(destinations-compatibility/includes/activation.php, same original on both sites)
and destination_navigation (Wayfatours MU vs Tours.dev mad4b-platform module).
The two navigation variants must NOT be automatically merged: their behavior
and layout loading differ.

## Validation

For source CI (no runtime access):

    python3 ops/company-code-release-v1/site-owner-sources/verify.py
    python3 -m unittest discover -s ops/company-code-release-v1/site-owner-sources -p test_verify.py

For an authorized operator readback of the exact site files:

    python3 ops/company-code-release-v1/site-owner-sources/verify.py \
      --runtime wayfatours=/path/to/wayfatours/site-copy \
      --runtime tours.dev=/path/to/tours-dev/site-copy

A separate WP-CLI **reflection readback** must confirm each recorded PHP
function is loaded from the claimed owner before an owner transition,
and again independently after any approved release. A filesystem checksum
alone is not proof that an owner is active.

The included runtime-readback.php accepts a base64-encoded JSON envelope in
the MAD4B_SITE_OWNER_EXPECTATIONS_B64 process environment. Its components
come from the validated registry, not from user-controlled WordPress settings.
It checks ReflectionFunction source paths and hashes against the running
WordPress site, and fails if a claimed active owner is absent or overridden.
It runs only through WP-CLI, and never modifies settings or content.
A PASS is an **observation**, not an authorized release receipt.

## Release fence

Do not add these files to the existing dynamic executable MU component catalog
without the original authorized 305/312/313 planning, grant and independent
readback contract. That catalog expressly limits its release targets: this
provenance registry does not extend it.

Add future companies as new variants, subject to an explicit site ID, exact
runtime path, declared owner and independently inspected source bytes. Preserve
separate site-specific variants instead of copying older MU code over a newer
native plugin. New source snapshots are not automatically activated.


## Original 305/312/313 source admission (read-only)

The included release_admission.py composes the existing original
company_code_release pinned target policy, exact Git blob reader,
target-path scope checks, original site identity resolver, and this
site-owner source registry. It is a diagnostic contract, not a new code
executor, registry grant, plugin loader, or a release-catalog expansion.

For Wayfatours Production, the live admission snapshot has confirmed
both registered preimages and the original native site identity, but
returns HOLD until the original pinned Git root is reconciled, the exact
site-owner destinations are explicitly enrolled, and the original
312 job grant and independent 313 receipt are actually available.

For example, after checking out the reviewed Git commit:

    python3 ops/company-code-release-v1/site-owner-sources/release_admission.py       --candidate-repo /reviewed/worktree --commit FULL_40_CHAR_GIT_COMMIT       --site wayfatours --company wayfatours --environment production

Unknown sites and environments must not inherit Wayfatours settings.
The original engine remains the only executor and must still independently
verify its own runtime identities, immutable source/target preimages,
one-time grants and after-write readback.


## Original Git object store and narrow site-owner release scope

The original host release policy pins a **Git repository root**, not a HEAD
branch. Two independently checked-out worktrees may have different filesystem
paths and mutable HEAD pointers while referencing the same immutable Git object
store. Admission now checks the common Git directory and reads every registered
source from the **same exact commit** through the pinned root, before evaluating
the root-owned target allowlist.

The Original Code Release executor has a protected extension for native plugins
outside the usual MAD4B prefix. It resolves the versioned site-owner registry
from the exact commit and requires the exact company/environment owner, exact
source SHA-256 and *separately installed root-owned* target allowlist entry.
No site-owner source record alone grants execution; signed grant, immutable
plan/target compare-and-swap and independent Original313 readback remain
mandatory. A source-only registry is not a permission catalog.

The original MU component release catalog is checked for completeness at CI
using verify_catalog_sources.py. Its four existing Composite Media entries
have byte-verified source artifacts. Missing Git blob references are not
treated as an authorization or silently skipped.

Host-owned policy enrollment, plan signing, the independent original job claim
and Original313 receipt remain separate lifecycle actions, not part of Git
pull-request tests. The current CI is *not* a production release certificate.
