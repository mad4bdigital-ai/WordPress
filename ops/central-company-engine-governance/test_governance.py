import copy
import json
import os
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import governance as g

HERE=Path(__file__).parent
REG=g.load_json(HERE/"engine-registry.json")
# CI uses an isolated Core fixture; central VPS uses actual Core registry.
if os.getenv("MAD4B_GOVERNANCE_USE_FIXTURE")=="1" or not (Path(g.DEFAULT_CP)/"config/operation-registry.json").is_file():
    import tempfile
    _temp = tempfile.TemporaryDirectory(prefix="mad4b-company-governance-test-")
    _root = Path(_temp.name)
    g.DEFAULT_CP = str(_root/"wordpress")
    g.DEFAULT_ROOT = str(_root/"vps")
    Path(g.DEFAULT_CP,"config").mkdir(parents=True)
    for _e in REG["engines"]:
        for _m in _e["modules"]:
            _p = Path(g.DEFAULT_CP,"includes",_m) if _e["source"]=="wordpress" else Path(g.DEFAULT_ROOT,_m)
            _p.parent.mkdir(parents=True,exist_ok=True)
            _p.write_text("fixture-only",encoding="utf-8")
    _ops=[{"id":_id,"planner":"mad4b/test-plan","executor":"mad4b/test-apply",
           "pipeline_profile":"governed_mutation_v1"}
          for _id in ("company.plugin-sync.request","wordpress.runtime.converge")]
    _ops.extend({"id":"testing.stub.%02d"%i,"planner":"mad4b/test-plan",
                 "executor":"mad4b/test-apply","pipeline_profile":"governed_mutation_v1"} for i in range(16))
    Path(g.DEFAULT_CP,"config/operation-registry.json").write_text(json.dumps({
        "contract":"mad4b.operation-registry.v1","default_mutation_policy":"deny",
        "generic_shell":False,"arbitrary_operation_ids":False,"operations":_ops
    }),encoding="utf-8")
NATIVE=g.load_json(Path(g.DEFAULT_CP)/"config/operation-registry.json")
SITE={"company_id":"company-01","site_uuid":"site-01","origin":"https://example.test","environment":"staging"}

