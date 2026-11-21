#!/usr/bin/env python3
import importlib.util
import hashlib
import json
import pathlib
import sys
import unittest
from copy import deepcopy

f=pathlib.Path(__file__).resolve().parents[1]/"deploy/host-authority/v81/original306_admission_router.py"
sys.path.insert(0,str(f.parent))
spec=importlib.util.spec_from_file_location("route81",f)
m=importlib.util.module_from_spec(spec);sys.modules[spec.name]=m;spec.loader.exec_module(m)

class Test(unittest.TestCase):
    def setUp(self):
        self.op="company.host-authority.anchor.issue"
        self.plan=dict(operation_id=self.op,
            plan_sha256="f"*64,authority_mode="operational",
            grant_revision=2,anchor_revision=3,
            company="all-royal-egypt",environment="production",
            site_uuid="0384b6ce-4004-4b4e-b701-7d77cb6f6ff0",
            origin="https://www.allroyalegypt.com",
            target_host_id="hosting-2",ssh_principal="u948953904",
            contract="mad4b.host-authority.anchor-issue.plan.v2",
            policy_sha256="1"*64,profile_digest="2"*64,
            host_key_sha256="3"*64,ssh_root_sha256="4"*64,
            registry_sha256="5"*64)
        self.job=dict(
            job_id="a"*32,operation_id=self.op,
            company=self.plan["company"],environment=self.plan["environment"],
            site_uuid=self.plan["site_uuid"],origin=self.plan["origin"],
            command_sha256="b"*64,
            job_type="signed_remote_hostinger_wpcli_v2",
            principal_type="signed_host_principal_v2",
            state="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
            executed=False,operation="company.remote.wpcli.full",
            broker_host_id="srv2030908",
            target_host_id="hosting-2",target_ssh_principal="u948953904",
            target_scope="wordpress",command_format="wpcli.argv.json.v1")
    def signplan(self):
        self.plan["plan_sha256"]=hashlib.sha256(
            json.dumps({k:v for k,v in self.plan.items()
                        if k not in ("plan_sha256","changed_count")},
                       sort_keys=True,separators=(",",":"),ensure_ascii=False
                       ).encode()).hexdigest()
    def route(self,registered=True):
        self.signplan()
        return m.classify(self.job,self.op,self.plan,"b"*64,
                          registry_has_operation=registered,
                          original_broker_host_id="srv2030908")
    def denied(self):
        with self.assertRaises(m.RouteDenied):self.route()
    def test_v2_operational(self):
        o=self.route()
        self.assertEqual(o["route"],"original_host_v2_signed_operation")
        self.assertFalse(o["execution_authorized"])
    def test_first_anchor_bootstrap(self):
        self.plan.update(authority_mode="bootstrap",
                         anchor_revision=0,grant_revision=0,
                         enrollment_id="e"*32,enrollment_nonce="d"*32,
                         requested_anchor_revision=1)
        self.assertEqual(self.route()["route"],
                         "original_host_v2_first_anchor_bootstrap")
    def test_bootstrap_revoke_rejected(self):
        self.plan.update(authority_mode="bootstrap",
                         anchor_revision=0,grant_revision=0,
                         enrollment_id="e"*32,enrollment_nonce="d"*32,
                         requested_anchor_revision=1,
                         operation_id="company.host-authority.anchor.revoke")
        self.op="company.host-authority.anchor.revoke"
        self.job["operation_id"]=self.op
        self.denied()
    def test_bootstrap_without_independent_enrollment_id(self):
        self.plan.update(authority_mode="bootstrap",
                         anchor_revision=0,grant_revision=0)
        self.denied()
    def test_v2_no_v1_fallback(self):
        self.job["job_type"]=m.JOB_V1;self.denied()
    def test_wrong_principal(self):
        self.job["principal_type"]="wordpress_actor";self.denied()
    def test_unsupported_job(self):
        self.job["job_type"]="anything";self.denied()
    def test_job_unregistered(self):
        with self.assertRaises(m.RouteDenied):self.route(False)
    def test_command_drift(self):
        self.job["command_sha256"]="0"*64;self.denied()
    def test_target_host_drift(self):
        self.job["target_host_id"]="hosting-3";self.denied()
    def test_scope_drift(self):
        self.job["environment"]="staging";self.denied()
    def test_cross_host_broker_drift(self):
        self.job["broker_host_id"]="another-broker";self.denied()
    def test_reservation_state(self):
        self.job["state"]="EXECUTED";self.denied()
    def test_v1_legacy_untouched(self):
        self.op="company.code.release"
        self.job.update(job_type=m.JOB_V1,
                        principal_type="wordpress_actor",
                        operation_id=self.op)
        self.plan["operation_id"]=self.op
        self.plan["contract"]="mad4b.host-authority.anchor-issue.plan.v2"
        v=self.route()
        self.assertEqual(v["route"],"original_legacy_v1")
        self.assertFalse(v["original313_certified"])
    def test_v1_host_operation_never_fallback(self):
        self.job["job_type"]=m.JOB_V1;self.denied()
if __name__=="__main__":unittest.main(verbosity=2)
