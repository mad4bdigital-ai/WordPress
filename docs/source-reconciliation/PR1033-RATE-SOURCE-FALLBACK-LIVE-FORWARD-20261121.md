# Native accommodation Unit → Property Rate source fallback — live follow-on

Date: 2026-11-21. Two-site selective delivery with signed-cost commercial gate intact.

## Runtime rollout

- Tours.dev and Wayfatours use the original MU `mad4b-accommodation-weight-policies-v1.php` with the same source hash.
- The original full-window Season helper was independently installed in both site's native Rate Planner and date reader. On Tours.dev the duplicated shared-product-core path is a symlink, not a second independent file.
- Original authenticated AJAX action `mad4b_accommodation_weight_policy_v1` exposes a read-only `rate_source` mode. It takes Property, original Unit, dates, currency, cost unit basis and returns source preference.
- Unit-linked published cost Rate wins; scoped generic Property cost Rate can fall back only with complete date coverage, matching currency/basis, published Unit relation and capacity. Draft, Stop Sale, QA, retail, ambiguous or incomplete sources are refused.
- The native signed Cost Receipt admission, Price Writer, seasonal stage assignment, occupancy, FX and checkout gates remain completely unchanged. The preview reports supplier approval and checkout as false.

## Evidence

- Hermetic PHP regression 18/18 PASS. PHP 8.5.12 syntax PASS.
- On live Tours.dev and Wayfatours, WordPress and its original AJAX handler loaded the class and returned `success=true`, `commercial_ready=false`, `rate_written=false`, `checkout_ready=false` for selected Draft Unit diagnostics.
- At inspection: Tours.dev Property Rates 76 Draft / zero Published; Wayfatours 84 Draft / zero Published. No commercial fallback source exists yet.
- Site-specific file preimages, hashes and candidate in the restricted `/opt/mad4b-company-provisioning/reports/property-rate-source-fallback-20261121/` directory. No WordPress rate, booking or supplier-price mutation.

Source CI never constitutes commercial or Owner/Host release authorization. Keep this PR Draft.
