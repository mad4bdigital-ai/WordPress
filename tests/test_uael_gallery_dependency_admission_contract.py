#!/usr/bin/env python3
"""Source-only contract for UAEL carousel-specific script dependency gate."""
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
OWNER=ROOT/'wp-content/mu-plugins/425-mad4b-uael-gallery-viewport-init.php'
class CarouselAssetDependencyGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.source=OWNER.read_text()
    def test_one_plugin_owner_not_parallel_mu_plugin(self):
        self.assertIn('MAD4B_UAEL_Gallery_Viewport_V1',self.source)
        self.assertIn('MAD4B_UAEL_Carousel_Dependency_Gate_V1',self.source)
        self.assertFalse((OWNER.parent/'432-mad4b-uael-carousel-assets-v1.php').exists())
    def test_public_home_site_scope(self):
        for marker in ("get_option(self::OPTION,'off')",
                       "['wayfatours.com','tours.dev.mad4b.com']",
                       'is_user_logged_in()', 'is_preview()', 'is_admin()',
                       'is_front_page()', 'wp_doing_ajax()', 'is_feed()',
                       "HTTP_X_MAD4B_UAEL_CAROUSEL_ASSETS",
                       "HTTP_X_MAD4B_BYPASS_CACHE"):
            self.assertIn(marker,self.source)
    def test_only_unneeded_layout_packages_removed(self):
        self.assertIn("['uael-isotope','uael-justified']",self.source)
        self.assertIn('wp_dequeue_script($handle)',self.source)
        for name in ['uael-slick','uael-fancybox','uael-frontend-script']:
            self.assertIn("wp_script_is('"+name+"','enqueued')",self.source)
        self.assertIn("($settings['gallery_style']??'')==='carousel'",self.source)
        self.assertIn("self::$other_uael!==0",self.source)
        self.assertIn("self::$carousels<1",self.source)
    def test_no_global_modification(self):
        for bad in ['remove_action(', 'wp_deregister_script(', 'wp_dequeue_style(']:
            self.assertNotIn(bad,self.source)
        self.assertIn("add_action('wp_print_footer_scripts'",self.source)
        self.assertIn("add_action('elementor/frontend/widget/before_render'",self.source)
    def test_disabled_by_default_and_canary_audit(self):
        self.assertIn("mad4b_perf_uael_carousel_assets_v1",self.source)
        self.assertIn("get_option(self::OPTION,'off')",self.source)
        self.assertIn("$mode!=='canary'",self.source)
        self.assertIn("get_option(self::OPTION,'off')!=='canary'",self.source)
        self.assertIn("data-pruned=",self.source)
if __name__=='__main__':unittest.main()
