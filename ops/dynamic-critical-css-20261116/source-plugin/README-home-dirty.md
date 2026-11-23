# Source-bound home card CSS dirty — original plugin artifact

This directory preserves the exact, deployed first-party
`mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php`
bytes for release reconciliation. It is **not** an extra runtime
copy loaded by WordPress. Canonical installation remains the existing plugin
path under each company's WordPress `wp-content/plugins` directory.

Original live source SHA256: `09fd2d054a4acad8698fe51d8f666a76a4adc4c1fbd3b78320d00091805e2979`.
Replacement deployed SHA256: `6abfa2d3b92f9f21df7c627b31a6c6c2852c166b2164b79818ceee1edddf03d0`.

Only `wp_after_insert_post` changes on `tours-and-activities` which have
the first-party cache lifecycle proof `purge=true, reason=direct_home_card`
may rotate `mad4b_critical_css_home_dirty_v1` instead of the broad global
`mad4b_css_ai_epoch_v1`. The original WordPress 429 source-trigger hook
enqueues first-fold requalification from this home-dirty option. Original 421
continues route/listing invalidation. All Elementor edits, missing/unknown
graphs, other CPTs, menu/setting/template changes stay on the original global
fail-closed epoch. No CSS certificates, QA thresholds, consumer 422, market
keys, visitor FastCGI cache or Canary switches are changed.

Release procedure must compare the exact old SHA, preserve original rollback
outside active `.php` source paths, stage atomically, PHP-lint, run
`php ops/dynamic-critical-css-20261116/tests/test-home-scoped-dirty.php`,
and verify real WordPress source/429/421 readback. An unexpected prior
version must be reconciled by a diff, not overwritten by this snapshot.
