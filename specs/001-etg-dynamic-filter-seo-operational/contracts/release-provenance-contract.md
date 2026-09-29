# Release Provenance Contract v7

Every ETG candidate package is bound to exact source and installable payload identity. A candidate built from another source SHA, repository tree, plugin subtree, installable manifest, or package digest is different evidence even when the WordPress plugin version string is unchanged.

Required evidence:

- `git_sha`: exact repository commit.
- `tree_sha`: exact repository tree.
- `plugin_tree_sha`: exact `wp-content/plugins/etg-dynamic-filter-seo-bridge` subtree.
- `installable_manifest_sha256`: deterministic digest of every installable ETG source file, excluding tests.
- `installable_file_count`: manifest cardinality.
- `package_sha256`: deterministic ZIP digest.
- embedded build identity digest, CI run identity, PHP matrix, test-manifest digest, and vendor-package contract digest.
- `certification_mode`: PR candidate, branch candidate, or explicit manual recertification.
- a human-readable certification reason for manual recertification.

## Historical Alpha13 boundary

The previously certified Alpha13 source is `3be47285e9a1f751d7b4068c766c09f414ca7eb6` with ETG plugin subtree `0f12a5710a117ec50ec04ce86798f8a955bccbcd`.

A newer source that still reports `Version: 0.4.0-alpha.13` is **not** the same certified artifact unless exact source/package evidence proves equivalence. The workflow records `historical_alpha13_plugin_tree_match` explicitly and must not relabel a differing tree as the historical certified Alpha13 tree.

Manual recertification is exposed through `workflow_dispatch`; it builds the deterministic package/provenance from the selected exact ref without granting merge, Staging publication, Production activation, or mutation authority.
