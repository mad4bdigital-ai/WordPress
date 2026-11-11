# MAD4B first-party plugin fleet — additive source recovery and release HOLD

Date: 2026-11-11.
Live source: Tours.dev Staging.
Candidate: isolated full WordPress Git reconciliation worktree.

## Corrected fleet inventory

A live-first inventory found 32 installed/source first-party mad4b-* plugin roots. Twenty-one entire plugin source roots were absent in the nominated Git candidate. The candidate had another four missing source paths inside existing plugin roots. A total of 238 live executable code paths were therefore missing (172 PHP, 31 JavaScript, 35 CSS).

These 238 **missing only** code files have been copied under their original relative paths into local Git, preserving exact SHA-256 and Git blob fingerprints. No existing candidate file was overwritten. In particular, 69 divergent live-versus-Git source files remain unchanged for independent semantic feature preservation and review, comprising existing Control Plane, Portfolio Manager, Country Switcher, Google Measurement Bridge, Age/Occupancy Registry, Pricing Registry and other first-party plugin components.

The source manifest is mad4b-first-party-plugin-additive-source-20261111.json.
Verifier: tools/verify_additive_mad4b_plugin_sources.py.
Global 32-plugin coverage: tools/audit_all_first_party_plugin_code.py.
Raw source evidence remains local only.

## Acceptance

- 172 newly imported PHP source files passed PHP 8.5 syntax lint in a no-network read-only container.
- 31 newly imported JS files passed node --check.
- Six focused provenance tests passed, including an exact historical Git preimage test proving none of the 69 existing conflicting code files were overwritten.
- The read-only full 32-plugin audit reports ZERO missing PHP/JS/CSS source paths and 69 existing content differences. It does not certify plugin dependencies, runtime registration or behavior.
- Direct MU root separately has ZERO absent directly stored PHP/JS/CSS paths but three existing Media/Content Jobs differences. Existing MU symlinks are outside direct-file comparisons.

## No runtime promotion

This is not an installable release. Certain plugin roots may require data files not represented in the code-only source manifest. No plugin activation, WP-CLI job, company schema overwrite, Elementor/JetEngine/WPML option replacement, Stripe payment, user grant, host Anchor Issue/Revoke or Original313 receipt occurred. Company ownership, enrollment identity, pricing and booking data must not be inferred from demo source.

**Global release state remains HOLD.** Resolve the remaining 69 plugin source differences, 3 direct-MU differences, independent Original318/313 authority gates, functional-browser tests, and any other runtime dependencies before governed code release.
