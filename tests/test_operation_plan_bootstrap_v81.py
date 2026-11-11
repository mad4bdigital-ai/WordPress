"""Isolated plan-contract compatibility. Never invokes installed registry."""
import hashlib
import importlib.util
import pathlib
import sys
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,ROOT/path)
    module=importlib.util.module_from_spec(spec)
    sys.modules[name]=module
    spec.loader.exec_module(module)
    return module
m=load('deploy/host-authority/v81/operation_plan_contract.py','plan_v81')
old=load('deploy/host-authority/v79/host-authority-v2/operation_contract.py','plan_v79')

class Plan(unittest.TestCase):
    def setUp(self):
        self.op=m.ISSUE
        self.p=dict(operation_id=m.ISSUE,contract=m.CONTRACTS[m.ISSUE],
          company='all-royal-egypt',environment='staging',
          site_uuid='0384b6ce-4004-4b4e-b701-7d77cb6f6ff0',
          origin='https://staging.allroyalegypt.com',target_host_id='hosting-2',
          ssh_principal='u948953904',grant_revision=0,anchor_revision=0,
          authority_mode='bootstrap',enrollment_id='6'*32,
          enrollment_nonce='7'*32,requested_anchor_revision=1,
          policy_sha256='1'*64,profile_digest='2'*64,
          host_key_sha256='3'*64,ssh_root_sha256='4'*64,
          registry_sha256='5'*64)
        self.sign()
    def sign(self):
        self.p.pop('plan_sha256',None)
        self.p['plan_sha256']=hashlib.sha256(m.canonical(self.p)).hexdigest()
    def check(self):
        return m.verify_plan(self.p,self.op)
    def denied(self):
        with self.assertRaises(m.PlanDenied):self.check()
    def test_bootstrap_first_revision(self):
        self.assertTrue(self.check()['first_anchor_bootstrap'])
        self.assertFalse(self.check()['execution_authorized'])
    def test_old_v79_rejects_bootstrap(self):
        with self.assertRaises(old.Denied):old.verify_plan(self.p,self.op)
        self.assertTrue(self.check()['first_anchor_bootstrap'])
    def test_v79_plan_contract_string_unchanged(self):
        self.assertEqual(m.CONTRACTS,old.CONTRACTS)
    def test_operational_issue_unchanged(self):
        for k in ('authority_mode','enrollment_id','enrollment_nonce','requested_anchor_revision'):
            self.p.pop(k)
        self.p.update(grant_revision=1,anchor_revision=1)
        self.sign()
        self.assertFalse(self.check()['first_anchor_bootstrap'])
        self.assertIs(old.verify_plan(self.p,self.op)['write_authorized'],False)
    def test_operational_revoke_unchanged(self):
        for k in ('authority_mode','enrollment_id','enrollment_nonce','requested_anchor_revision'):
            self.p.pop(k)
        self.op=m.REVOKE
        self.p.update(operation_id=m.REVOKE,contract=m.CONTRACTS[m.REVOKE],
                      grant_revision=2,anchor_revision=3,target_anchor_nonce='8'*32)
        self.sign()
        self.assertFalse(self.check()['execution_authorized'])
        self.assertFalse(old.verify_plan(self.p,self.op)['write_authorized'])
    def test_bootstrap_wrong_op(self):
        self.op=m.REVOKE
        self.p.update(operation_id=m.REVOKE,contract=m.CONTRACTS[m.REVOKE])
        self.sign();self.denied()
    def test_bootstrap_missing_issuer_enrollment(self):
        del self.p['enrollment_id'];self.sign();self.denied()
    def test_bootstrap_missing_nonce(self):
        del self.p['enrollment_nonce'];self.sign();self.denied()
    def test_bootstrap_requires_zero_prior_grant(self):
        self.p['grant_revision']=1;self.sign();self.denied()
    def test_bootstrap_requires_zero_prior_anchor(self):
        self.p['anchor_revision']=1;self.sign();self.denied()
    def test_bootstrap_no_revision_skip(self):
        self.p['requested_anchor_revision']=2;self.sign();self.denied()
    def test_revoke_nonce_not_accepted_on_bootstrap(self):
        self.p['target_anchor_nonce']='9'*32;self.sign();self.denied()
    def test_unknown_mode(self):
        self.p['authority_mode']='old';self.sign();self.denied()
    def test_plan_tamper(self):
        self.p['origin']='https://evil.example';self.denied()
    def test_digest_wrong(self):
        self.p['policy_sha256']='g'*64;self.sign();self.denied()
if __name__=='__main__':unittest.main(verbosity=2)
