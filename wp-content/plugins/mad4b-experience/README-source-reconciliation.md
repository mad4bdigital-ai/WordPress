# MAD4B Experience — Central Staging v9 source reconciliation

Source: **live `tours.dev.mad4b.com`** deployed before GitHub reconciliation on 2026-11-04.

This directory contains the complete 36-file UTF-8 source inventory of the active WordPress plugin `mad4b-experience` (excluding generated `__pycache__` files). Original plugin bootstrap, presentation and visitor modules, assets, portability profile, and existing tests are retained. The active PHP module is `src/presentation/modules/369-layout-switcher-compatibility.php`.

## Grid/List defect and correction

The archived six-language JetEngine listing uses Query Builder **#5** for the outer tour and **#182** for the nested `destinations` terms. During a signed Layout Switcher Ajax render, the first Grid item previously inherited the wrong object (French term 366, tour 60637), while native filtered query returned tour 60491.

v9 restores the outer `WP_Post` context at the start of Query #5 and for each tour item. For nested Query #182 it binds `object_ids` and `queried_object_id` to the active tour **before `get_terms()`**. That eliminates 17 unrelated destination terms as well as the stale title and permalink. The fix does not patch JetEngine vendor files, alter tour classifications, or replace native HMAC/signed settings.

The runtime-specific guard confines this correction to **Central Staging** `tours.dev.mad4b.com`, the signed `get_listing` Layout Switcher request, authored Grid widget `7739f090`, and the published WPML translations of Elementor archive `30843`. It does **not** automatically authorize Production behavior or prove all tenants/archives.

## Reproduced acceptance

- Reversible browser trials of the published workflow: EN, FR, DE, ES, IT, AR: **6/6 pass** selecting Day Tours in Grid.
- Canonical independent WordPress/WPML readback: **6/6 pass**: expected post language, `tour-types` term, heading text (typographic punctuation normalized only), and **exact canonical URL**.
- First Grid IDs for the source data: EN 60481, FR 60491, DE 60535, ES 60579, IT 64871, AR 64874.
- No 17-city leak on the tested first filtered cards; no JS errors in those runs. Stage source PHP lint PASS and Reflection verified it loaded from the actual active plugin.
- Deployed active PHP SHA-256: `9250b0b00a3786a92acecacc54f4906d8257de032ab0a08bd5060dc5997a2af9`.
- The previous PHP source was preserved in an authorized VPS backup for rollback, SHA-256 `15bdc44e8f025d6e8c6b09862acd3c59d816cf995a33d59a9beefc9f778e33ca`.

## Release status and exclusions

**Staging scoped PASS; not a Production release or a full multilingual Grid/List release sign-off.** Full mobile/desktop cycles, other terms and layouts, filter DOM duplication (15 → 25 `.jet-filter` nodes), and target-company native readback still require their own verification.

Separately, the WPML taxonomy parity plugin is active on Central Staging, but draft PR #758 is **incomplete** (native plugin Bootstrap without its implementation). Do not merge #758 or assume that this PR resolves its packaging. Company Start/Reconcile must verify plugins and taxonomy parity on each target independently.

This branch is for **source reconciliation from Staging**, not for pushing changes back to Production.