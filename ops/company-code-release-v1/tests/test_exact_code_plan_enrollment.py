"""First-party plan enrollment is immutable metadata, never a signed grant."""
import importlib.util,json,tempfile
from pathlib import Path
import unittest

SCRIPT=Path(__file__).resolve().parents[1]/'integrations/enroll_exact_source_plan_v1.py'
sp=importlib.util.spec_from_file_location('first_party_exact_plan_enrollment_test',SCRIPT)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
PLAN=Path('/opt/mad4b-company-provisioning/reports/company-execution-closeout-20261110/wayfa-tenant-workflow-source-exact-release-plan.json')

@unittest.skipUnless(PLAN.exists(),'Host-only exact plan required')
class ExactPlanTests(unittest.TestCase):
    def stores(self,root):
        arr=(root/'dynamic',root/'release')
        for p in arr:p.mkdir(mode=0o700)
        return arr

    def test_exact_original_plan_checks_without_install(self):
        with tempfile.TemporaryDirectory() as td:
            dirs=self.stores(Path(td))
            out=m.enroll(PLAN,False,stores=dirs)
            self.assertEqual(out['state'],'EXACT_PLAN_ELIGIBLE_FOR_ENROLLMENT')
            self.assertFalse(out['grants_created'])
            self.assertFalse(out['wp_files_modified'])
            self.assertEqual(list(dirs[0].iterdir()),[])

    def test_two_store_atomic_registration_and_idempotent_readback(self):
        with tempfile.TemporaryDirectory() as td:
            dirs=self.stores(Path(td))
            out=m.enroll(PLAN,True,stores=dirs)
            self.assertEqual(out['enrolled_store_count'],2)
            self.assertEqual(out['files_inserted'],2)
            self.assertEqual(out['state'],'EXACT_PLAN_REGISTERED_PENDING_SIGNED_BROKER')
            self.assertEqual(list(dirs[0].iterdir())[0].read_bytes(),list(dirs[1].iterdir())[0].read_bytes())
            self.assertEqual(m.enroll(PLAN,True,stores=dirs)['files_inserted'],0)
            self.assertFalse(out['original_313_certified'])

    def test_signature_or_company_scope_tamper_rejected(self):
        raw=json.loads(PLAN.read_text())
        for k,v in [('company','other-company'),('environment','staging'),('plan_sha256','f'*64)]:
            with self.subTest(key=k),tempfile.TemporaryDirectory() as td:
                file=Path(td)/'untrusted-plan.json'
                file.write_text(json.dumps({**raw,k:v}));file.chmod(0o600)
                dirs=self.stores(Path(td))
                with self.assertRaises((m.Denied,RuntimeError,ValueError,KeyError)):
                    m.enroll(file,True,stores=dirs)
                self.assertEqual(len(list(dirs[0].iterdir())),0)

    def test_conflicting_root_plan_is_never_overwritten(self):
        with tempfile.TemporaryDirectory() as td:
            dirs=self.stores(Path(td));data=json.loads(PLAN.read_text())
            p=dirs[0]/(data['plan_sha256']+'.json')
            p.write_bytes(b'{"untrusted":"modified"}\n');p.chmod(0o600)
            with self.assertRaisesRegex(m.Denied,'DIFFERENT_BYTES'):
                m.enroll(PLAN,True,stores=dirs)
            self.assertEqual(p.read_bytes(),b'{"untrusted":"modified"}\n')

    def test_store_permissions_fail_closed(self):
        with tempfile.TemporaryDirectory() as td:
            dirs=self.stores(Path(td));dirs[0].chmod(0o755)
            with self.assertRaisesRegex(m.Denied,'PLAN_STORE_NOT_ROOT_PRIVATE'):
                m.enroll(PLAN,True,stores=dirs)

if __name__=='__main__':unittest.main()
