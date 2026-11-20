#!/usr/bin/env python3
"""Host v126 direct master SCP Site Profile exact Staging delta contract."""
from pathlib import Path
import hashlib
import re
import unittest

ROOT=Path(__file__).resolve().parents[2]
FILE=ROOT/"wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php"
EXPECTED_SHA="7698ea2882d95bcc8eab3a029d0d9c4814b206d9a1e1d20de4b4546318e8d7ee"
FUNCTION=re.compile(r"\b(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(")
class SourceParity(unittest.TestCase):
 def test_01_exact_original_staging_source_sha(self):
  self.assertTrue(FILE.is_file())
  self.assertEqual(hashlib.sha256(FILE.read_bytes()).hexdigest(),EXPECTED_SHA)
 def test_02_unchanged_public_api_function_count(self):
  functions=FUNCTION.findall(FILE.read_text())
  self.assertEqual(len(functions),88)
  self.assertEqual(len(set(functions)),88)
 def test_03_wpml_language_origin_is_guarded_by_original_host_and_locale(self):
  s=FILE.read_text()
  self.assertIn("class_exists( 'SitePress', false )",s)
  self.assertIn("$sitepress->get_this_lang()",s)
  self.assertIn("wpml_current_language",s)
  self.assertIn("HTTP_HOST",s)
  self.assertIn("hidden_languages",s)
  self.assertIn("active_languages",s)
  self.assertIn("site_url( '/' )",s)
 def test_04_cli_scheme_fallback_is_only_for_signed_native_cli_context(self):
  s=FILE.read_text()
  self.assertIn("defined( 'WP_CLI' ) && WP_CLI",s)
  self.assertIn("get_option( 'home'",s)
  self.assertIn("get_option( 'siteurl'",s)
  self.assertIn("hash_equals( $expected, $home_saved )",s)
  self.assertIn("hash_equals( $expected, $site_saved )",s)
 def test_05_reserved_uuid_never_trusts_unattested_caller_input(self):
  s=FILE.read_text()
  self.assertIn("mad4b_scp_authorized_reserved_site_uuid",s)
  for code in ("mad4b_reserved_site_uuid_invalid",
      "mad4b_reserved_site_uuid_not_attested",
      "mad4b_reserved_site_uuid_identity_conflict"):
   self.assertIn(code,s)
  self.assertIn("hash_equals( $reserved_uuid, strtolower( $verified_reserved_uuid ) )",s)
 def test_06_profile_write_code_count_is_not_broadened(self):
  s=FILE.read_text()
  self.assertEqual(s.count("update_option("),6)
  self.assertEqual(s.count("add_option("),3)
  self.assertEqual(s.count("current_user_can("),6)
 def test_07_source_is_noninteractive_until_merging_and_deploying(self):
  self.assertNotIn(b"-----BEGIN PRIVATE KEY-----",FILE.read_bytes())
  self.assertFalse((ROOT/"wp-content/plugins/mad4b-company-portfolio-manager").exists())
if __name__=="__main__":
 unittest.main(verbosity=2)
