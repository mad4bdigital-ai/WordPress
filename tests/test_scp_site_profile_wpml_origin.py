"""Site Profile query-safe WPML origin and 4 no-op source-newline differences."""
import hashlib,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'docs/release-coverage/scp-wpml-origin-and-format-source-20261111.json').read_text())
P='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php'
class WPMLOrigin(unittest.TestCase):
 def test_original_git_preimages_and_reviewed_sha_are_exact(self):
  self.assertEqual(len(M['files']),5)
  for r in M['files']:
   previous=subprocess.check_output(['git','-C',str(ROOT),'show',
      M['base_git_head']+':'+r['path']],timeout=20)
   current=(ROOT/r['path']).read_bytes()
   self.assertEqual(hashlib.sha256(previous).hexdigest(),r['original_sha256'])
   self.assertEqual(hashlib.sha256(current).hexdigest(),r['candidate_sha256'])
   if r['scope']=='one_final_newline_only':
    self.assertEqual(current,previous+b'\n',r['path'])
 def test_only_specific_canonical_origin_wpml_branch_changed(self):
  s=(ROOT/P).read_text()
  code=s[s.index('public static function current_origin()'):
    s.index('public static function current_host()')]
  self.assertIn("class_exists( 'SitePress', false )",code)
  self.assertIn("$sitepress instanceof \\SitePress",code)
  self.assertIn("$sitepress->get_this_lang()",code)
  self.assertIn("hash_equals( $code,",code)
  self.assertIn("hash_equals( $stored . '/' . $code, $observed )",code)
  self.assertIn("hash_equals( $expected_host, $request_host )",code)
  self.assertNotIn("'wpml_active_languages'",code)
 def test_registered_identity_policy_unchanged(self):
  s=(ROOT/P).read_text()
  self.assertIn("const CONTRACT = 'mad4b.site-profile.v2'",s)
  self.assertIn("const OPTION = 'mad4b_scp_site_profile_v2'",s)
  self.assertIn("PRODUCTION_WRITE_CONFIRMATION",s)
  self.assertIn("REVOCATION_AUDIT_OUTBOX_OPTION",s)
  self.assertFalse(M['database_changed'])
  self.assertFalse(M['release_approved'])
if __name__=='__main__':unittest.main()
