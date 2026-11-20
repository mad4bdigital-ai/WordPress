# Wayfatours: source-backed hotel candidates for eight Package Tours

**Execution:** 20 November 2026. Native component discovery, not manual Tour Rate reuse or commercial activation.

## Native source / exact preflight

- EN hotel cohort: 18 Published Properties, ES translations 18 Published, with 134 Published EN Units and 0 Published Property Rates.
- Rate discovery provider: `shared_product_accommodation_assignment($tour,0,$tier)`, source Rate ID = **0**. Zero-price missing is accepted for **candidate discovery** via `unit_price_required=false`; checkout remains false.
- Existing 32 manually sourced/imported `tour-rates` for these eight EN Tours are all **Draft**. Backed up their whole pre-run postmeta and statuses. The **after** entire meta/status snapshot was byte-for-byte equivalent: **32/32 unchanged**; no legacy rate links or prices were copied.
- Authoring of fresh Rates remains governed separately: the native Rate Generator still requires an approved source `amount` or `supplier_cost` to create a **priced** Draft. No new Rate was inserted here.
- The component price defaults allow native accommodation at the component layer with `checkout_enabled=false`.

## Executed corrections

1. Scoped exact EN/ES WPML mapping of 18 Properties and eight Package Tour pairs.
2. Set `prop_use_in_tour_rate_generation=1` on all 36 EN/ES Properties (27 missing opt-ins updated; nine EN entries already opted in).
3. Restored **42 missing Spanish native property taxonomy groups** (`destinations`, `stars` where actually present on EN, `property-types`) and removed exactly one foreign-language `Hotel` term from Spanish Saray. Levels stayed untouched: **Silver 5, Gold 6, Diamond 7**. Star ratings not independently documented stayed missing.
4. Added **21 missing Cairo/Giza relationships** to the 16 EN/ES Package Tour posts, each grounded in the actual published itinerary's Cairo hotel and Giza Pyramids excursion. Existing destination tags, including Saqqara, Luxor, Aswan and Red Sea, were preserved.
5. Fixed only the live Wayfatours location resolver's behavior: a specifically tagged sightseeing `zone_area` inside an explicitly tagged governorate no longer removes that governorate from the *Tour's* potential accommodation search scope. The *Property* destination precision is unchanged. This closed the last six candidate-test mismatches on Package Tour 57562 and ES sibling 57563.

### Bounded code source and recovery

- Data script: `scripts/mad4b-stay-eight-packages-hotel-component-discovery-cli.php`, scoped `plan/apply/verify`, exact post IDs, no writes to Rates, stock, inventory, nights or checkout.
- Live geography code backup in private VPS report directory:
  `/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/accommodation-assignment-wayfa-before-geography-hotfix.php`
- Runtime source before SHA256: `6b9128ef3ed9a540537130554a4c9b344c45bcd60fc4b0a3a16531ef66e725b8`.
- Runtime source after SHA256: `edfdfa4b7ca5ad0c3d2b8652a103e05f58d3fffdf84807192f64fa78a71a2bf8`.
- Fail-closed patch/rollback reproducibility: `scripts/mad4b-stay-package-hotel-tour-zone-area-fix.py`.
- Safety note: **live Wayfatours runtime contains 448 lines**, but the canonical Git package file on this PR's base contains **87 lines**, so they must **not** be overwritten wholesale. This targeted patch remains separately documented until Control Plane/shared-product-core source reconciliation is approved. PR Draft and upstream release gates are not bypassed by the source commit.

## Result: 48/48 exact candidates

| Cohort | EN candidate IDs | Per EN Tour | Per ES sibling |
| --- | --- | ---: | ---: |
| Silver | 70732, 36475, 36316, 36197, 36209 | 5 | 5 ES WPML IDs |
| Gold | 36479, 36473, 36474, 36477, 36140, 70733 | 6 | 6 ES WPML IDs |
| Diamond | 36355, 70734, 70735, 70736, 70737, 70738, 70739 | 7 | 7 ES WPML IDs |

All eight English Package Tours **57562, 60437, 60439, 60441, 60443, 60623, 60627, 60629** and their Spanish siblings were tested against all three hotel tiers (16 × 3 = **48 cases**). The native resolver returned **exact expected canonical/localized Property IDs**, no source Rate, no required Unit price, and `checkout_ready=false`, with **0 errors**.

All 48 results currently report `needs_stage_plan`. This means **hotel candidate discovery succeeds**, but the source itinerary still needs a proper accommodation-stage mapping (actual hotel nights vs cruise nights vs sleeper-train nights vs Red Sea resort). No nightly geometry or actual hotel choice was automatically fabricated.

**Platinum:** Tour **60439** has a Platinum taxonomy label, but the approved 18-hotel cohort has no Platinum hotel. No fallback from Platinum to Diamond was invented.

## Pricing dimensions reviewed, not silently altered

- Pricing Lab preference is custom with only `taxonomy:tour-types` and `taxonomy:package_levels`. That governs *new Pricing Lab targeting*, not native destination matching. Geography is enforced by the accommodation assignment's destination taxonomy regardless of this preference.
- Native base board policy: **BB** globally, **AI** for Hurghada and Sharm el-Sheikh, **Full Board** for cruises.
- Supplier precedence: `external_contract` primary, `self` fallback secondary, in **preview-only**, with no booking authority.
- Inventory: unmanaged, occupancy enforcement, supplier-availability confirmation required, checkout disabled.
- Hotel rate layer missing prices remains unpriced, **not zero**. There are no Published Property Rates, Published Tour Rates or enabled Properties catalog. `/properties/` and `/units/` remain HTTP 404.

## Next implementation boundary

Construct new **draft-only** itinerary stay-stage geometry by validating each night's lodging type and exact destination. For each hotel stage, offer OR alternatives from the 5/6/7 discovered hotels; do not use old Rate data as price authority. Keep missing hotel price as `pending_quote` and block publish, booking, and checkout until pricing/inventory/guest-capacity proofs exist. This should not happen in the same operation that merely enables catalog candidate discovery.
