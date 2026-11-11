"""Local source-only regression: UI readback is visible without enabling writes."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
P=ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-admin-query-performance-ui.php'
class PerformanceSections(unittest.TestCase):
    def test_read_only_panels_are_wired_once(self):
        source=P.read_text()
        for method in ('render_selective_cache_release','render_css_strategy_status'):
            self.assertEqual(source.count('self::'+method+'();'),1)
            self.assertEqual(source.count('private static function '+method+'()'),1)
    def test_no_activation_implemented_by_display(self):
        source=P.read_text()
        self.assertIn('Selective Render Cache',source)
        self.assertIn('CSS optimization',source)
        self.assertIn('read-only',source.lower())
        self.assertIn('wp_nonce_field',source)
        self.assertNotIn("update_option('litespeed.conf.",source)
    def test_guarded_original_index_maintenance_retained(self):
        source=P.read_text()
        self.assertIn("current_user_can( 'manage_options' )",source)
        self.assertIn("wp_nonce_field( 'mad4b_apply_admin_query_indexes'",source)
if __name__=='__main__':unittest.main()
