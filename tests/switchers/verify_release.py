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
        self.assertIn("etg_traveller_nationality/required_count", php)
        self.assertTrue((NATIONALITY / "assets/checkout-blocks.js").is_file())

    def test_unified_management_guards(self):
        src = (NATIONALITY / "includes-traveller-management.php").read_text()
        main = (NATIONALITY / "etg-traveller-nationality-switcher.php").read_text()
        blocks = (NATIONALITY / "includes-checkout-blocks.php").read_text()
        for marker in (
            "const LEGACY_BATCH_SIZE=20", "shared_product_booking_configuration_matches",
            "etg_traveller_management/verified_count", "checkout_ready",
            "etg_traveller_management/verified_service_links",
            "wp_verify_nonce", "manage_woocommerce", "revision",
            "traveller_indices", "add_meta_box"
        ):
            self.assertIn(marker, src)
        self.assertIn("require_once __DIR__.'/includes-traveller-management.php'", main)
        self.assertIn("Requires Plugins: woocommerce", main)
        self.assertIn("ETG_Traveller_Management::resolve", blocks)
        self.assertIn("ETG_Traveller_Management::registry", blocks)
        self.assertIn("if($verified<1)return 0", blocks)
        self.assertIn("$n===$verified", blocks)
        for token in ("etg.booking-request.v1", "departure_inventory_mode", "request_allowed_without_stock", "capacity_preview"):
            self.assertIn(token,src)
        self.assertNotIn("set_price(", src)
        self.assertNotIn("create_order(", src)
        for token in (
            "shared_product_group_tier_from_taxonomy", "etg.group-capacity.v1",
            "taxonomy_when_group", "booking_engine_only", "assess_v2_preview",
            "etg.departure-capacity.v1", "booking_v2_persons_supported",
            "booking_mode", "group_size", "etg.departure-capacity.v1",
            "minimum_to_operate", "booking_party_size", "first_group_minimum",
            "automatic_conversion", "MAX_VERIFIED_PERSONS=999", "LEGACY_BATCH_SIZE=20"
        ):
            self.assertIn(token, src)

if __name__ == "__main__":
    unittest.main(verbosity=2)
