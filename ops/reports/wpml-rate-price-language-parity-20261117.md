# WPML Tour Rate price parity — 2026-11-17

## Goal
Trust the most recent saved Rate revision on initial reconciliation. Any later authorized price-meta edit immediately propagates to published Rate translations sharing the same WPML translation group, same parent Tour identity and same currency. Language alone is not a price market.

## Results
- Wayfatours: 38 published same-currency groups; 3 stale price fields corrected; 0 remaining.
  - English #60820 / Spanish #60822: double_price 2130 to 2310 USD (latest: English, 2026-11-08)
  - English #61010 / Spanish #61012: triple_price 2630 to 2730 USD (latest: English, 2026-05-10)
  - English #60930 / Spanish #60932: triple_price 2085 to 2285 USD (latest: English, 2026-05-10)
  - Woo quote for 2 adults Double: Rate #60820 / #60822 is 4620 USD on both languages.
- Tours.dev: 74 same-currency groups; 4 stale fields corrected; 0 remaining.
  - USD differences of the three pairs above corrected, including Arabic sibling #68976.
  - EUR group #60804/#60806/#60808 reconciled to latest EUR double price 1090 (from older French 1920).
  - USD to EUR writes are excluded; localized Rate base currencies remain unchanged.

## Permanent behavior
1. Immediate on added_post_meta, updated_post_meta or deleted_post_meta for allowlisted price/sale fields. Original WP pricing capability controls changes; no bypass.
2. Daily read-only WP-Cron integrity pass; an anonymous runtime cannot write protected pricing.
3. Authorized low-CPU host Cron runs each day at 03:19 UTC (Tours.dev) and 03:41 UTC (Wayfatours). Uses existing WordPress admin ID 1 with commerce_manage_pricing, checks max 25 corrections, confirms readback, and uses per-site flock/timeout/nice/ionice.
4. Invalidates affected tour pages and emits MAD4B native rate-cache signal after actual synchronized edits. No repeated Chromium certification.
5. A tie between latest same-second but conflicting values is reported for review rather than guessed.

## Backups
Original price snapshots cover 196 Staging Rates and 76 Production Rates:
- /root/mad4b-backups/tour-rate-wpml-parity-20261117/staging-before.json
- /root/mad4b-backups/tour-rate-wpml-parity-20261117/production-before.json

Rollback: disable the host Cron and new MU module, then restore only intended price fields via authorized WordPress metadata writes, after reviewing changes made since this snapshot. Never blanket overwrite new Rates.

Shared module: wp-content/mu-plugins/432-mad4b-rate-price-language-parity-v1.php
Stable runtime: /opt/mad4b-company-provisioning/operations/rate-price-language-parity/reconcile.sh
Cron: /etc/cron.d/mad4b-tour-rate-language-parity

## Acceptance
- Tours.dev: editing Spanish #60822 double_price from 2310 to 2311 instantly updated English #60820 and Arabic #68976, leaving EUR #60824 at 2125. Restoring English to 2310 updated all USD siblings and returned audit 0.
- Production: 3 fields reconciled with verified pricing permission and readback; no payment or Woo order created.
- Both authorized daily runners executed successfully and reported remaining_drift=0. Cron service active.
