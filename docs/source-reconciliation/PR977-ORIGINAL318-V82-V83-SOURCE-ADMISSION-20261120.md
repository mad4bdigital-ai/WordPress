# PR977 Original318 v82 / v83 deploy target source reconciliation

Date: 2026-11-20. Non-authorizing review of a historical failure in the Company Reconcile Production Admission workflow; **no** WordPress/Host operations or release admissions were performed.

Original v82 signed-anchor candidate at `ops/original318-signed-anchor-candidate-v82/318-mad4b-native-host-exact-grant.php` remains SHA256 `56c78c0036c1e690069a2abbbbe4e84f8745bf30fec4dcc9c875835e52e442be` and is not changed. Its deploy target in `wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php` has since gained the **v83 non-authorizing readback** consumed by the independent Original313 reader. The new target is pinned to SHA256 `1c80391b15c750238339cf43c248dfd6c4017ec80102ddc01e210b9e8ec26b0b`.

The historical v82 acceptance fixture formerly asserted complete byte identity between original and deploy target, so it rejected the safer v83 extension as a failure. The updated test asserts both SHA256 values, strips **only the exact reviewed v83 readback method** and bounded `anchor-current-readback` CLI case, and requires the reconstructed source to equal the **original v82 bytes exactly**. Any changed body, missing method, unapproved CLI action, or modified original source fails closed.

The independently tested method reads only the signed WordPress Anchor option and Site Profile; it does *not* establish Original313's independent root ledger high-water mark, verified job, fresh signed checkpoint, grant, or execution authority. The source-invariant test requires all original write-denial flags and no `update_option`, `add_option`, `wp_insert_post`, or outbound HTTP in this new method.

Verification in isolated, network-disabled, read-only PHP 8.5 with synthetic signed keys: original v82 historical suite **7/7 passed**, original Original318 v83 signed readback **23 checks passed**, independent current revision reader **21 cases passed**. No private keys, live host receipts, live roles or infrastructure grants were read or created. The independent signed Broker event / root checkpoint remains mandatory for a real Anchor issue, revocation or deployment.

The release remains blocked pending an exact-head Review Verdict, Staging acceptance and owner attestation; this source test is not functional Production approval. Original PR942's 57 historical manual functional signoffs remain false.
