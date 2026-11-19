"""No-network preflight and pure ephemeral Ed25519 receipt-grant contract.

Never calls Original 305 intake or creates a Broker Job/Grant on the VPS.
"""
import datetime as dt,importlib.util,sys,unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
from unittest.mock import patch

PKG=Path(__file__).resolve().parents[1]/'ops/dynamic-operation-registry-v1/receipt-reconcile-v1'
sys.path.insert(0,str(PKG))
sp=importlib.util.spec_from_file_location('scoped_original305_recovery_issuer_candidate',PKG/'issue_signed_recovery_305.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
from signed_recovery_admission import verify_document

class Original305ReceiptOnlyIssuer(unittest.TestCase):
    def setUp(self):
        self.now=dt.datetime(2026,11,11,0,30,tzinfo=dt.timezone.utc)
        self.key=Ed25519PrivateKey.generate()
        self.plan={'company':'wayfatours','environment':'production',
                   'site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
                   'origin':'https://wayfatours.com','plan_sha256':'c'*64,
                   'original_job_id':'b'*32,'original_plan_sha256':'d'*64,
                   'original_signed_artifact_hashes':{
                       'original_grant_sha256':'e'*64,'original_host_receipt_sha256':'f'*64}}
        self.scope={'command_sha256':'1'*64,'original_305_grant_id':'transport-grant-01',
                    'original_305_transport_id':'transport-01','original_actor_id':2}
        self.job={'job_id':'a'*32,'company':'wayfatours','environment':'production',
                  'site_uuid':self.plan['site_uuid'],'command_sha256':self.scope['command_sha256'],
                  'grant_id':self.scope['original_305_grant_id'],
                  'transport_id':self.scope['original_305_transport_id'],
                  'central_principal_id':2,'executed':False,
                  'state':'QUEUED_FOR_VPS_BROKER_CLAIM',
                  'expires_epoch':int((self.now+dt.timedelta(minutes=20)).timestamp())}
    def test_real_new_original_job_creates_only_readonly_grant_document(self):
        doc=m.make_grant(self.job,self.plan,self.scope,self.key,now=self.now)
        checked=verify_document(self.plan,self.job['job_id'],self.scope['command_sha256'],doc,
                  self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw),now=self.now)
        self.assertTrue(checked['new_grant_signed_by_existing_broker'])
        self.assertFalse(checked['production_mutation_allowed'])
        self.assertFalse(checked['schema_ddl_allowed'])
        self.assertFalse(checked['original_313_certified'])
        self.assertEqual(doc['original_job_id'],self.plan['original_job_id'])
        self.assertNotEqual(doc['job_id'],doc['original_job_id'])
    def test_old_job_and_missing_305_identity_denied(self):
        cases=[{'job_id':self.plan['original_job_id']},
               {'grant_id':'wrong-company-grant'},
               {'transport_id':'unknown-transport'},
               {'central_principal_id':0},
               {'state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'},
               {'executed':True},
               {'command_sha256':'0'*64}]
        for row in cases:
            with self.subTest(case=row),self.assertRaises(m.Denied):
                m.make_grant({**self.job,**row},self.plan,self.scope,self.key,now=self.now)
    def test_expiring_original_job_cannot_be_extended_by_new_grant(self):
        for secs in [5,420,600,720]:
            with self.subTest(secs=secs),self.assertRaises(m.Denied):
                m.make_grant({**self.job,
                    'expires_epoch':int(self.now.timestamp())+secs},
                    self.plan,self.scope,self.key,now=self.now)
    def test_no_active_provider_preflight_fails_before_job_issuance(self):
        with self.assertRaisesRegex(m.Denied,'RECOVERY_SIGNED_GRANT_PROVIDER_NOT_INSTALLED'):
            # Uses read-only first-party Registry and immutable recovery plan;
            # never invokes signed intake, dispatch or a private key.
            from pathlib import Path
            sys.path.insert(0,str(m.INDEX))
            import registry
            index=registry.Registry(m.INDEX)
            plan=registry.checked_json(m.PLANS/(m.PLAN_SHA+'.json'),m.PLANS)
            m.all_sources_ready(index,plan)
    def test_central_313_independent_recovery_must_exist(self):
        class AbsentCentral:
            @staticmethod
            def cli_json(php):return {'connected':None}
        with self.assertRaisesRegex(m.Denied,'CENTRAL_RECEIPT_ONLY_FINALIZER_NOT_ENROLLED'):
            m.central_recovery_ready(AbsentCentral,2)
    def test_private_key_not_copied_into_new_provider_source(self):
        raw=(PKG/'issue_signed_recovery_305.py').read_text()
        self.assertIn('legacy.signer()',raw)
        self.assertIn("legacy.send('intake'",raw)
        self.assertIn("legacy.send('dispatch'",raw)
        self.assertNotIn('Ed25519PrivateKey.generate(',raw)
        self.assertIn("'grant_issued':None if args.execute else False",raw)
        self.assertNotIn('CREATE TABLE',raw)

if __name__=='__main__':unittest.main()
