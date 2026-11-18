#!/usr/bin/env python3
"""Hermetic source proof for opt-in UAEL gallery lazy native initialization."""
from pathlib import Path
import re,hashlib,unittest,subprocess
ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'wp-content/mu-plugins/425-mad4b-uael-gallery-viewport-init.php'

class GalleryContract(unittest.TestCase):
 def test_immutable_sha_pinned(self):
  script=PHP.read_text()
  digest=re.search(r"ASSET_SHA256\s*=\s*'([a-f0-9]{64})'",script).group(1)
  path=PHP.parent/'mad4b-uael-gallery-viewport'/('uael-gallery.'+digest[:12]+'.js')
  self.assertTrue(path.is_file())
  self.assertEqual(digest,hashlib.sha256(path.read_bytes()).hexdigest())

 def test_opt_in_and_canary_guard(self):
  php=PHP.read_text()
  for required in ["'off'","'on'","'canary'","mad4b_uael_gallery_viewport_probe=1","HTTP_X_MAD4B_BYPASS_CACHE","HTTP_X_MAD4B_UAEL_GALLERY_CANARY","is_front_page()","is_user_logged_in()"]:
   self.assertIn(required,php)

 def test_preserve_native_and_no_global_js_delay(self):
  php=PHP.read_text()
  self.assertIn("wp_script_is('jquery-core','done')",php)
  self.assertIn(",'head'],10)",php)
  text=next((PHP.parent/'mad4b-uael-gallery-viewport').glob('*.js')).read_text()
  self.assertIn('original.call(this,name,wrapped,priority)',text)
  self.assertIn('callback.apply(job.context,job.args)',text)
  self.assertIn("'frontend/element_ready/uael-image-gallery.default'",text)
  self.assertIn("box.width<1 || box.height<1",text)
  self.assertIn("observer.observe(element)",text)

 def test_native_runtime_semantics(self):
  path=ROOT/'tests/uael-gallery-viewport-runtime.js'
  result=subprocess.run(['node',str(path)],capture_output=True,text=True)
  self.assertEqual(result.returncode,0,result.stderr)
  self.assertIn('PASS UAEL gallery',result.stdout)

if __name__=='__main__':unittest.main()
