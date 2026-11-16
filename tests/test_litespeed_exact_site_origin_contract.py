"""CI contract: exact-origin LiteSpeed purge keeps new scope guards."""
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-litespeed-adapter.php'
WF=ROOT/'.github/workflows/mad4b-site-control-plane.yml'
class PurgeScope(unittest.TestCase):
 def test_same_origin_required_before_purge(self):
  text=SRC.read_text()
  start=text.index('public function purge_url(')
  body=text[start:text.index('public function purge_all()',start)]
  for witness in (
   'wp_parse_url( home_url() )',
   "strtolower( $target['scheme']",
   "strtolower( $target['host']",
   "isset( $target['port'] )",
   "isset( $target['user'] )",
   "isset( $target['pass'] )",
   "isset( $target['fragment'] )",
   'Only the exact enrolled WordPress site origin can be purged.',
  ):
   self.assertIn(witness,body)
  self.assertLess(body.index('Only the exact enrolled WordPress site origin can be purged.'),
                  body.index("do_action( 'litespeed_purge_url'"))
 def test_workflow_asserts_current_stronger_source(self):
  wf=WF.read_text()
  self.assertIn('Only the exact enrolled WordPress site origin can be purged.',wf)
  self.assertNotIn('Only this WordPress site can be purged',wf)
if __name__=='__main__':unittest.main()
