"""Historical installation resource state is not a new 305/312/313 authority."""
import hashlib,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'docs/release-coverage/portfolio-historical-install-intent-provenance-20261111.json').read_text())
BASE='wp-content/plugins/mad4b-company-portfolio-manager/includes/'
OWNER=ROOT/(BASE+'class-mad4b-company-experience-install-owner-launch.php')
PROVIDER=ROOT/(BASE+'class-mad4b-company-native-plugin-install-provider.php')
PLANNER=ROOT/(BASE+'class-mad4b-company-plugin-sync-operation.php')
class PortfolioHistorical(unittest.TestCase):
 def test_reviewed_three_exact_live_binaries_and_original_git_preimages(self):
  self.assertEqual(len(M['files']),3)
  for row in M['files']:
   original=subprocess.check_output(['git','-C',str(ROOT),'show',
     M['base_head']+':'+row['path']],timeout=20)
   current=(ROOT/row['path']).read_bytes()
   self.assertEqual(hashlib.sha256(original).hexdigest(),row['base_sha256'])
   self.assertEqual(hashlib.sha256(current).hexdigest(),row['candidate_sha256'])
   self.assertEqual(row['candidate_sha256'],row['live_sha256'])
 def test_historical_owner_does_not_serve_as_313_certificate(self):
  s=OWNER.read_text()
  self.assertIn('public static function terminal_status():array',s)
  self.assertIn("'original_313_certified'=>false",s)
  self.assertIn("($job['independent_readback_verified']??null)!==true",s)
  self.assertIn("($history['contract']??null)!=='mad4b.original-wpcli-historical-evidence-reconciliation.v1'",s)
  self.assertIn("($job['company']??null)!==self::COMPANY",s)
  self.assertIn("($job['site_uuid']??null)!==self::SITE",s)
  self.assertIn("'reinstallation_required'=>false",s)
  self.assertIn("if(($terminal['job_id']??null)===$id)",s)
 def test_provider_avoids_reinstall_without_minting_new_authority(self):
  s=PROVIDER.read_text()
  self.assertIn("'ALREADY_INSTALLED_NO_NEW_JOB_REQUIRED'",s)
  self.assertIn("'provider_ready'=>false",s)
  self.assertIn("'original_313_certified'=>false",s)
  self.assertIn("'target_mutated'=>false",s)
  self.assertIn("!class_exists('MAD4B_Central_VPS_Broker_V1',false)",s)
  self.assertIn("EXECUTOR_SOURCE_NOT_TRUSTED",s)
 def test_planning_journal_not_mislabeled_as_native_execution(self):
  s=PLANNER.read_text()
  for label in ("'lifecycle_stage'=>'INTENT_ONLY_NOT_NATIVE_JOB'",
                "'journal_record_kind'=>'PLANNED_INTENT_NOT_ORIGINAL_305_JOB'",
                "'original_305_job_issued'=>false",
                "'original_306_claimed'=>false",
                "'native_312_executed'=>false",
                "'independent_313_verified'=>false",
                "'mu_plugin_deploy_requires_dedicated_provider'=>true",
                "'production_mutation_authorized'=>false"):
   self.assertIn(label,s)
 def test_source_only_and_no_operator_grants(self):
  self.assertTrue(M['no_company_files_installed'])
  self.assertTrue(M['no_new_grants_issued'])
  self.assertTrue(M['no_new_job_issued'])
  self.assertFalse(M['global_release_authorized'])
  self.assertFalse(M['runtime_status_tested_on_live_site'])
if __name__=='__main__':unittest.main()
