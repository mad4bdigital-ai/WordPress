import hashlib,subprocess,unittest
from pathlib import Path
BASE=Path(__file__).resolve().parent
class LocalGitReleaseTests(unittest.TestCase):
 def digest(self,name):return hashlib.sha256((BASE/name).read_bytes()).hexdigest()
 def test_exact_versioned_source_digest(self):
  self.assertEqual(self.digest('integration.py'),'943fdb87dd095ebbd2bd3c8bc3eaca60c685d8ff3b005f24f50afd231947ceb1')
  self.assertEqual(self.digest('integration.previous.py'),'ba61105b27175055e2b811cb8341ae8f41b7c72e723483978902b2e8859440f8')
 def test_exact_original_dropin_digest(self):
  self.assertEqual(self.digest('90-channel-governance-integration.conf'),'c8b93b0857a1b6b2093a89ecd820fd670d7b43bf38680b43ee92f00588600e20')
 def test_install_and_rollback_shell_syntax(self):
  for name in ('install.sh','rollback.sh'):
   with self.subTest(name=name):
    self.assertEqual(subprocess.run(['bash','-n',str(BASE/name)],check=False).returncode,0)
 def test_rollback_is_source_bound_and_no_service_start(self):
  data=(BASE/'rollback.sh').read_text()
  self.assertIn('ORIGINAL_RUNTIME_CHANGED_ABORT',data)
  self.assertIn('DISPATCH_ACTIVE_RETRY_WHEN_IDLE',data)
  self.assertIn('--to-previous',data)
  self.assertIn('--pre-integration',data)
  self.assertNotIn('systemctl start',data)
 def test_certifier_requires_completed_exact_journal_cycle(self):
  data=(BASE/'certify.py').read_text()
  self.assertIn('COMPLETED_ORIGINAL_DISPATCHER_JOURNAL_CYCLE_NOT_FOUND',data)
  self.assertIn('GIT_SOURCE_MISMATCH_WITH_LIVE',data)
  self.assertIn('GIT_UNIT_MISMATCH_WITH_LIVE',data)
if __name__=='__main__':unittest.main()
