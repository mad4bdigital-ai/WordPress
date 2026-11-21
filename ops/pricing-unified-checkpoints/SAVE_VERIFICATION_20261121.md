# Native JetEngine Save Verification — 21 November 2026

## Source owner and scope

This is a diagnostics layer on the ORIGINAL JetEngine Pricing Controls
Options Page. The existing original writer remains the only pricing
option writer. No prices, supplier receipts, Rates, orders or Checkout
were changed during deployment.

Original CAS:
wp-content/mu-plugins/mad4b-jetengine-native-pricing-cas-v1.php

Metadata-only Save observer:
wp-content/mu-plugins/mad4b-native-pricing-save-proof-v1.php

Existing-browser stale-request probe:
wp-content/mu-plugins/assets/mad4b-native-pricing-stale-probe-v1.js

## Evidence semantics

Only 12 metadata-only records are retained in the separate non-autoload
option mad4b_native_pricing_save_proof_v1. Each record has event type,
timestamp, abbreviated submitted/current source fingerprints, and
exact Repeater readback counts. No price values, original row data,
customer identity, session cookies, IP addresses, nonces or secrets.

- native_source_changed: original vendor Save hook was reached while
  protected by the CAS lock, and the source revision changed.
- native_source_unchanged: original Save handler ran, but no source
  change was proved. It does NOT certify a changed Repeater persisted.
- stale_rejected: a signed real-browser stale submission was rejected
  HTTP 409 before JetEngine Save.
- stale_probe_rejected: deliberate user-triggered browser negative test
  was rejected HTTP 409. NOT counted as a true two-session conflict.

After a real vendor Save, the observer also checks each original
submitted Repeater array against the authoritative MariaDB raw value.
It records only N/M exact readbacks; a non-exact value is not claimed
as successfully persisted.

## How the logged-in operator runs the remaining proof

Open the original page in a normal, already signed-in WordPress browser:

Tours.dev:
https://tours.dev.mad4b.com/wp-admin/admin.php?page=dynamic-pricing-controls

Wayfatours:
https://wayfatours.com/wp-admin/admin.php?page=dynamic-pricing-controls

Find Native JetEngine Save evidence and click
Test stale-save rejection (no pricing changes).

Expected: PASS — HTTP 409, without submitting any original pricing
field. Reload to inspect its clearly labeled probe receipt. No separate
login or cookie export is required.

To certify a real changed Repeater Save, select a deliberately
staging-only non-commercial QA row. Change one reversible value,
click the ORIGINAL JetEngine Save, reload and inspect the proof:
native_source_changed, with the changed Repeater verified as exact in
the stored N/M readback. Restore the original row with the same native
Save. Do NOT use customer pricing, live Rates or Production supplier
policies as throwaway test inputs.

For the genuine two-browser-tab conflict: open two tabs before
editing, save a controlled staging-only edit in tab A, and then submit
the old tab B without refreshing. Expect HTTP 409 and NO overwrite of
tab A. Verify stale_rejected (not stale_probe_rejected), then restore
the staging-only QA value.

## What was actually verified automatically

- Both WordPress sites: protected original Save admission, signed
  nonce checks, stale revision denial, and real MySQL advisory lock
  contention using two independent database connections.
- Both: original one JetEngine form/Save, 54 registered fields,
  native Repeater read-only roundtrip and privileged metadata-only
  diagnostics acceptance, all with unchanged source revisions.
- Offline Chromium: 409, 403 and 200 negative-probe responses; only
  CAS nonce, intentionally stale fingerprint and probe flag submitted.
  PASS appears ONLY for HTTP 409.
- Source/live file hashes matched after protected deployments.
- Normal logged-in browser clicking and controlled native policy
  mutation were NOT executed from this chat. Platform access checks
  had previously stopped attempted synthetic authentication and writes;
  those attempts were not retried or bypassed.

## Rollback

Per-site rollback files:
 /opt/mad4b-company-provisioning/backups/pricing-cp35-native-save-evidence-20261121/

Restore the original CAS file and remove the diagnostics observer and
browser probe if reverting. Do not remove the original JetEngine Page.
