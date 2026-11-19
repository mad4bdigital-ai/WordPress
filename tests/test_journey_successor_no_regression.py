"""Guard against restoring retired Journey semantics during branch convergence."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

class JourneySuccessorContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.engine = (PLUGIN / "includes/class-mad4b-scp-tour-highlights-v1.php").read_text()
        cls.presentation = (PLUGIN / "includes/class-mad4b-scp-tour-highlights-presentation-v1.php").read_text()
        cls.css = (PLUGIN / "assets/tour-journey-split-v3.css").read_text()
        cls.migration = (ROOT / "scripts/migrations/destination-native-template-reconcile-v1.php").read_text()

    def test_keep_newer_original_runtime_guard(self):
        self.assertIn("if (!function_exists('add_filter') || !function_exists('add_action')) return;", self.engine)

    def test_keep_legacy_manual_copy_if_jetengine_callback_is_empty(self):
        self.assertIn("(string)get_post_meta($post,'tour_highlights_content',true)", self.engine)

    def test_avoid_php74_removed_string_functions(self):
        self.assertNotIn("str_contains(", self.engine)
        self.assertNotIn("str_contains(", self.migration)
        self.assertNotIn("str_starts_with(", self.migration)
        self.assertIn("strpos(", self.migration)

    def test_cacheable_script_dependency_chain_matches_live(self):
        self.assertIn("wp_enqueue_script('tour-journey-ui',$assets.'tour-journey-ui.js'", self.presentation)
        self.assertIn("wp_enqueue_script('tour-journey-split-v3',$assets.'tour-journey-split-v3.js'", self.presentation)
        self.assertIn("['tour-journey-ui'],(string)filemtime($splitJs),true", self.presentation)
        self.assertIn("['tour-journey-split-v3'],(string)filemtime($destinationJs),true", self.presentation)
        self.assertNotIn("wp_add_inline_script('tour-journey-ui'", self.presentation)

    def test_keep_live_itinerary_accent_card_without_doubling(self):
        self.assertEqual(self.css.count(".jd-modes-v3 .jd-split-v3__itinerary > .elementor > .e-con{"), 1)
        self.assertIn("0 0 0 1px var(--e-global-color-accent,#b68d52)", self.css)

if __name__ == "__main__":
    unittest.main()
