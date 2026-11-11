"""Exact Form #6 passport country AJAX source is opt-in and value-preserving."""
import json
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'wp-content/plugins/mad4b-platform'
PHP=BASE/'modules/presentation/327-mad4b-fluent-passport-locale.php'
JS=BASE/'legacy-mu-tree/assets/mad4b-passport-country-ajax-v1.js'
CSS=BASE/'legacy-mu-tree/assets/mad4b-passport-country-ajax-v1.css'
M=json.loads((ROOT/'docs/release-coverage/missing-live-code-source-snapshot-20261111.json').read_text())

class PassportSource(unittest.TestCase):
 def test_manifest_binds_exact_files(self):
  import hashlib
  for f in (PHP,JS,CSS):
   entry=next(x for x in M['files'] if x['path']==f.relative_to(ROOT).as_posix())
   self.assertEqual(hashlib.sha256(f.read_bytes()).hexdigest(),entry['sha256'])
 def test_saved_option_values_are_read_only(self):
  s=PHP.read_text()
  self.assertIn('private const LEGACY_VALUES_SHA=',s)
  match=re.search(r"private const LEGACY_CODES='([^']+)';",s)
  self.assertIsNotNone(match)
  self.assertEqual(len(match.group(1).split()),195)
  self.assertIn("($node['attributes']['name']??null)==='dropdown_1'",s)
  self.assertIn("count($opts)!==195",s)
  self.assertIn("hash_equals(self::LEGACY_VALUES_SHA,hash('sha256',wp_json_encode($values)))",s)
  self.assertIn("form_fields FROM",s)
  self.assertNotIn('UPDATE fluentform_forms',s)
 def test_search_contract_is_bounded_and_public_evidence_only(self):
  s=PHP.read_text()
  self.assertIn('array_slice($matches,0,14)',s)
  self.assertIn("strlen($q)>64",s)
  self.assertIn("count($rows)!==195",s)
  self.assertIn("'permission_callback'=>'__return_true'",s)
  self.assertIn("if(!self::active())",s)
  self.assertIn("is_singular('tours-and-activities')",s)
  self.assertIn("WPMU_PLUGIN_DIR.'/assets/mad4b-passport-country-ajax-v1.js'",s)
 def test_search_widget_keeps_original_fluent_value(self):
  s=JS.read_text()
  self.assertIn("form.fluent_form_6 select[name=\"dropdown_1\"]",s)
  self.assertIn("available.has(item.value)",s)
  self.assertIn("field.dispatchEvent(new Event('change'",s)
  self.assertIn("!field.closest('form.fluent_form_6')",s)
  self.assertIn("field.classList.remove('mad4b-passport-native-hidden')",s)
  self.assertNotIn("innerHTML =",s)
  self.assertIn("cache.size>45",s)
  self.assertIn("controller?.abort()",s)
 def test_site_specific_identity_is_not_global_publisher(self):
  s=PHP.read_text()
  self.assertIn("private const SITE_UUID=",s)
  self.assertIn("wp_get_environment_type()!=='staging'",s)
  self.assertIn("self::site_active()",s)
  self.assertFalse(M['allow_release'])
if __name__=='__main__':unittest.main()
