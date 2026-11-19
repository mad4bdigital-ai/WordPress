#!/usr/bin/env python3
"""Hermetic host-v111 Root plan regression: no WordPress, SSH or real Root writes."""
import importlib.util
import pathlib
import tempfile
import unittest

HERE=pathlib.Path(__file__).resolve().parents[2]
SOURCE=HERE/"ops/host-anchor-v111/prepare_first_root_plan.py"
spec=importlib.util.spec_from_file_location("host_v111_plan",SOURCE)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

def facts():
    return {
      "operation_id":m.OP,
      "contract":"mad4b.host-authority.anchor-issue.plan.v2",
      "company":m.COMPANY,
      "environment":"staging",
      "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
      "origin":"https://staging.allroyalegypt.com",
      "target_host_id":"hosting-2",
      "ssh_principal":"u948953904",
      "policy_sha256":"1"*64,
      "profile_digest":"2"*64,
      "host_key_sha256":"3"*64,
      "ssh_root_sha256":"4"*64,
      "registry_sha256":"5"*64,
      "authority_mode":"bootstrap",
      "grant_revision":0,
      "anchor_revision":0,
      "requested_anchor_revision":1,
    }

class TestPreparedRootPlan(unittest.TestCase):
    def setUp(self):
        self.plan=m.make_plan(facts(),"a"*32,"b"*32)
        self.v=m.module_from_pinned(m.PLAN_MODULE,m.PLAN_MODULE_SHA,"v111testverifier")
    def test_real_first_plan_contract(self):
        r=self.v.verify_plan(self.plan,m.OP)
        self.assertTrue(r["first_anchor_bootstrap"])
        self.assertFalse(r["execution_authorized"])
    def test_change_field_invalidates_digest(self):
        p=dict(self.plan);p["target_host_id"]="hosting-3"
        with self.assertRaises(Exception):self.v.verify_plan(p,m.OP)
    def test_no_placeholder_enrollment(self):
        for pair in (("","b"*32),("a"*31,"b"*32),("a"*32,"a"*32)):
            with self.assertRaises(m.Held):m.make_plan(facts(),*pair)
    def test_requires_staging_zero_revision(self):
        p=dict(self.plan);p["grant_revision"]=3
        p["plan_sha256"]=m.digest(self.v.canonical({k:v for k,v in p.items() if k!="plan_sha256"}))
        with self.assertRaises(Exception):self.v.verify_plan(p,m.OP)
    def test_write_once_root_only_idempotent(self):
        with tempfile.TemporaryDirectory() as td:
            target=pathlib.Path(td)/"private"/"first-plan.json"
            a,created=m.write_once(self.plan,target)
            self.assertTrue(created)
            self.assertEqual(target.stat().st_mode&0o777,0o600)
            self.assertEqual(target.parent.stat().st_mode&0o777,0o700)
            repeated=m.make_plan(facts(),"c"*32,"d"*32)
            b,created=m.write_once(repeated,target)
            self.assertFalse(created)
            self.assertEqual(a["plan_sha256"],b["plan_sha256"])
    def test_write_once_refuses_scope_drift(self):
        with tempfile.TemporaryDirectory() as td:
            target=pathlib.Path(td)/"private"/"first-plan.json"
            m.write_once(self.plan,target)
            modified=facts();modified["policy_sha256"]="f"*64
            other=m.make_plan(modified,"c"*32,"d"*32)
            with self.assertRaises(m.Held):m.write_once(other,target)
    def test_write_once_refuses_symlink(self):
        with tempfile.TemporaryDirectory() as td:
            base=pathlib.Path(td)
            link=base/"linked.json"
            link.symlink_to(base/"other.json")
            with self.assertRaises(m.Held):m.write_once(self.plan,link)

if __name__=="__main__":
    unittest.main(verbosity=2)
