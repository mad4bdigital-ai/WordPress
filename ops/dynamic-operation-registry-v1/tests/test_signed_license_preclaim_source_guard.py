"""Versioned Original 313 guard accepts exactly reviewed source, refuses unknowns."""
import importlib.util,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
source=Path(__file__).resolve().parents[1]/'integrations/guard_signed_license_preclaim_worker_source.py'
spec=importlib.util.spec_from_file_location('signed_source_guard_candidate',source)
g=importlib.util.module_from_spec(spec);spec.loader.exec_module(g)

class GuardTests(unittest.TestCase):
    def setUp(self):
        # Earlier historical source-guard tests share the same imported
        # guard_original_328_release module. Isolate v2's exact pins per test.
        self.patched=patch.multiple(
            g.guard,
            CORE=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py'),
            VETTED=source.parent/'reviewed-source/claimed-job-worker-license-preclaim-v1.py',
            REPORT=Path('/opt/mad4b-company-provisioning/reports/original-313-worker-signed-preclaim-source-guard-current.json'),
            EXPECTED_NEW='c98aa36ee312dc96589474540216cefee6831b0df4c2acd522be6083579811b8',
            EXPECTED_OLD='df7394fd2d9ecdae81fcf689af21a10016f91abe99450a454a90e1b09af162a1',
            KNOWN_OLD=('df7394fd2d9ecdae81fcf689af21a10016f91abe99450a454a90e1b09af162a1',
                       'bb505a2fb0b3e0834ce815b4c17b380e2fdde1193d8cdd6424b785223f315c6f',
                       '636068349b49be7906dc419462637988f3bf290e4647501e80415ab1abe1ffb3',
                       '9ec8a84a2ea3a6e4ddea258ca7443ecb041fafd3711af427fd860d17de07750c',
                       'c0d93c133f538e8323c999a2512824b965e27568e25aac7a69347942b452e0f9'))
        self.patched.start()
        self.addCleanup(self.patched.stop)
    def test_new_firstparty_worker_current(self):
        x=g.guard.run(False)
        self.assertEqual(x['action'],'PASS_ALREADY_CURRENT')
        self.assertFalse(x['known_version_restored'])
        self.assertFalse(x['new_313_certification'])

    def test_unknown_source_never_repaired(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);file=root/'unknown.py';file.write_bytes(g.guard.VETTED.read_bytes()+b'\n# unknown edit\n')
            with patch.object(g.guard,'CORE',file),patch.object(g.guard,'REPORT',root/'report.json'):
                r=g.guard.run(False)
                self.assertEqual(r['action'],'DEFER')
                self.assertEqual(r['reason'],'UNKNOWN_RELEASE_SOURCE_DO_NOT_OVERWRITE')
                self.assertEqual(file.read_bytes(),g.guard.VETTED.read_bytes()+b'\n# unknown edit\n')

    def test_known_historical_v1_not_silently_overwritten(self):
        original=Path('/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/candidates/claimed-job-worker-postmeta-v6-code-release-merged.py')
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);file=root/'historic.py';file.write_bytes(original.read_bytes())
            with patch.object(g.guard,'CORE',file),patch.object(g.guard,'REPORT',root/'report.json'):
                r=g.guard.run(False)
                self.assertEqual(r['action'],'KNOWN_REGRESSION_REPAIR_AVAILABLE')
                self.assertFalse(r['known_version_restored'])
                self.assertEqual(file.read_bytes(),original.read_bytes())

    def test_reviewed_source_drift_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);file=root/'source.py';file.write_bytes(g.guard.VETTED.read_bytes()+b'\n# tampered\n')
            with patch.object(g.guard,'VETTED',file),patch.object(g.guard,'REPORT',root/'report.json'):
                r=g.guard.run(False)
                self.assertEqual(r['action'],'DEFER')
                self.assertEqual(r['reason'],'PINNED_328_PATCH_CHANGED')

if __name__=='__main__':unittest.main()
