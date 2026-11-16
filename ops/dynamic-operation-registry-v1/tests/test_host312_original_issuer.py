"""Test-only Original312 issuer keys. No live keys or grants generated."""
import base64
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PrivateFormat,PublicFormat,NoEncryption

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry
import host_admission as admission
import host312_original_issuer as issuer
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'native-source-publisher-v1'))
import native_source_publisher as publisher


class SignedHost312OriginalTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='mad4b-test-host312-')
        self.addCleanup(self.temporary.cleanup)
        self.path=Path(self.temporary.name)/'original312.key'
        self.signer=Ed25519PrivateKey.generate()
        self.path.write_bytes(self.signer.private_bytes(Encoding.Raw,PrivateFormat.Raw,NoEncryption()))
        self.path.chmod(0o600)
        self.pub=self.signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.now=1794700000
        self.policy={'enabled':True,
            'master_approval_public_key_b64':base64.b64encode(b'C'*32).decode(),
            'broker_public_key_b64':base64.b64encode(b'D'*32).decode()}
        self.plan={'contract':'mad4b.native-host-source-readonly-plan.v1',
           'owner_id':'original-native-broker','scope':'host:srv2030908',
           'owner_catalog_sha256':'a'*64,'git_commit':'f'*40}
        self.plan['plan_sha256']=registry.sha(registry.canonical(self.plan))
        operation='host.source.release'
        digest='b'*64
        self.index=SimpleNamespace(
            resolve=lambda op:{'plan_contract':self.plan['contract'],
                'source_family':'mad4b.native-host-source-release.v1',
                'target_scope':'host','resource_kind':'host_root_file',
                'job_type':'signed_vps_host_source','command':{'format':'host.source.ref.v1'}},
            command_sha256=lambda op,plan:digest,
            host_resource_312_public_key=self.pub,
            independent_verifier_public_key=b'E'*32)
        self.job={
           'contract':'mad4b.central-vps-broker.v1','job_id':'c'*32,
           'owner_id':self.plan['owner_id'],
           'owner_catalog_sha256':self.plan['owner_catalog_sha256'],
           'plan_sha256':self.plan['plan_sha256'],
           'host_id':'srv2030908','broker_host_id':'srv2030908',
           'target_host_id':'srv2030908','target_scope':'host',
           'operation':operation,'job_type':'signed_vps_host_source',
           'command_format':'host.source.ref.v1','command_sha256':digest,
           'state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
           'executed':False,'claimed_epoch':self.now-10,
           'expires_epoch':self.now+600}
        self.pending={'contract':'mad4b.central-vps-broker.v1',
          'state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
          'host_id':'srv2030908','production_mutation_performed':False,
          'job_id':self.job['job_id'],'claimed_epoch':self.job['claimed_epoch'],
          'nonce':'d'*32}
        self.path_patch=mock.patch.object(issuer,'HOST_SIGNER',self.path)
        self.path_patch.start();self.addCleanup(self.path_patch.stop)
        self.policy_patch=mock.patch.object(publisher,'checked_policy',return_value=self.policy)
        self.policy_patch.start();self.addCleanup(self.policy_patch.stop)

    def issue(self):
        return issuer.firstparty_host312_proof(self.index,self.plan,self.job,
              self.pending,now=self.now)

    def test_genuine_independent_firstparty_host312_proof_has_no_release_authority(self):
        proof=self.issue()
        self.assertEqual(proof['nonce'],self.pending['nonce'])
        self.assertEqual(proof['operation'],self.job['operation'])
        candidate=admission.claimed_host_job(self.index,self.job['operation'],
                 self.plan,self.job,self.pending,now=self.now)
        result=admission.verify_first_party_host312(self.index,candidate,proof,now=self.now)
        self.assertTrue(result['original_312_verified'])
        self.assertFalse(result['execution_authorized'])
        self.assertFalse(result['original_313_signed_receipt'])
        self.assertNotIn('master_approval',proof)
        self.assertNotIn('broker_grant',proof)

    def test_absent_root_signer_is_denied(self):
        self.path.unlink()
        with self.assertRaisesRegex(registry.RegistryDenied,'ISSUER_NOT_ENROLLED'):
            self.issue()

    def test_original_root_public_key_mismatch_denied(self):
        self.index.host_resource_312_public_key=b'Z'*32
        with self.assertRaisesRegex(registry.RegistryDenied,'KEY_NOT_ORIGINAL_ROOT_PIN'):
            self.issue()

    def test_original313_key_reuse_denied(self):
        self.index.independent_verifier_public_key=self.pub
        with self.assertRaisesRegex(registry.RegistryDenied,'KEY_NOT_ORIGINAL_ROOT_PIN'):
            self.issue()

    def test_publisher_master_or_broker_signer_collision_denied(self):
        self.policy['broker_public_key_b64']=base64.b64encode(self.pub).decode()
        with self.assertRaisesRegex(registry.RegistryDenied,'SIGNERS_COLLIDE'):
            self.issue()

    def test_signed_claim_nonce_invalid_denied(self):
        self.pending['nonce']='invalid'
        with self.assertRaisesRegex(registry.RegistryDenied,'ORIGINAL_SIGNED_CLAIM_NOT_BOUND'):
            self.issue()

    def test_wpcli_job_cannot_receive_host312_proof(self):
        self.job['target_scope']='wordpress'
        with self.assertRaisesRegex(registry.RegistryDenied,'ORIGINAL_SIGNED_CLAIM_NOT_BOUND'):
            self.issue()

    def test_expired_job_cannot_issue_host312(self):
        self.job['expires_epoch']=self.now+10
        with self.assertRaisesRegex(registry.RegistryDenied,'CLAIM_NOT_CURRENT'):
            self.issue()

if __name__=='__main__':unittest.main()