class ContractTests(unittest.TestCase):
    def mutated(self, fn):
        c=copy.deepcopy(REG);fn(c);return c

    def test_01_catalog_valid(self):
        self.assertEqual(len(g.checked_catalog(REG)),9)
    def test_02_core_registry_valid_and_no_mutation(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        self.assertEqual(r["core_operations_observed"],18)
        self.assertEqual(r["mapped_operations"],2)
        self.assertFalse(r["mutation_authorized"])
        self.assertFalse(r["write_performed"])
    def test_03_duplicate_engine_rejected(self):
        c=self.mutated(lambda r:r["engines"].append(copy.deepcopy(r["engines"][0])))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_04_cycle_rejected(self):
        c=self.mutated(lambda r:r["engines"][0]["depends_on"].append("company.drift_recovery"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_05_unknown_dependency_rejected(self):
        c=self.mutated(lambda r:r["engines"][0]["depends_on"].append("company.unknown"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_06_parallel_dispatcher_rejected(self):
        c=self.mutated(lambda r:r.update(core_dispatch="custom_ssh"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_07_parallel_authority_rejected(self):
        c=self.mutated(lambda r:r.update(core_authority="root_ssh"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_08_implicit_site_copy_rejected(self):
        c=self.mutated(lambda r:r.update(site_data_replication="copy_all"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_09_write_policy_weaken_rejected(self):
        c=self.mutated(lambda r:r.update(default_write_policy="allow"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_10_duplicate_native_operation_rejected(self):
        c=self.mutated(lambda r:r["engines"][0].update(native_operation="company.plugin-sync.request"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_11_path_traversal_rejected(self):
        c=self.mutated(lambda r:r["engines"][0]["modules"].append("../../wp-config.php"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_12_missing_ownership_rejected(self):
        c=self.mutated(lambda r:r["engines"][0].update(owner="company_site"))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_13_missing_protected_list_rejected(self):
        c=self.mutated(lambda r:r["engines"][0].update(protected=[]))
        with self.assertRaises(g.InvalidContract):g.checked_catalog(c)
    def test_14_unknown_core_registry_rejected(self):
        core=copy.deepcopy(NATIVE);core["generic_shell"]=True
        with self.assertRaises(g.InvalidContract):g.audit(REG,core,g.DEFAULT_CP,g.DEFAULT_ROOT)
    def test_15_unregistered_native_op_stays_blocked(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        row=next(x for x in r["engines"] if x["engine_id"]=="company.schema_evolution")
        self.assertFalse(row["core_registry_mapping"])
        self.assertIn("native_operation_unregistered",row["blockers"])
    def test_16_no_fake_executor_certification(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        self.assertTrue(all(e["executor_certified"] is False for e in r["engines"]))
    def test_17_bound_operation_not_authority(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        a=g.assess_engine(r,"company.site_convergence",SITE)
        self.assertTrue(a["core_registry_mapping"])
        self.assertFalse(a["authorizing"])
        self.assertFalse(a["execute"])
    def test_18_company_scope_required(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        with self.assertRaises(g.InvalidContract):g.assess_engine(r,"company.site_convergence",{k:v for k,v in SITE.items() if k!="company_id"})
    def test_19_cross_site_binding_not_inferred(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        a=g.assess_engine(r,"company.site_convergence",SITE)
        b=g.assess_engine(r,"company.site_convergence",dict(SITE,site_uuid="site-02"))
        self.assertNotEqual(a["site_uuid"],b["site_uuid"])
        self.assertIn("native_site_bound_identity_not_attested",b["blockers"])
    def test_20_prod_supported_but_not_authorized(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        a=g.assess_engine(r,"company.site_convergence",dict(SITE,environment="production"))
        self.assertEqual(a["environment"],"production")
        self.assertFalse(a["authorizing"])
        self.assertTrue(r["policy"]["production_higher_risk_lane"])
    def test_21_no_auto_grant_from_caller(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        with self.assertRaises(g.InvalidContract):g.assess_engine(r,"company.site_convergence",dict(SITE,authorized=True))
    def test_22_reject_unknown_engine(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        with self.assertRaises(g.InvalidContract):g.assess_engine(r,"company.fake",SITE)
    def test_23_bad_company_type_rejected(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        with self.assertRaises(g.InvalidContract):g.assess_engine(r,"company.plugin_sync",dict(SITE,company_id=123))
    def test_24_insecure_origin_rejected(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        with self.assertRaises(g.InvalidContract):g.assess_engine(r,"company.plugin_sync",dict(SITE,origin="http://example.test"))
    def test_25_missing_module_reported_not_ignored(self):
        r=g.audit(REG,NATIVE,"/path/not/existing",g.DEFAULT_ROOT)
        self.assertLess(r["source_complete_engines"],9)
    def test_26_missing_operation_mapping_fails_closed(self):
        core=copy.deepcopy(NATIVE)
        core["operations"]=[x for x in core["operations"] if x["id"]!="company.plugin-sync.request"]
        r=g.audit(REG,core,g.DEFAULT_CP,g.DEFAULT_ROOT)
        row=next(x for x in r["engines"] if x["engine_id"]=="company.plugin_sync")
        self.assertFalse(row["core_registry_mapping"])
        self.assertIn("native_operation_contract_unverified",row["blockers"])
    def test_27_tamper_report_not_authority(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        a=g.assess_engine(r,"company.plugin_sync",SITE)
        self.assertIn("fresh_authority_not_obtained",a["blockers"])
        self.assertIn("independent_restore_and_readback_not_certified",a["blockers"])
    def test_28_no_external_operation_trust(self):
        core=copy.deepcopy(NATIVE)
        core["operations"][0]["pipeline_profile"]="bypass"
        r=g.audit(REG,core,g.DEFAULT_CP,g.DEFAULT_ROOT)
        self.assertFalse(r["mutation_authorized"])
    def test_29_contract_digest_reproducible(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        h=r.pop("report_sha256")
        self.assertEqual(h,g.digest(r))
    def test_30_inventory_no_sites_mutated(self):
        r=g.audit(REG,NATIVE,g.DEFAULT_CP,g.DEFAULT_ROOT)
        self.assertTrue(r["policy"]["central_governance_never_dispatches"])
        self.assertFalse(r["write_performed"])

if __name__=="__main__":
    unittest.main(verbosity=2)
