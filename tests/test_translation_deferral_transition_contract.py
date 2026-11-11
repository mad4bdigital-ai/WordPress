"""Verify original ContentJob transaction enforces exact source deferral."""
import hashlib,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
J=ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php'
G=ROOT/'wp-content/mu-plugins/423-mad4b-translation-execution-governance.php'
class TranslationGuard(unittest.TestCase):
 def test_original_job_transaction_checks_before_artifact_and_revision(self):
  s=J.read_text()
  guard="MAD4B_Translation_Execution_Governance::transition_denied($row,$new_state,$new_stage,$artifact_id)"
  self.assertEqual(s.count(guard),1)
  assert guard in s
  first=s.index("if ( $expected_revision !== (int) $row['job_revision'] )")
  forbidden=s.index(guard)
  artifact=s.index("$artifact_changed = $artifact_id")
  revision=s.index("$revision = $expected_revision + 1")
  self.assertLess(first,forbidden)
  self.assertLess(forbidden,artifact)
  self.assertLess(artifact,revision)
  self.assertIn("throw new RuntimeException('translation_notification_only_deferred')",s)
  self.assertIn("START TRANSACTION",s)
  self.assertIn("ROLLBACK",s)
 def test_scope_policy_is_read_only_and_site_bound(self):
  s=G.read_text()
  self.assertIn("($job['site_uuid']??'')!==self::SITE_UUID",s)
  self.assertIn("count($p['jobs']) !== 42",s)
  self.assertIn("resume_requires",s)
  self.assertIn("notification_only",s)
 def test_does_not_remove_existing_wpml_restrictions(self):
  s=J.read_text()
  for x in ('content_job_localized_artifact_binding_denied','MAD4B_SCP_Content_Relationship_Graph::planJob','content_job_revision_conflict','content_job_terminal_immutable'):
   self.assertIn(x,s)
if __name__=='__main__':unittest.main()
