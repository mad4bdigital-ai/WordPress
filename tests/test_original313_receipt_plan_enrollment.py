"""Exact receipt plan must remain metadata until current 305/306/312 grants."""
from pathlib import Path
import importlib.util,json,tempfile,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
P=ROOT/'ops/dynamic-operation-registry-v1/receipt-reconcile-v1/enroll_receipt_recovery_plan.py'
sp=importlib.util.spec_from_file_location('verified_exact_receipt_plan_enroll',P)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)

class EnrollmentTests(unittest.TestCase):
    def test_exact_source_preflight_has_no_authority(self):
        r=m.enroll()
        self.assertEqual(r['operation_id'],'company.code.release.receipt.reconcile')
        self.assertIn(r['state'],('ENROLLMENT_READY','PLAN_ALREADY_REGISTERED'))
        self.assertFalse(r['job_created'])
        self.assertFalse(r['grant_issued'])
        self.assertFalse(r['original_313_certified'])

    def test_enroll_disposable_store_then_confirm_independent_adapter(self):
        with tempfile.TemporaryDirectory() as folder:
            store=Path(folder)/'plans';store.mkdir(mode=0o700)
            with patch.object(m,'STORE',store):
                result=m.enroll(install=True,store=store)
                self.assertEqual(result['state'],'RECEIPT_ONLY_PLAN_REGISTERED_NO_GRANT')
                self.assertEqual(m.enroll(install=True,store=store)['state'],'PLAN_ALREADY_REGISTERED')
                f=store/(result['plan_sha256']+'.json')
                self.assertEqual(f.stat().st_mode&0o777,0o600)
                self.assertEqual(json.loads(f.read_text())['original_job_id'],result['original_job_id'])
                self.assertFalse(result['original_313_certified'])

    def test_tampered_plan_denied_before_enrollment(self):
        with tempfile.TemporaryDirectory() as folder:
            store=Path(folder)/'plans';store.mkdir(mode=0o700)
            x=json.loads(m.REQUEST.read_text());x['action']='apply'
            q=Path(folder)/'tampered.json';q.write_text(json.dumps(x));q.chmod(0o600)
            with self.assertRaises(Exception):m.validate(store,q)
            self.assertEqual(list(store.iterdir()),[])

    def test_existing_untrusted_file_cannot_be_overwritten(self):
        with tempfile.TemporaryDirectory() as folder:
            store=Path(folder)/'plans';store.mkdir(mode=0o700)
            r=m.enroll(store=store)
            f=store/(r['plan_sha256']+'.json');f.write_bytes(b'old');f.chmod(0o600)
            with self.assertRaises(m.Denied):m.enroll(store=store)
            self.assertEqual(f.read_bytes(),b'old')

if __name__=='__main__':unittest.main()
