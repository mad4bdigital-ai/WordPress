"""Historical host apply evidence must never be promoted to 313 authorization."""
import importlib.util,json,tempfile,unittest
from pathlib import Path

SCRIPT=Path(__file__).resolve().parents[1]/'scripts/audit_original313_signed_historical_code_release.py'
spec=importlib.util.spec_from_file_location('mad4b_historical_signed_exact_release',SCRIPT)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
JOB='a37629fc009a97afe2fb66cef34a48ca'
PLAN='c07011d760b1e7c59ef432a1a38c49cc445a89a674738e58e42b323119179b3f'

class ExactHistoricalAuditTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source={
           'plan_file':m.PLAN_DIR/(PLAN+'.json'),
           'grant_file':m.GRANT_DIR/(JOB+'.json'),
           'reservation_file':m.RESERVATIONS/(JOB+'.reserved'),
           'finish_file':m.FINISH_DIR/(JOB+'-dynamic313-finish.json')}
        g=json.loads(cls.source['grant_file'].read_text())
        cls.source['receipt_file']=Path('/var/lib/mad4b/company-code-release/receipts')/(g['nonce']+'.json')

    def fixture(self,dir):
        args={}
        for key,p in self.source.items():
            dest=dir/p.name
            dest.write_bytes(p.read_bytes());dest.chmod(0o600)
            args[key]=dest
        args['job']=JOB;args['plan_sha']=PLAN
        return args

    def test_signed_historical_evidence_passes_with_313_uncertified(self):
        x=m.audit_exact(JOB,PLAN)
        self.assertEqual(x['state'],'HISTORICAL_SIGNED_APPLY_POSTIMAGE_VERIFIED__313_UNCERTIFIED')
        for field in ('broker_grant_historical_signature_verified',
                      'signed_original_finish_attempt_verified',
                      'signed_original306_reservation_verified',
                      'exact_live_git_postimage_verified'):
            self.assertTrue(x[field],field)
        for field in ('original_313_certified','job_replayed',
                      'broker_grant_extended','wordpress_mutated_by_audit'):
            self.assertFalse(x[field],field)

    def test_grant_signature_cannot_be_forged(self):
        with tempfile.TemporaryDirectory() as d:
            args=self.fixture(Path(d));p=args['grant_file'];x=json.loads(p.read_text());x['signature']='AAA=';p.write_text(json.dumps(x))
            with self.assertRaisesRegex(m.Denied,'HISTORICAL_BROKER_SIGNATURE_INVALID'):
                m.evaluate(**args)

    def test_grant_company_binding_cannot_be_swapped(self):
        with tempfile.TemporaryDirectory() as d:
            args=self.fixture(Path(d));p=args['grant_file'];x=json.loads(p.read_text());x['company']='other-company';p.write_text(json.dumps(x))
            with self.assertRaisesRegex(m.Denied,'HISTORICAL_GRANT_SCOPE_CHANGED_COMPANY'):
                m.evaluate(**args)

    def test_receipt_time_cannot_be_after_signed_window(self):
        with tempfile.TemporaryDirectory() as d:
            args=self.fixture(Path(d));p=args['receipt_file'];x=json.loads(p.read_text());x['at']='2099-01-01T01:00:00+00:00';p.write_text(json.dumps(x))
            with self.assertRaisesRegex(m.Denied,'HISTORICAL_APPLY_OUTSIDE_SIGNED_WINDOW'):
                m.evaluate(**args)

    def test_signed_finish_tamper_denied(self):
        with tempfile.TemporaryDirectory() as d:
            args=self.fixture(Path(d));p=args['finish_file'];x=json.loads(p.read_text());x['signature']='AAA=';p.write_text(json.dumps(x))
            with self.assertRaisesRegex(m.Denied,'HISTORICAL_BROKER_SIGNATURE_INVALID'):
                m.evaluate(**args)

    def test_wrong_original306_reservation_denied(self):
        with tempfile.TemporaryDirectory() as d:
            args=self.fixture(Path(d));args['reservation_file'].write_bytes(b'dynamic:company.code.release '+b'a'*64+b'\n')
            with self.assertRaisesRegex(m.Denied,'ORIGINAL_306_EXACT_RESERVATION_NOT_VERIFIED'):
                m.evaluate(**args)

    def test_receipt_mutation_breaks_signed_finish_digest(self):
        with tempfile.TemporaryDirectory() as d:
            args=self.fixture(Path(d));p=args['receipt_file'];x=json.loads(p.read_text());x['failed_reason']='tampered';p.write_text(json.dumps(x))
            with self.assertRaisesRegex(m.Denied,'HISTORICAL_FINISH_ATTEMPT_SCOPE_CHANGED'):
                m.evaluate(**args)

    def test_different_plan_identity_denied(self):
        with tempfile.TemporaryDirectory() as d:
            args=self.fixture(Path(d));args['plan_sha']='f'*64
            with self.assertRaisesRegex(m.Denied,'EXACT_RELEASE_PLAN_NOT_MATCHED'):
                m.evaluate(**args)

if __name__=='__main__':unittest.main()
