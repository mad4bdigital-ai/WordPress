
#!/usr/bin/env python3
"""Offline, source-only regressions for verified MAD4B first-paint geometry changes."""
import hashlib
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CITY = ROOT / "wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-city-visible-image.js"
CITY_OVERLAY = ROOT / "ops/site-company-overlays/wayfatours/media-runtime/wp-content/mu-plugins/assets/mad4b-city-visible-image.js"
NAV_LOADER = ROOT / "wp-content/mu-plugins/mad4b-navigation-lazy-listings.php"
NAV_DIR = ROOT / "wp-content/mu-plugins/mad4b-navigation-lazy-listings"
CITY_MODULE = ROOT / "ops/site-company-overlays/wayfatours/performance-runtime/wp-content/plugins/mad4b-experience/src/presentation/modules/366-mad4b-city-visible-image.php"

class MainThreadGeometryContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.city = CITY.read_text()
        cls.nav_loader = NAV_LOADER.read_text()
        cls.digest = re.search(r"ASSET_SHA256\s*=\s*'([a-f0-9]{64})'",cls.nav_loader).group(1)
        cls.nav_path = NAV_DIR / ("navigation." + cls.digest[:12] + ".js")
        cls.nav = cls.nav_path.read_text()

    def test_canonical_city_assets_have_identical_bytes(self):
        self.assertEqual(CITY.read_bytes(),CITY_OVERLAY.read_bytes())
        self.assertIn("version:'2.2.1'",self.city)

    def test_city_scans_separate_reads_and_writes(self):
        block=self.city.split("function scan(){",1)[1].split("function schedule(){",1)[0]
        self.assertIn("var list=cards(),toReveal=[],sliderRects=new WeakMap();",block)
        self.assertIn("if(near(card,sliderRects))toReveal.push(card);",block)
        self.assertIn("for(var j=0;j<toReveal.length;j++)reveal(toReveal[j]);",block)
        self.assertLess(block.index("near(card,sliderRects)"),block.index("reveal(toReveal[j])"))
        self.assertIn("var s=sliderRects && sliderRects.get(slider);",self.city)
        observer=self.city.split("intersectionObserver=new IntersectionObserver(function(entries){",1)[1].split("rootMargin:",1)[0]
        self.assertLess(observer.index("near(entries[i].target,sliderRects)"),observer.index("reveal(toReveal[j])"))

    def test_navigation_immutable_asset_is_content_addressed(self):
        self.assertEqual(hashlib.sha256(self.nav.encode()).hexdigest(),self.digest)
        self.assertEqual(self.nav_path.name,"navigation.c9315f96239b.js")
        self.assertIn("Version: 1.2.1",self.nav_loader)
        self.assertTrue((NAV_DIR / "navigation.ea09566dff9a.js").is_file())

    def test_navigation_does_not_synchronously_drain_in_jetengine_hook(self):
        block=self.nav.split("engine.lazyLoadListing = function(args)",1)[1].split("};",1)[0]
        self.assertIn("scheduleDrain();",block)
        self.assertNotIn("drain();",block)

    def test_navigation_root_widgets_batch_layout_reads(self):
        block=self.nav.split("function activateVisibleRootListings(root)",1)[1].split("function afterNativeOpen",1)[0]
        self.assertIn("const visible = [];",block)
        self.assertIn("visible.forEach(widget => {",block)
        self.assertLess(block.index("widget.getBoundingClientRect()"),block.index("engine.widgetListingGrid("))

    def test_site_module_no_aggregation_and_fail_open(self):
        src=CITY_MODULE.read_text()
        self.assertIn("data-no-optimize",src)
        self.assertIn("data-no-defer",src)
        self.assertIn("failOpen",src)
        self.assertIn("4800",src)

    def test_javascript_parser(self):
        for source in (CITY,self.nav_path):
            run=subprocess.run(['node','--check',str(source)],capture_output=True,text=True,check=False)
            self.assertEqual(run.returncode,0,run.stderr)

if __name__=='__main__':
    unittest.main()
