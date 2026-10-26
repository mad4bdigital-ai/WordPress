#!/usr/bin/env python3
"""Static release contracts. Real cart/checkout tests run separately in Woo Local Lab."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
PLUG = ROOT / "wp-content/plugins"
CORE = PLUG / "mad4b-site-control-plane"
COUNTRY = PLUG / "mad4b-country-switcher"
NATIONALITY = PLUG / "etg-traveller-nationality-switcher"
MAIN = (CORE / "mad4b-site-control-plane.php").read_text()

class ReleaseContracts(unittest.TestCase):
    def test_data_sources_and_market_runtime_copackaged(self):
        for part in ("market-profiles", "switcher-sources", "market-context", "market-preferences"):
            filename = "class-mad4b-scp-" + part + ".php"
            self.assertTrue((CORE / "includes" / filename).is_file(), filename)
            self.assertEqual(MAIN.count("require_once MAD4B_SCP_DIR . 'includes/" + filename + "';"), 1)
        for klass in ("Market_Profiles", "Market_Context", "Market_Preferences"):
            self.assertEqual(MAIN.count("MAD4B_SCP_" + klass + "::boot();"), 1)
        self.assertTrue((CORE / "data/market-language-territories.json").is_file())
        self.assertTrue((CORE / "assets/js/mad4b-market-selector.js").is_file())

    def test_reconciled_market_profile_policy_and_filter_binding(self):
        profiles = (CORE / "includes/class-mad4b-scp-market-profiles.php").read_text()
        self.assertIn("const TARGETING_POLICY=", profiles)
        self.assertIn("function is_targetable(", profiles)
        self.assertIn("if(!self::is_targetable($code,$lang))continue;", profiles)
        self.assertIn("self::is_targetable($country,$language)", profiles)
        self.assertIn("$_GET['market_lang']", profiles)
        self.assertIn('name="market_lang"', profiles)
        self.assertNotIn('name="tab"', profiles)
        for component in ("market-context", "market-preferences",
                          "season-calendar", "source-hub", "public-holidays"):
            source = "includes/class-mad4b-scp-" + component + ".php"
            self.assertEqual(MAIN.count("require_once MAD4B_SCP_DIR . '" + source + "';"), 1)

    def test_market_selector_advisory_and_native_source_boundaries(self):
        script = (CORE / "assets/js/mad4b-market-selector.js").read_text()
        context = (CORE / "includes/class-mad4b-scp-market-context.php").read_text()
        preferences = (CORE / "includes/class-mad4b-scp-market-preferences.php").read_text()
        adapter = (CORE / "includes/class-mad4b-scp-switcher-sources.php").read_text()
        self.assertIn("cache:'no-store'", script)
        self.assertIn("credentials:'same-origin'", script)
        self.assertIn("note.textContent=", script)
        self.assertNotIn(".innerHTML", script)
        self.assertNotIn(".submit(", script)
        self.assertNotIn("set_price(", script)
        self.assertIn("wp_ajax_nopriv_mad4b_market_context_read", context)
        self.assertIn("nocache_headers();", context)
        self.assertIn("array_intersect_key($m,array_flip(", context)
        self.assertIn("'geoip_is_commercial_eligibility'=>false", context)
        self.assertIn("wp_verify_nonce(", context)
        self.assertIn("wp_safe_redirect(", context)
        self.assertNotIn("str_contains(", preferences)
        country_source = (COUNTRY / "mad4b-country-switcher.php").read_text()
        nationality_source = (NATIONALITY / "etg-traveller-nationality-switcher.php").read_text()
        self.assertNotIn("str_contains(", country_source)
        self.assertNotIn("str_contains(", nationality_source)
        management_source = (NATIONALITY / "includes-traveller-management.php").read_text()
        self.assertNotIn("array_is_list(", management_source)
        self.assertIn("array_values($indices)!==$indices", management_source)


        self.assertIn("strpos($cookie,'.')===false", preferences)
        self.assertIn("hash_equals(", preferences)
        self.assertIn("FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE", context)
        for native_contract in ("get_countries()", "wpml_active_languages", "get_woocommerce_currency_symbol"):
            self.assertIn(native_contract, adapter)
        self.assertIn("MAD4B_SCP_Market_Profiles::by_country(", adapter)

    def test_country_explicit_dependency_and_contract(self):
        php = (COUNTRY / "mad4b-country-switcher.php").read_text()
        self.assertIn("Requires Plugins: woocommerce, mad4b-site-control-plane", php)
        self.assertIn("mad4b.switcher-sources.v1", php)
        self.assertIn("class_exists('MAD4B_SCP_Market_Profiles')", php)
        self.assertIn("mad4b_country_switcher", php)

    def test_native_sources_and_market_separation(self):
        src = (CORE / "includes/class-mad4b-scp-switcher-sources.php").read_text()
        self.assertIn("get_countries()", src)
        self.assertIn("wpml_active_languages", src)
        self.assertIn("get_woocommerce_currency_symbol", src)
        self.assertIn("MAD4B_SCP_Market_Profiles::by_country", src)
        # We may serve QA profiles from the lab database, never bundle those market grants.
        for directory in (COUNTRY, NATIONALITY):
            for path in directory.rglob("*"):
                if path.is_file():
                    self.assertNotRegex(path.read_text(), r"qa-us-(en|es)|qa-eg-en|qa-es-es")

    def test_pricing_currency_and_billing_not_intercepted(self):
        for directory in (COUNTRY, NATIONALITY):
            sources = "\n".join(f.read_text() for f in directory.rglob("*.php"))
            for hook in ("woocommerce_product_get_price", "woocommerce_product_get_regular_price",
                         "woocommerce_currency", "woocommerce_before_calculate_totals"):
                self.assertNotIn("'" + hook + "'", sources)
            self.assertNotRegex(sources, r"->set_(billing_country|shipping_country|price)\s*\(")

    def test_checkout_blocks_validation_and_no_fake_payment_claim(self):
        php = (NATIONALITY / "includes-checkout-blocks.php").read_text()
        self.assertIn("validate_request", php)
        self.assertIn("get_items('line_item')", php)
        self.assertIn("get_cart()", php)
        self.assertIn("woocommerce_store_api_checkout_update_order_from_request", php)
        self.assertIn("travel_booking_traveller_nationality/required_count", php)
        self.assertTrue((NATIONALITY / "assets/checkout-blocks.js").is_file())

    def test_unified_management_guards(self):
        src = (NATIONALITY / "includes-traveller-management.php").read_text()
        main = (NATIONALITY / "etg-traveller-nationality-switcher.php").read_text()
        blocks = (NATIONALITY / "includes-checkout-blocks.php").read_text()
        for marker in (
            "const LEGACY_BATCH_SIZE=20", "shared_product_booking_configuration_matches",
            "travel_booking_traveller_management/verified_count", "checkout_ready",
            "travel_booking_traveller_management/verified_service_links",
            "wp_verify_nonce", "manage_woocommerce", "revision",
            "traveller_indices", "add_meta_box"
        ):
            self.assertIn(marker, src)
        self.assertIn("require_once __DIR__.'/includes-traveller-management.php'", main)
        self.assertIn("Requires Plugins: woocommerce", main)
        self.assertIn("Travel_Booking_Traveller_Management::resolve", blocks)
        self.assertIn("Travel_Booking_Traveller_Management::registry", blocks)
        self.assertIn("if($verified<1)return 0", blocks)
        self.assertIn("$n===$verified", blocks)
        for token in ("travel_booking.booking-request.v1", "departure_inventory_mode", "request_allowed_without_stock", "capacity_preview"):
            self.assertIn(token,src)
        self.assertNotIn("set_price(", src)
        self.assertNotIn("create_order(", src)
        for token in (
            "shared_product_group_tier_from_taxonomy", "travel_booking.group-capacity.v1",
            "taxonomy_when_group", "booking_engine_only", "assess_v2_preview",
            "travel_booking.departure-capacity.v1", "booking_v2_persons_supported",
            "booking_mode", "group_size", "travel_booking.departure-capacity.v1",
            "minimum_to_operate", "booking_party_size", "first_group_minimum",
            "automatic_conversion", "MAX_VERIFIED_PERSONS=999", "LEGACY_BATCH_SIZE=20"
        ):
            self.assertIn(token, src)

    def test_no_new_site_branded_identifiers(self):
        import re
        compatibility = NATIONALITY / "includes-legacy-compat.php"
        self.assertTrue(compatibility.is_file(), "Legacy data compatibility must remain isolated")
        # WordPress retains the historic activation filename, not historic runtime symbols.
        for path in NATIONALITY.rglob("*"):
            if not path.is_file() or path == compatibility or path.suffix not in {".php", ".js", ".css"}:
                continue
            body = path.read_text()
            self.assertNotRegex(body, r"(?i)(?:\betg[_\-.]|\bETG_|\bETG\s)",
                                f"Site-branded runtime identifier in {path}")
        management = (NATIONALITY / "includes-traveller-management.php").read_text()
        for marker in (
            "_travel_booking_group_booking_minimum",
            "function group_booking_minimum(",
            "self::first_group_minimum($tour_id)",
            "travel_booking_tm_group_minimum",
            "group_booking_minimum($tour_id)",
        ):
            self.assertIn(marker, management)

    def test_suggestions_confirmed_server_and_preview_only(self):
        management = (NATIONALITY / "includes-traveller-management.php").read_text()
        blocks = (NATIONALITY / "includes-checkout-blocks.php").read_text()
        js = (NATIONALITY / "assets/checkout-blocks.js").read_text()
        for token in ("'suggestion'=>'first'", "'none','first','all'", "fields[suggestion]"):
            self.assertIn(token, management)
        for token in ("travel_booking_block_confirmation_missing",
                      "travel_booking_block_confirmation_required",
                      "($confirmed[$key]??null)!==true"):
            self.assertIn(token, blocks)
        for token in ("setConfirmed", "fillEmpty(", "confirmed: current", "disabled:!complete"):
            self.assertIn(token, js)
        self.assertNotIn("return config.preferred || '';", js)
        self.assertIn("CheckoutSchema::IDENTIFIER", blocks)
        self.assertIn("'additionalProperties'=>['type'=>'boolean']", blocks)
        self.assertIn("'additionalProperties'=>['type'=>'array'", blocks)
        self.assertIn("CHECKOUT_EXTENSION_REVIEW_NOT_STRIPPED",
          (Path(__file__).parent / "test-checkout-schema-local.php").read_text())
        self.assertIn("'confirmed'=>", (Path(__file__).parent / "test-large-party-local.php").read_text())
        self.assertIn("HPOS_3_ISO_COUNTRIES_PERSISTED", (Path(__file__).parent / "test-hpos-lifecycle-local.php").read_text())
        self.assertIn("VALIDATION_999_PERFORMANCE", (Path(__file__).parent / "test-999-validation-performance-local.php").read_text())

if __name__ == "__main__":
    unittest.main(verbosity=2)
