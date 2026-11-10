from pathlib import Path
import base64
import importlib.util
import json
import os
import sys
import tempfile
import unittest
from unittest import mock

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/'integrations/original_dynamic_313_resumer_rollback_filter.py'
sys.path.insert(0,'/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/scripts')
spec=importlib.util.spec_from_file_location('mad4b_original_313_rollback_source_test',SOURCE)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class Original313SignedRollbackFilter(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        base=Path(self.tmp.name)
        self.grants=base/'grants'
        self.receipts=base/'receipts'
        self.grants.mkdir(mode=0o700)
        self.receipts.mkdir(mode=0o700)
        os.chmod(self.grants,0o700)
        os.chmod(self.receipts,0o700)
        self.key=Ed25519PrivateKey.generate()
        self.policy={'broker_public_key_b64':base64.b64encode(
            self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)).decode(),
            'receipt_dir':str(self.receipts)}
        self.job='a'*32
        self.plan={
            'plan_sha256':'b'*64,
            'company':'wayfatours',
            'environment':'production',
            'site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
            'origin':'https://wayfatours.com',
            'git_commit':'c'*40,
            'files':[{'target':'wp-content/mu-plugins/test.php'}]
        }
        self.grant={'contract':m.release.AUTH_CONTRACT,'issuer':'mad4b-company-broker',
                    'action':'apply','original_job_id':self.job,
                    'nonce':'d'*32,'company':self.plan['company'],
                    'environment':self.plan['environment'],
                    'site_uuid':self.plan['site_uuid'],
                    'origin':self.plan['origin'],
                    'plan_sha256':self.plan['plan_sha256']}
        self.receipt={'contract':m.release.RECEIPT_CONTRACT,'state':'ROLLED_BACK',
                      'nonce':self.grant['nonce'],'plan_sha256':self.plan['plan_sha256'],
                      'company':self.plan['company'],'environment':self.plan['environment'],
                      'site_uuid':self.plan['site_uuid'],'git_commit':self.plan['git_commit'],
                      'files':[f['target'] for f in self.plan['files']],
                      'broker_grant_signature_verified':True,
                      'original_313_receipt':False,'failed_reason':'COPY_COVERAGE_RUNTIME_ACCEPTANCE_NOT_PROVEN'}
        self.persist()
        p=mock.patch.object(m,'GRANT_STORE',self.grants)
        p.start()
        self.addCleanup(p.stop)
        p=mock.patch.object(m.release,'trusted_config',return_value=self.policy)
        p.start()
        self.addCleanup(p.stop)

    def write(self,path,row):
        path.write_text(json.dumps(row))
        os.chmod(path,0o600)

    def persist(self):
        self.grant['signature']=base64.b64encode(
            self.key.sign(m.release.grant_message(self.grant))).decode()
        self.write(self.grants/(self.job+'.json'),self.grant)
        self.write(self.receipts/(self.grant['nonce']+'.json'),self.receipt)

    def test_first_party_signed_rollback_is_skipped(self):
        self.assertTrue(m.original_signed_rollback_verified(self.job,self.plan))

    def test_successful_apply_cannot_be_suppressed(self):
        self.receipt['state']='APPLIED_VERIFIED'
        self.persist()
        self.assertFalse(m.original_signed_rollback_verified(self.job,self.plan))

    def test_unknown_or_uncertain_receipt_remains_visible(self):
        self.receipt['state']='PENDING'
        self.persist()
        self.assertFalse(m.original_signed_rollback_verified(self.job,self.plan))

    def test_forged_rollback_receipt_not_suppressed(self):
        self.receipt['original_313_receipt']=True
        self.persist()
        self.assertFalse(m.original_signed_rollback_verified(self.job,self.plan))

    def test_wrong_receipt_scope_not_suppressed(self):
        self.receipt['site_uuid']='f'*36
        self.persist()
        self.assertFalse(m.original_signed_rollback_verified(self.job,self.plan))

    def test_tampered_grant_signature_not_suppressed(self):
        self.persist()
        g=json.loads((self.grants/(self.job+'.json')).read_text())
        g['signature']='AAAAAAAAAAAA'
        self.write(self.grants/(self.job+'.json'),g)
        self.assertFalse(m.original_signed_rollback_verified(self.job,self.plan))

    def test_missing_first_party_receipt_not_suppressed(self):
        (self.receipts/(self.grant['nonce']+'.json')).unlink()
        self.assertFalse(m.original_signed_rollback_verified(self.job,self.plan))

    def test_non_root_private_receipt_not_suppressed(self):
        os.chmod(self.receipts/(self.grant['nonce']+'.json'),0o644)
        self.assertFalse(m.original_signed_rollback_verified(self.job,self.plan))

if __name__=='__main__':unittest.main()
