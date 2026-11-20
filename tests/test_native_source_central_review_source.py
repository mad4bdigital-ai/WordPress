"""Provider-neutral read-only WP bridge and exact CAS safeguards."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
FILE=PLUGIN/'includes/class-mad4b-scp-native-source-release-review.php'

class SourceReadBridge(unittest.TestCase):
 def test_review_module_bootstrapped_read_only_not_a_mutation_provider(self):
  main=(PLUGIN/"mad4b-site-control-plane.php").read_text()
  self.assertEqual(main.count("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-source-release-review.php';"),1)
  self.assertEqual(main.count("MAD4B_SCP_Native_Source_Release_Review::boot();"),1)
  for p in (PLUGIN/"includes/class-mad4b-scp-plugin.php",
            PLUGIN/"includes/class-mad4b-scp-server-core-catalog.php"):
   self.assertNotIn("MAD4B_SCP_Native_Source_Release_Review",p.read_text())
  self.assertTrue(FILE.is_file())
  code=FILE.read_text()
  self.assertNotIn("wp_register_ability( 'mad4b/native-source-release'",code)
  self.assertNotIn("'readonly'=>false",code)
  self.assertIn("'publisher_authorized'=>false",code)
 def test_catalog_read_not_universal_write(self):
  s=FILE.read_text()
  server=(PLUGIN/'includes/class-mad4b-scp-server-core-catalog.php').read_text()
  self.assertIn("const STATUS_ABILITY = 'mad4b/native-source-catalog-status';",s)
  self.assertIn("const PREFLIGHT_ABILITY = 'mad4b/native-source-catalog-preflight';",s)
  self.assertNotIn('MAD4B_SCP_Native_Source_Release_Review::STATUS_ABILITY',server)
  self.assertNotIn('MAD4B_SCP_Native_Source_Release_Review::PREFLIGHT_ABILITY',server)
  self.assertIn("'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true)",s)
  self.assertIn("'publisher_authorized'=>false",s)
  self.assertIn("'authorization_created'=>false",s)
  self.assertIn("'native_manifest_verified_by_wp'=>false",s)
  self.assertNotIn('wp_register_ability( self::MUTATION_ABILITY',s)
 def test_dynamic_operation_site_file_and_cas(self):
  s=FILE.read_text()
  self.assertNotIn('const ORIGIN',s)
  self.assertNotIn('const UUID',s)
  self.assertNotIn('const TARGET',s)
  self.assertNotIn('source.code.release',s)
  self.assertNotIn('tours.dev.mad4b.com',s)
  self.assertIn("'operation_id'=>$plan['operation_id']",s)
  self.assertIn("'source_manifest_sha256'=>$plan['source_manifest_sha256']",s)
  self.assertIn("'operation_binding_sha256'=>$plan['operation_binding_sha256']",s)
  self.assertIn("hash_equals($file['expected_before_sha256'],$current)",s)
  self.assertIn("MAD4B_SCP_Servers::provider_for_ability('mad4b-write',$facts['ability_name'])",s)
  self.assertIn("wp_has_ability($facts['ability_name'])",s)
  for term in ('wp_insert_post(', 'update_option(', 'delete_option(',
               'wp_remote_post(', 'file_put_contents(', 'shell_exec('):
   self.assertNotIn(term,s)
 def test_php_acceptance_is_available(self):
  p=ROOT/'tests/native-source-central-review'
  self.assertTrue((p/'run-php-acceptance.sh').exists())
  self.assertIn('partner.source.update',(p/'acceptance.php').read_text())
if __name__=='__main__':unittest.main()
