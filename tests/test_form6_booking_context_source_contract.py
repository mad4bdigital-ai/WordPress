from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "packages/mad4b-shared-product-core/mu-plugins/shared-product-core"
OVERLAY = ROOT / "ops/site-company-overlays/wayfatours/booking-context-unified-v1/wp-content/plugins/mad4b-commerce"

def test_original_pricing_resolver_is_server_authoritative():
    php = (CORE / "adapters/presentation-form6-source-context-v1.php").read_text()
    assert "function shared_form6_source_context_v1" in php
    assert "shared_product_price_source_policy(" in php
    assert "shared_product_booking_v2_rate_covers_date(" in php
    assert "'/tour-form-source-context'" in php
    assert "'checkout_ready'=>false" in php
    assert "'order_created'=>false" in php
    assert "'Cache-Control','private, no-store, max-age=0'" in php

def test_form6_observes_applied_date_not_unverified_draft():
    js = (CORE / "assets/presentation-fluent-quote-v1.js").read_text()
    assert "sourceContextEndpoint" in js
    assert "function effectivePricingMode(state)" in js
    assert "appliedDateReadyForQuote(form)" in js
    assert "state.sourceNeedsApply=true" in js
    assert "'data-mad4b-date-pending'" in js
    assert "state.paymentRate.value=''" in js
    assert "state.stayPicker.hidden=true" in js
    assert "eligible_rate_ids" in js

def test_stay_choice_remains_rate_bound_and_not_fiscal():
    js = (CORE / "assets/presentation-fluent-quote-v1.js").read_text()
    assert "cfg.accommodationPolicy?.plans_by_rate?.[String(rate)]" in js
    assert "allowed.get(key)?.has(Number(id))" in js
    assert "booking-option-selection-change" in js
    assert "Stay preferences are saved for human review" in js

def test_pickup_does_not_present_itself_as_package_hotel_picker():
    pickup = (OVERLAY / "modules/commerce/362-mad4b-booking-pickup-fields.php").read_text()
    assert "Pickup arrangement (optional)" in pickup
    assert "Pickup from another hotel I booked" in pickup
    assert "Coordinate pickup with our team" in pickup
    assert "status.value==='booked'" in pickup
    assert "mad4b_pickup_hotel_name" in pickup

def test_critical_js_is_not_stale_optimized_bundle():
    php = (OVERLAY / "modules/compat/local-presentation-fluent-projection-v1.php").read_text()
    assert "litespeed_optimize_js_excludes" in php
    assert "data-no-optimize" in php
    assert "presentation-fluent-quote-v1.js" in php
    assert "presentation-selection-state-v1.js" in php
    assert "sourceContextEndpoint" in php


def test_source_cleanup_preserves_distinct_tenant_transport_behavior():
    original = (ROOT / "ops/site-company-overlays/tours-dev/booking-context-unified-v1/wp-content/mu-plugins/shared-product-core/assets/presentation-selection-state-v1.js").read_text()
    tenant = (CORE / "assets/presentation-selection-state-v1.js").read_text()
    quote = (CORE / "assets/presentation-fluent-quote-v1.js").read_text()
    for broker in (original, tenant):
        assert "shared-presentation-source-context-changed" in broker
        assert "latestSourceDate" in broker
        assert "rates.delete(rate)" in broker
        assert "eligibleRateIds" in broker
        assert "notify('source.context'" in broker
    assert "transportIntent=new Map()" in original
    assert "transportIntent=new Map()" not in tenant
    assert "eligibleRateIds:data.eligible_rate_ids" in quote

def test_readonly_stay_topology_audit_does_not_convert_rates():
    audit = (ROOT / "tools/audit_form6_stay_topology.php").read_text()
    assert "get_posts(" in audit
    assert "wp_get_post_terms(" in audit
    assert "automatic_conversion" in audit
    assert "financial_policy_mutated" in audit
    assert "update_post_meta(" not in audit
    assert "wp_update_post(" not in audit

def test_wpml_direct_source_is_current_tour_not_rate_catalog_fallback():
    php = (CORE / "adapters/presentation-form6-source-context-v1.php").read_text()
    for name in ("start_price", "tour_base_currency", "tour_price_unit"):
        assert "get_post_meta($tour,'" + name + "',true)" in php
        assert "get_post_meta($sourceTour,'" + name + "',true)" not in php

def test_date_contract_uses_fluid_field_settings_and_disambiguates():
    core = ROOT / "packages/mad4b-shared-product-core/mu-plugins/shared-product-core"
    date = (core / "adapters/presentation-form6-date-normalization-v1.php").read_text()
    quote = (core / "adapters/presentation-form6-price-quote-v1.php").read_text()
    pickup = (ROOT / "ops/site-company-overlays/wayfatours/booking-context-unified-v1/wp-content/plugins/mad4b-commerce/modules/commerce/362-mad4b-booking-pickup-fields.php").read_text()
    assert "SELECT form_fields FROM" in date
    assert "settings']['date_format" in date
    assert "shared_form6_date_parse_v1" in date
    assert "count($matches)>1" in date
    assert "shared_form6_dates_equal_v1($rawDate,$formDate,6,'datetime')" in quote
    assert "shared_form6_dates_equal_v1($when,$date,self::FORM_ID,'datetime')" in pickup
    assert "require_once WPMU_PLUGIN_DIR" in quote
    assert "require_once WPMU_PLUGIN_DIR" in pickup

def test_stay_pref_source_is_verified_not_pickup_inferred():
    pickup = (ROOT / "ops/site-company-overlays/wayfatours/booking-context-unified-v1/wp-content/plugins/mad4b-commerce/modules/commerce/362-mad4b-booking-pickup-fields.php").read_text()
    block = pickup.split("static function persist_stay_review($insert){", 1)[1].split("static function order_payload(",1)[0]
    assert "_request_advance_context" in block
    assert "hash_equals($correct,$context['seal'])" in block
    assert "shared_form6_source_context_v1($tour,$date)" in block
    assert "eligible_rate_ids" in block
    assert "additional_amount_authorized'=>false" in block
    assert "supplier_confirmation'=>false" in block
    assert "self::submission_tour" not in block
    assert "MAD4B_Booking_Pickup_Policy::rateFor" not in block
