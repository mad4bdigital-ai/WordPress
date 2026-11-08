import importlib.util
import pathlib
import tempfile
import unittest

TOOL = pathlib.Path(__file__).resolve().parent.parent / "tools" / "apply_contextual_pricing_calls.py"
spec = importlib.util.spec_from_file_location("context_patch", TOOL)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class ScopedContextCallSiteTests(unittest.TestCase):
    def test_all_fixed_targets_apply_only_once(self):
        for name, substitutions in mod.PATCHES.items():
            original = "<?php\n" + "\n".join("/* existing code */\n" + old for old, new in substitutions)
            changed, count = mod.transform(name, original)
            self.assertEqual(count, len(substitutions), name)
            again, repeated = mod.transform(name, changed)
            self.assertEqual(repeated, 0, name)
            self.assertEqual(changed, again, name)

    def test_preserves_site_specific_booking_implementation(self):
        name = "pricing/booking-configuration-v2.php"
        snippets = mod.PATCHES[name]
        for label in ("tour-dev-source-last-minute", "wayfa-legacy-booking"):
            original = "<?php\n" + "\n".join(x for x, _ in snippets)
            original += "\n/* UNIQUE BOOKING IMPLEMENTATION: " + label + " */\n"
            patched, count = mod.transform(name, original)
            self.assertEqual(count, len(snippets))
            self.assertIn(label, patched)
            for old, new in snippets:
                self.assertIn(new, patched)

    def test_unknown_or_ambiguous_site_code_fails_closed(self):
        self.assertRaises(ValueError, mod.transform, "not-a-pricing-module.php", "<?php")
        for name, snippets in mod.PATCHES.items():
            old = snippets[0][0]
            self.assertRaises(ValueError, mod.transform, name, "<?php\n" + old + "\n" + old)

    def test_never_changes_quote_or_last_minute_logic(self):
        for name, replacements in mod.PATCHES.items():
            for old, new in replacements:
                self.assertTrue(
                    "shared_product_mode_policies" in old
                    or "shared_product_strategy_policy" in old
                    or "shared_product_price_source_policy" in old
                    or "shared_product_resolve_price_sources" in old,
                    name,
                )
                self.assertNotIn("shared_product_last_minute_quote_adjust_v1", old)
                self.assertNotIn("shared_product_last_minute_quote_adjust_v1", new)

    def test_rejects_unexpected_site_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp) / "wp-content"
            root.mkdir()
            self.assertRaises(ValueError, mod.plan, root)


if __name__ == "__main__":
    unittest.main()
