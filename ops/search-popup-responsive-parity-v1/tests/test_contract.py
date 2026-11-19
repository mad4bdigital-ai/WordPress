import hashlib
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parent.parent
CSS = ROOT / "popup-responsive.css"
PHP = ROOT / "apply.php"
JS = ROOT / "javascript-exclusions.json"
EXPECTED_CSS_SHA = "ca8dc51521d17e01e899b1df065282c855d30d9a782de3765ba43e0a62327794"


class SearchPopupSourceContract(unittest.TestCase):
    def test_exact_live_css_source_and_scope(self):
        css = CSS.read_text()
        self.assertEqual(hashlib.sha256(CSS.read_bytes()).hexdigest(), EXPECTED_CSS_SHA)
        self.assertTrue(css.startswith("/* MAD4B_SEARCH_POPUP_RESPONSIVE_20261119_V1 */"))
        self.assertEqual(css.count("MAD4B_SEARCH_POPUP_RESPONSIVE_20261119_V1"), 1)
        self.assertIn("@media(max-width:767px)", css)
        self.assertIn("#elementor-popup-modal-41878", css)
        self.assertIn(".elementor-41728", css)
        self.assertEqual(set(re.findall(r"#elementor-popup-modal-(\d+)", css)), {"41878"})
        self.assertIn("position:relative!important", css)
        self.assertIn("overflow-y:auto", css)
        self.assertNotIn("overflow:hidden!important", css)

    def test_release_helper_fails_closed_and_does_not_global_purge(self):
        code = PHP.read_text()
        for token in (
            "PREIMAGE_NOT_APPROVED_OR_CHANGED",
            "RAW_POSTMETA_PREIMAGE_MISMATCH",
            "INSTALLED_POPUP_DRIFT_REQUIRES_RECONCILIATION",
            "MAD4B_POPUP_APPLY",
            "BINARY meta_value=%s",
            "wp_cache_delete($id, 'post_meta')",
            "new \\Elementor\\Core\\Files\\CSS\\Post($id)",
            "do_action('litespeed_purge_url', home_url('/'))",
        ):
            self.assertIn(token, code)
        for forbidden in ("litespeed_purge_all", "shell_exec(", "file_put_contents('/var/www/html", "wp_update_post("):
            self.assertNotIn(forbidden, code)

    def test_conditional_elementor_js_exclusions(self):
        data = json.loads(JS.read_text())
        self.assertEqual(data["when"], "only-if-litespeed-js-combine-is-enabled")
        self.assertTrue(data["do_not_toggle_combine_or_apply_to_sites_where_combining_is_off"])
        paths = data["entries"]
        self.assertEqual(len(paths), 7)
        self.assertEqual(len(set(paths)), 7)
        self.assertIn("elementor-pro/assets/js/elements-handlers", paths)
        self.assertIn("elementor/assets/js/frontend", paths)


if __name__ == "__main__":
    unittest.main()
