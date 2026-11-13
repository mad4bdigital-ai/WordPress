"""Offline contract tests; NEVER creates a live 305/306/312 job or grant."""
import base64,datetime as dt,importlib.util,json,sys,unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'ops/dynamic-operation-registry-v1/receipt-reconcile-v1'
sys.path.insert(0,str(SRC))
import signed_recovery_admission as a

class PureSignedRecoveryAdmissionTests(unittest.TestCase):
    def setUp(self):
        # Standalone synthetic test keys never saved and never trusted in VPS.
        self.key=Ed25519PrivateKey.generate()
        self.public=self.key.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw)
        self.job='1'*32
        self.old='2'*32
        self.command='3'*64
        self.plan={'company':'wayfatours','environment':'production',
                   'site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
                   'origin':'https://wayfatours.com',
                   'plan_sha256':'4'*64,'original_job_id':self.old,
                   'original_plan_sha256':'5'*64,
                   'original_signed_artifact_hashes':{
                       'original_grant_sha256':'6'*64,
                       'original_host_receipt_sha256':'7'*64}}
        now=dt.datetime(2026,11,11,0,1,0,tzinfo=dt.timezone.utc)
        self.now=now
        self.row={'contract':a.CONTRACT,'action':'receipt_only_readback',
            'issuer':'mad4b-company-broker','job_id':self.job,
            'operation_id':a.OP,'company':self.plan['company'],
            'environment':self.plan['environment'],'site_uuid':self.plan['site_uuid'],
            'origin':self.plan['origin'],'plan_sha256':self.plan['plan_sha256'],
            'command_sha256':self.command,'original_job_id':self.old,
            'original_plan_sha256':self.plan['original_plan_sha256'],
            'historical_grant_sha256':'6'*64,
            'historical_host_receipt_sha256':'7'*64,
            'original_305_grant_id':'test-broker-grant-id',
            'original_305_transport_id':'test-transport-id',
            'production_mutation_allowed':False,'schema_ddl_allowed':False,
            'old_grant_reauthorized':False,'original_313_certified':False,
            'nonce':'a'*32,
            'issued_at':(now-dt.timedelta(seconds=15)).isoformat(),
            'expires_at':(now+dt.timedelta(minutes=10)).isoformat(),
            'signature':''}
    def signed(self,doc=None):
        doc=dict(self.row if doc is None else doc)
        doc['signature']=base64.b64encode(self.key.sign(a.signed_message(doc))).decode()
        return doc
    def verify(self,doc,key=None,now=None):
        return a.verify_document(self.plan,self.job,self.command,doc,
                   self.public if key is None else key,now=self.now if now is None else now)
    def test_new_operation_only_signed_and_read_only(self):
        r=self.verify(self.signed())
        self.assertEqual(r['state'],'CURRENT_SIGNED_RECEIPT_ONLY_GRANT_VERIFIED')
        for key in ('production_mutation_allowed','schema_ddl_allowed',
                    'original_313_certified','new_original_312_verified',
                    'old_grant_reauthorized'):
            self.assertFalse(r[key])
    def test_old_or_foreign_issuer_or_apply_grants_denied(self):
        for key,value in [('contract','mad4b.company-code-release-authority.v1'),
             ('action','apply'),('operation_id','company.code.release'),
             ('job_id',self.old),('company','other-company'),
             ('environment','staging'),('site_uuid','f'*36),
             ('origin','https://other.example'),('plan_sha256','f'*64),
             ('command_sha256','f'*64),('original_job_id','f'*32),
             ('historical_grant_sha256','f'*64),
             ('historical_host_receipt_sha256','f'*64)]:
            with self.subTest(field=key),self.assertRaises(a.Denied):
                self.verify(self.signed({**self.row,key:value}))
    def test_mutation_claims_denied_even_when_signed(self):
        for key in ('production_mutation_allowed','schema_ddl_allowed',
                    'old_grant_reauthorized','original_313_certified'):
            with self.subTest(key=key),self.assertRaisesRegex(a.Denied,'CANNOT_PERMIT_MUTATIONS'):
                self.verify(self.signed({**self.row,key:True}))
    def test_expired_future_and_overlong_grants_denied(self):
        samples=[{'expires_at':(self.now-dt.timedelta(seconds=1)).isoformat()},
          {'issued_at':(self.now+dt.timedelta(seconds=1)).isoformat()},
          {'issued_at':(self.now-dt.timedelta(minutes=5)).isoformat()},
          {'expires_at':(self.now+dt.timedelta(minutes=25)).isoformat()},
          {'expires_at':(self.now+dt.timedelta(seconds=60)).isoformat()}]
        for row in samples:
            with self.subTest(row=row),self.assertRaisesRegex(a.Denied,'NOT_FRESH'):
                self.verify(self.signed({**self.row,**row}))
    def test_unsigned_modified_message_and_wrong_public_key_denied(self):
        doc=self.signed()
        for row in [dict(doc,signature='AAA='),dict(doc,nonce='f'*32)]:
            with self.assertRaisesRegex(a.Denied,'SIGNATURE_INVALID'):
                self.verify(row)
        foreign=Ed25519PrivateKey.generate().public_key().public_bytes(
            encoding=serialization.Encoding.Raw,format=serialization.PublicFormat.Raw)
        with self.assertRaisesRegex(a.Denied,'SIGNATURE_INVALID'):
            self.verify(doc,key=foreign)
    def test_extra_field_fails_signed_contract(self):
        with self.assertRaisesRegex(a.Denied,'EXACT_FIELDS_REQUIRED'):
            self.verify(self.signed({**self.row,'verified':True}))
    def test_no_original_job_replay(self):
        with self.assertRaises(a.Denied):
            a.verify_document(self.plan,self.old,self.command,
                              self.signed({**self.row,'job_id':self.old}),self.public,now=self.now)
    def test_real_registered_plan_without_new_grant_denied(self):
        # The production plan exists, but no new receipt-only grant does.
        # This read-only test does not create one or reissue the old job.
        from recovery_plan import load_exact_plan
        p=load_exact_plan('a37629fc009a97afe2fb66cef34a48ca',
          'c07011d760b1e7c59ef432a1a38c49cc445a89a674738e58e42b323119179b3f')
        with self.assertRaisesRegex(a.Denied,'RECOVERY_SIGNED_GRANT'):
            a.root_preclaim(p,'f'*32,'1'*64)

if __name__=='__main__':unittest.main()
