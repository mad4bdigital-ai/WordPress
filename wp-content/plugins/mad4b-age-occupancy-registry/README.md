# MAD4B Age-Aware Occupancy Registry — Local integration

## Ownership and precedence

The existing `MAD4B_Age_Occupancy_Registry` remains the policy authority. The age-aware stay module contributes WordPress-backed sources through the existing `mad4b/age_occupancy/policy_level` hook; it does not copy JetEngine fields or create duplicate taxonomies.

Source precedence (later wins): global option `mad4b_age_occupancy_policy_global_v1` → product type option `mad4b_age_occupancy_policy_product_types_v1[domain]` → supplier post meta → product/property post meta → unit post meta → rate post meta. Post-meta key: `_mad4b_age_occupancy_policy_v1`.

WooCommerce → Age & Occupancy provides a scoped policy editor. Capabilities: `manage_woocommerce`. Saves require nonce verification and policy validation. No global policy or rates were changed when installing this module.

## Band schema

```json
{
  "age_reference": "checkin",
  "count_in_capacity": true,
  "age_pricing_enabled": true,
  "bands": [
    {"id":"infant","min_age":0,"max_age":2,"price_factor":0,"count_in_capacity":false},
    {"id":"child","min_age":3,"max_age":17,"price_factor":0.5,"count_in_capacity":true}
  ]
}
```

These ages are examples, not site defaults. Each supplied child age means the age **at check-in/service start**, according to policy. No birth dates are required. Overlapping bands, missing age coverage and conflicting child counts are refused.

## Booking request

For a single room, supply `child_ages: [1, 7]` alongside `children: 2`. For multiple rooms, supply each `room_occupancy[]` allocation with its `child_ages`. The age-derived capacity is checked against `max_children` and `max_occupancy`, while the original adult limit and raw child totals are preserved.

Age price factors affect only `per_person` and `per_person_night`. `per_room_night`, `per_cabin_night`, and `per_stay` retain existing unit pricing. Source rates, market rules and WooCommerce order authority are unchanged. Quotes include an age-policy receipt and are preview-only.

## Rollout guardrails

Legacy quotes without an age policy remain unchanged. Once age bands are configured for a rate, quotes with children must supply age details to avoid silent mispricing. Do not enable age policies on live booking forms until those forms collect and transmit the ages. Rate/room/source parity can be tested locally using `tests/age-occupancy-runtime.php`; admin validation via `tests/admin-policy-runtime.php`.

Current commercial boundary: accommodation shadow quotes and age-aware capacity are integrated. Tour/transport fare arithmetic, persistence of age-specific quote evidence into new checkout orders and full browser acceptance are not yet certified.

## Live Fluent Forms passenger-age integration

The adapter is **non-destructive**: original Fluent Form definitions are not changed. It extends the existing render/submission pipeline and adds age inputs only for forms enrolled in the site option `mad4b_age_occupancy_fluent_forms_v1` (editable under WooCommerce → Age & Occupancy). No form IDs are hardcoded in the distributable plugin. The following mapping is installed in ETG Local only.

| Fluent Forms ID | Existing children field | Existing infants field | Missing ages with minors |
| --- | --- | --- | --- |
| 5 (Request Tour) | `numeric_field_1` | `numeric_field_2` | Legacy inquiry allowed, new age payload validated |
| 6 (Single Tour Order) | `no_of_children` | `no_of_infants` | Fail closed |
| 7 (Tour Order) | `no_of_children` | `no_of_infants` | Fail closed |
| 8 (Tour Order, alternate) | `no_of_children` | `no_of_infants` | Fail closed |

The `fluentform/form_element_start` hook emits one accessible age collector per form instance. The frontend `assets/fluent-ages.js` creates exactly as many required age number fields as the form reports children/infants, retaining values when counts change. Age fields use `mad4b_child_ages[]` and `mad4b_infant_ages[]` and are not trusted to contain pricing. The server validates serialized submission data using `fluentform/validation_errors` before insert and records a normalized `mad4b_age_context` via `fluentform/insert_response_data`. This includes `stay_children` (child count + infant count), `child_ages` (combined age list) and `infant_ages` separately. No payment amount or quote authority is accepted from the request.

The request-form receipt is **not** an approved unit-specific accommodation quote: actual rate/occupancy validation is rerun by the booking quote engine. For tours with accommodation stages, `tour_stay_overlay_quote()` now forwards `child_ages` from stage choices to the native stay quote provider. When two or more rooms are selected, each `room_occupancy[]` must also contain matching `child_ages` for that room; age submissions alone do not guess room placement.

Run isolated tests with `tests/fluent-form-runtime.php`, `tests/age-occupancy-runtime.php` and `tests/admin-policy-runtime.php`. A real browser/AJAX submission and stored entry readback must be certified before Staging/Production rollout.
