#!/usr/bin/env python3
"""Pure fixture test: pinned catalog bridged to exact claimed company stage."""
import copy
import datetime as dt
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SOURCE=Path(__file__).resolve().parents[1]/"executor/company_stage_adapter.py"
spec=importlib.util.spec_from_file_location("company_stage_adapter",SOURCE)
adapter=importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)

class AdapterTest(unittest.TestCase):
    def setUp(self):
        self.t=tempfile.TemporaryDirectory(prefix="mad4b-adapter-fixture-")
        self.root=Path(self.t.name)
        self.policy={
            "company":"wayfatours","site_uuid":"b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b",
            "canonical_origin":"https://wayfatours.mad4b.com",
            "executor_id":"enrolled-vps-test","workspace_root":str(self.root),
        }
        self.catalog={
            "contract":"mad4b.portable-mu-artifact-catalog.v1",
            "company_code":"wayfatours","site_uuid":self.policy["site_uuid"],
            "origin":self.policy["canonical_origin"],
            "action":"company.mu-providers.converge",
            "artifacts":[{"path":"product-navigation-tags.php","sha256":"a"*64}],
        }
        self.policy["catalog_sha256"]=adapter.hashlib.sha256(adapter.canonical(self.catalog)).hexdigest()
        p={
            "contract":"mad4b.company-stage-job.v1","company_code":"wayfatours",
            "site_uuid":self.policy["site_uuid"],"origin":self.policy["canonical_origin"],
            "mode":"repair_existing","stage":"mu_providers",
            "action":"company.mu-providers.converge",
            "plan_sha256":"1"*64,"stage_revision":"2"*64,"run_id":"3"*64,
            "preserve_existing":True,"mutation_authorized":False,
        }
        self.job={"operation_id":"company.stage.execute","status":"claimed",
            "lease_expires_at_epoch":int(dt.datetime.now(dt.timezone.utc).timestamp())+600,
            "executor_id":"enrolled-vps-test","payload":p}
    def tearDown(self):
        self.t.cleanup()
    def test_prepared_manifest_is_exact_and_idempotent(self):
        obj=adapter.build(self.job,self.policy,self.catalog)
        self.assertEqual(obj["artifacts"],self.catalog["artifacts"])
        self.assertNotIn("mutation_authorized",obj)
        self.assertEqual(obj["run_id"],"3"*64)
    def test_no_arbitrary_shell_or_payload_source(self):
        job=copy.deepcopy(self.job)
        job["payload"]["command"]="docker exec ..."
        with self.assertRaisesRegex(ValueError,"QUEUE_PAYLOAD_SCHEMA_INVALID"):
            adapter.build(job,self.policy,self.catalog)
    def test_foreign_company_rejected(self):
        job=copy.deepcopy(self.job);job["payload"]["site_uuid"]="4"*64
        with self.assertRaisesRegex(ValueError,"TENANT_SCOPE_MISMATCH"):
            adapter.build(job,self.policy,self.catalog)
    def test_expired_claim_rejected(self):
        job=copy.deepcopy(self.job);job["lease_expires_at_epoch"]=1
        with self.assertRaisesRegex(ValueError,"CLAIM_EXPIRED_OR_CANCELLED"):
            adapter.build(job,self.policy,self.catalog)
    def test_tampered_catalog_rejected(self):
        catalog=copy.deepcopy(self.catalog)
        catalog["artifacts"][0]["sha256"]="f"*64
        with self.assertRaisesRegex(ValueError,"CATALOG_SHA256_NOT_ENROLLED"):
            adapter.build(self.job,self.policy,catalog)
    def test_wrong_executor_rejected(self):
        job=copy.deepcopy(self.job);job["executor_id"]="another"
        with self.assertRaisesRegex(ValueError,"EXECUTOR_BINDING_MISMATCH"):
            adapter.build(job,self.policy,self.catalog)

if __name__=="__main__":
    unittest.main(verbosity=2)
