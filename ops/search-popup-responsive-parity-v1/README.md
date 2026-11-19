# Elementor popup search: source parity without overwriting live updates

This directory preserves the reviewed behavior of the existing Tour Search popup (Elementor popup ID 41878, embedded template ID 41728). It contains the narrow responsive CSS and a guarded WordPress CLI helper. It does NOT include a database dump, a generated Elementor CSS file, a plugin update, or a release authorization.

## What changed in WordPress

- Responsive Elementor page settings: mobile width 94vw; tablet width 92vw.
- Popup-only CSS: viewport-safe dialog, vertical scroll, min-width protections, and Jet AJAX Search results positioned in normal flow on mobile instead of protruding below the dialog.
- Existing page settings, embedded template content, custom CSS bytes, the remaining Elementor popups and other styles must be preserved.
- Core/Pro JavaScript dependencies are excluded from LiteSpeed JS combining ONLY on deployments where combining was already enabled. The policy is in javascript-exclusions.json. Combining stays disabled on sites where disabled.

The baseline serialized Elementor popup setting SHA-256 was 082f451548bbb5e47a663240fe99b3257b6722eb3d05d71e56884be5058f9a9b. The reviewed post-fix SHA-256 was 8c8fd4c73ead924656e2b9c8a57e305c2fabb23b1b5ea05b3b5d7df1298ad54e. The scoped CSS file hash is ca8dc51521d17e01e899b1df065282c855d30d9a782de3765ba43e0a62327794. These are historical evidence, not a permanent license to overwrite later live changes.

## Dry-run, apply and safety

Use WP-CLI with both apply.php and popup-responsive.css in the same directory, after creating root-only backups of raw serialized post metadata and Elementor generated CSS.

A patched site can be checked without mutation using:

    wp eval-file apply.php

To apply to a not-yet-patched site, first inspect and approve the current serialized metadata hash, then run a read-only plan:

    MAD4B_POPUP_EXPECTED_META_SHA256=<reviewed-current-sha256> wp eval-file apply.php

Only after the plan matches current live bytes and backup exists:

    MAD4B_POPUP_APPLY=1 MAD4B_POPUP_EXPECTED_META_SHA256=<reviewed-current-sha256> wp eval-file apply.php

Unexpected live changes, unknown popup ID/type/template, a differing existing patch, or missing CAS preimage fail closed. WordPress update_post_meta unslashes stored strings; the helper uses a conditional SQL CAS for the single existing metadata row to preserve original CSS. Elementor rebuilds CSS after the write. Only the home URL is passed to LiteSpeed for targeted purge.

This is NOT an Original312 signed job or an Original313 receipt. Do not change release permissions or use it as an approval substitute. The live WordPress state remains the mandatory preimage for any future release.

## Verification and rollback

Automated source checks: python3 -m unittest discover -s ops/search-popup-responsive-parity-v1/tests -p test_contract.py -v

Runtime check: run apply.php without mutation on each installed site and verify exact hashes, then test the popup open/search/close interaction on a fresh and a warm browser session. Tested mobile viewports 320, 375, 427px with results inside dialog; desktop/tablet should stay unaffected. Check popup document getModal and JS page errors.

Rollback requires matching current state and root-only backups, with a CAS guard; restore the exact previous serialized metadata, regenerate Elementor CSS, and restore old LiteSpeed JS exclusions only where previously changed. Do not automatically restore or overwrite another editor's later changes.
