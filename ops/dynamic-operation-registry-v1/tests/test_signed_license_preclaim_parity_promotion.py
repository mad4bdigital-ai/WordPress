"""Bounded two-copy release promotion; preserve signed worker source and grants."""
import importlib.util,os,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
p=Path(__file__).resolve().parents[1]/'integrations/promote_signed_license_preclaim_worker_release.py'
sp=importlib.util.spec_from_file_location('v2_worker_exact_parity',p)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)

class WorkerV2Test(unittest.TestCase):
    def test_live_source_semantic_parity(self):
        out=m.readiness()
        self.assertIn(out['state'],('REVIEWED_CONSOLIDATED_RELEASE_READY','ALREADY_CONSOLIDATED'))
        self.assertTrue(out['source_owner_guard_pinned'])
        self.assertFalse(out['claims_created'])
        self.assertFalse(out['grants_created'])
        self.assertFalse(out['active_worker_modified'])

    def test_unknown_baseline_fails_closed(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);tmp=root/'unknown.py';tmp.write_text('x = 1\n'*1000);tmp.chmod(0o600)
            with patch.object(m,'TARGETS',{'mu':(tmp,'f'*64)}):
                with self.assertRaisesRegex(m.Denied,'UNKNOWN_MU_RELEASE_HASH'):
                    m.readiness()

    def test_missing_license_guard_fails_source_validation(self):
        with tempfile.TemporaryDirectory() as td:
            tmp=Path(td)/'source.py'
            tmp.write_bytes(m.SOURCE.read_bytes().replace(b"row.get('vendor_entitlement_independently_verified',False)",b"row.get('irrelevant',False)"))
            tmp.chmod(0o600)
            with patch.object(m,'SOURCE',tmp),patch.object(m,'NEW',m.dig(tmp)):
                with self.assertRaisesRegex(m.Denied,'REVIEWED_SOURCE_NOT_ACTIVE'):
                    m.readiness()

    def test_unknown_active_worker_drift(self):
        with patch.object(m,'NEW','f'*64):
            with self.assertRaisesRegex(m.Denied,'REVIEWED_SOURCE_NOT_ACTIVE'):
                m.readiness()

if __name__=='__main__':unittest.main()
