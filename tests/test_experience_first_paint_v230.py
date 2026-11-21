"""Exact immutable source-owned release overlay. Non-authorizing."""
import hashlib
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / 'ops/experience-first-paint-v1'


class FirstPaintRelease(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.contract = json.loads((BASE/'contract.json').read_text())
        cls.js = (BASE/'release/mad4b-city-visible-image.339807974258.js').read_text()
        cls.php = (BASE/'release/366-mad4b-city-visible-image.php').read_text()

    def test_contract_and_target_scope(self):
        self.assertEqual(self.contract['contract'], 'mad4b.experience-first-paint.city-geometry.v1')
        self.assertFalse(self.contract['auto_promotion'])
        self.assertEqual(set(self.contract['live_site_targets']), {'tours.dev.mad4b.com','wayfatours.com'})
        self.assertIn('home/city widget', self.contract['site_scope'])

    def test_pinned_exact_file_hashes(self):
        for entry in self.contract['changes']:
            source = BASE / entry['source']
            self.assertTrue(source.is_file())
            self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(), entry['source_sha256'])
            self.assertNotIn('..', Path(entry['source']).parts)

    def test_bounded_widget_mutation(self):
        self.assertIn("version:'2.3.0'", self.js)
        self.assertIn("var selector='.elementor-element-95d1c9c';", self.js)
        self.assertIn("mutationObserver.observe(home,{childList:true,subtree:true})", self.js)
        self.assertNotIn("mutationObserver.observe(document.body||root,{childList:true,subtree:true})", self.js)
        self.assertIn("stats.mutationScope='city-widget'", self.js)
        self.assertIn("stats.fallbackMode='bounded'", self.js)
        self.assertRegex(self.js, r"if\(!intersectionObserver\)\s*\{[\s\S]{0,120}setInterval\(fallback,1100\)")
        self.assertIn("if(!intersectionObserver)window.addEventListener('scroll',schedule", self.js)

    def test_content_addressed_fallback(self):
        self.assertIn(self.contract['changes'][1]['source_sha256'], self.php)
        self.assertIn("mad4b-city-visible-image.339807974258.js", self.php)
        self.assertIn("$jsName='mad4b-city-visible-image.js';", self.php)
        self.assertIn("!is_link($candidate)", self.php)
        self.assertIn("hash_file('sha256',$candidate)", self.php)
        self.assertIn("wp_enqueue_script('mad4b-city-visible-image'", self.php)

    def test_does_not_change_commerce_or_optimizers(self):
        for term in ("wp_dequeue_script", "wp_dequeue_style", "litespeed.conf.optm-js_defer",
                     "woocommerce_calc_taxes", "remove_all_actions", "get_option('woocommerce"):
            self.assertNotIn(term,self.php+self.js)


if __name__ == '__main__':
    unittest.main()
