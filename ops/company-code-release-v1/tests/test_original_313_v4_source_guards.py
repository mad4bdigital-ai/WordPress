"""Validate pinned original 313 v4 and signed-grant 306 recovery.

All mutation tests happen in isolated temporary directories. Actual VPS
original source is never replaced by the unit test.
"""
from __future__ import annotations
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
import hashlib

ROOT=Path(__file__).resolve().parents[1]
S=Path('/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator')
PINS=[
  ('guard_original_313_v4_release.py',
   ROOT.parent/'dynamic-operation-registry-v1/php/313b-first-party-dynamic-v4.php',
   S/'candidates/313b-mad4b-native-independent-verifier-option-v3.php',
   '8cb58178a3cc16d0cfd10cd27ac30b797a5041692deb835cb264e4cb2565329a'),
  ('guard_original_313_worker_preclaim_release.py',
   ROOT.parent/'dynamic-operation-registry-v1/integrations/claimed-job-worker-signed-grant-preclaim.py',
   S/'candidates/claimed-job-worker-encrypted-wpcli.py',
   'f75b5849de8838e67bd89f72375dbb90c9e4b596b6fb8b088828283e4fb8e45c')
]

class Original313GuardPinnedTests(unittest.TestCase):
    def _guard(self,script):
        spec=importlib.util.spec_from_file_location(
            'pinned_'+script.replace('.','_'),ROOT/'integrations'/script)
        mod=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        return mod.guard

    def test_genuine_live_first_party_snapshots_are_pinned(self):
        for script,source,previous,expected in PINS:
            with self.subTest(script=script):
                self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(),expected)
                self.assertTrue(previous.is_file())
                m=self._guard(script)
                self.assertEqual(m.EXPECTED_NEW,expected)
                self.assertEqual(hashlib.sha256(previous.read_bytes()).hexdigest(),
                                 m.EXPECTED_OLD)

    def test_recovery_old_versions_and_unknown_newer_defer(self):
        for script,source,previous,expected in PINS:
            with self.subTest(script=script),tempfile.TemporaryDirectory() as folder:
                m=self._guard(script)
                keys=('CORE','VETTED','REPORT','BACKUPS','LOCK')
                old={key:getattr(m,key) for key in keys}
                base=Path(folder)
                try:
                    m.CORE=base/'runtime-original'
                    m.VETTED=source
                    m.REPORT=base/'report.json'
                    m.BACKUPS=base/'backups'
                    m.LOCK=base/'lock'
                    m.CORE.write_bytes(previous.read_bytes())
                    self.assertEqual(m.run(False)['action'],
                                     'KNOWN_REGRESSION_REPAIR_AVAILABLE')
                    self.assertEqual(m.run(True)['action'],
                                     'SIGNED_328_KNOWN_BASELINE_RECOVERED')
                    self.assertEqual(m.CORE.read_bytes(),source.read_bytes())
                    self.assertEqual(m.run(True)['action'],'PASS_ALREADY_CURRENT')
                    changed=source.read_bytes()+b'\n# Newer unregistered change\n'
                    m.CORE.write_bytes(changed)
                    result=m.run(True)
                    self.assertEqual(result['action'],'DEFER')
                    self.assertEqual(result['reason'],'UNKNOWN_RELEASE_SOURCE_DO_NOT_OVERWRITE')
                    self.assertEqual(m.CORE.read_bytes(),changed)
                finally:
                    for key,value in old.items():setattr(m,key,value)

    def test_systemd_uses_v4_guards_not_legacy(self):
        s=(ROOT/'integrations/mad4b-original-source-release-v4-preclaim.service').read_text()
        self.assertIn('guard_original_313_v4_release.py --apply',s)
        self.assertIn('guard_original_313_worker_preclaim_release.py --apply',s)
        self.assertNotIn('guard_original_313_v3_release.py --apply',s)
        self.assertNotIn('guard_original_313_worker_release.py --apply',s)
        self.assertLess(s.index('guard_original_305_intake_release.py'),
                        s.index('guard_original_313_v4_release.py'))

if __name__=='__main__':
    unittest.main()
