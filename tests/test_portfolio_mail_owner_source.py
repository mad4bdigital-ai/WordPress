"""Explicit source-only mail ownership across environments; never sends mail."""
import hashlib,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
MAIL=ROOT/'docs/release-coverage/portfolio-mail-owner-source-20261111.json'
P=ROOT/'wp-content/plugins/mad4b-company-portfolio-manager/mad4b-company-portfolio-manager.php'
CORE=ROOT/'wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'
D=json.loads(MAIL.read_text())
class MailOwnerSource(unittest.TestCase):
 def test_new_exact_main_and_prior_commit(self):
  self.assertEqual(hashlib.sha256(P.read_bytes()).hexdigest(),D['candidate_sha256'])
  past=subprocess.run(['git','-C',str(ROOT),'show',D['preimage_commit']+':'+P.relative_to(ROOT).as_posix()],
      capture_output=True,timeout=15)
  self.assertEqual(past.returncode,0)
  self.assertEqual(hashlib.sha256(past.stdout).hexdigest(),D['preimage_sha256'])
 def test_staging_and_production_both_covered_without_redeclarations(self):
  s=P.read_text().split('final class MAD4B_Company_Portfolio_Manager_V1')[0]
  self.assertIn("in_array(wp_get_environment_type(), ['staging','production'], true)",s)
  for cl in D['early_classes']:
   self.assertIn("!class_exists('"+cl+"', false)",s)
  self.assertEqual(s.count("require_once __DIR__ . '/includes/class-mad4b-scp-company-"),3)
 def test_original_core_production_fallback_is_unchanged(self):
  core=CORE.read_text()
  self.assertIn("'production' === wp_get_environment_type()",core)
  self.assertIn("! in_array( 'mad4b-company-portfolio-manager/mad4b-company-portfolio-manager.php'",core)
  self.assertIn("MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-sender-policy.php'",core)
 def test_mail_source_only_and_delivery_not_certified(self):
  for key in ('production_mail_delivery_verified','production_mutation_executed',
              'smtp_credentials_modified','production_grant_issued','global_release_authorized'):
   self.assertIs(D[key],False)
  self.assertNotIn("function has_production_delegation",P.read_text())
if __name__=='__main__':unittest.main()
