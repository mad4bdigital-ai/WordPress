import hashlib
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[4]
# The full source owner baseline has now been recovered from the enrolled
# Tours.dev runtime. The historical bootstrap remains inert in source-overlays.
BOOT = ROOT / 'wp-content/plugins/mad4b-commerce/mad4b-commerce.php'
MODULE = ROOT / 'wp-content/plugins/mad4b-commerce/modules/commerce/tour-component-structured-price-tags.php'

class SourceOwnership(unittest.TestCase):
    def test_plugin_registers_shared_readonly_module(self):
        s = BOOT.read_text()
        self.assertIn("/modules/commerce/tour-component-structured-price-tags.php'", s)
        self.assertIn("function_exists('tour_component_price_evidence')", s)
        self.assertIn("function_exists('tour_component_structured_price_value')", s)
        self.assertIn("MAD4B_PRESENTATION_RUNTIME_ENABLED", s)
        self.assertIn("MAD4B_COMMERCE_RUNTIME_ENABLED", s)
        self.assertIn('require_once $module', s)
    def test_complete_source_runtime_owner_and_pickup(self):
        self.assertTrue(BOOT.is_file())
        self.assertIn('Version: 0.1.2',BOOT.read_text())
        self.assertIn('362-mad4b-booking-pickup-fields.php',BOOT.read_text())
        self.assertTrue((ROOT/'wp-content/plugins/mad4b-commerce/modules/commerce/362-mad4b-booking-pickup-fields.php').is_file())

    def test_single_formatter_module(self):
        data = MODULE.read_bytes()
        self.assertEqual(hashlib.sha256(data).hexdigest(), '4e4ef66c60846d8a58365e4b7abbcd08d7314bd1af084df357be17e2ddce78a7')
        self.assertIn(b'function tour_component_structured_price_value(', data)
        self.assertIn(b'function_exists(\'tour_component_price_evidence\')', data)
        self.assertIn(b'tour_component_price_display_label(', data)
        self.assertIn('Desde'.encode('utf-8'), data)
        self.assertIn('por persona'.encode('utf-8'), data)
if __name__ == '__main__':unittest.main()
