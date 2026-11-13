import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("runtime_bluegreen_test",
                                              ROOT/"runtime_controller.py")
code = importlib.util.module_from_spec(spec)
spec.loader.exec_module(code)
model = code.runtime


class RuntimeControllerTest(unittest.TestCase):
    def setUp(self):
        self.plan = {
            "contract": model.CONTRACT,
            "site":{"company":"wayfatours","environment":"production",
                    "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
                    "origin":"https://wayfatours.com"},
            "base_image_id":"sha256:"+"a"*64,
            "layers":[],
            "source_publisher_authorized":False,
            "original312_signed":False,
            "original313_finalized":False,
            "cutover_verified":False}
        self.plan["runtime_plan_sha256"] = model.sha(model.canonical(self.plan))
        self.roles={x:"provider."+x.replace("_",".") for x in code.REQUIRED_ROLES}
        self.enrolled={x:"a"*64 for x in self.roles.values()}

    def test_provider_role_scopes_are_dynamic_and_pinned(self):
        doc=code.blueprint(self.plan,active_image_id="sha256:"+"a"*64,
             candidate_image_id="sha256:"+"b"*64,
             provider_roles=self.roles,installed_manifest_digest=self.enrolled)
        self.assertEqual(len(doc["providers"]),6)
        extended=dict(self.roles, marketing_experience="provider.experience.check")
        enrolled=dict(self.enrolled, **{"provider.experience.check":"b"*64})
        expanded=code.blueprint(self.plan,active_image_id="sha256:"+"a"*64,
             candidate_image_id="sha256:"+"b"*64,
             provider_roles=extended,installed_manifest_digest=enrolled)
        self.assertEqual(len(expanded["providers"]),7)
        self.assertEqual(doc["providers"]["traffic_switch"]["operation_id"],
                         "provider.traffic.switch")
        self.assertEqual(doc["state"],"NOT_AUTHORIZED_FOR_ACTIVATION")
        self.assertTrue(doc["original_signed_312_required"])

    def test_missing_registry_or_role_fails_before_activating(self):
        with self.assertRaisesRegex(model.RuntimeHold,
                                    "PROVIDER_NOT_REGISTRY_PINNED"):
            code.blueprint(self.plan,active_image_id="sha256:"+"a"*64,
                 candidate_image_id="sha256:"+"b"*64,
                 provider_roles=self.roles,installed_manifest_digest={})

    def test_image_provenance_passes_but_original_gates_still_block(self):
        view={"Id":"sha256:"+"b"*64,
              "Config":{"Labels":{
                  "org.mad4b.runtime.plan":self.plan["runtime_plan_sha256"],
                  "org.mad4b.runtime.site":self.plan["site"]["site_uuid"]}}}
        class Registry:
            def resolve(self, op):return {"operation_id":op}
            def manifest_sha256(self, op):return "a"*64
        blueprint=code.blueprint(self.plan,active_image_id="sha256:"+"a"*64,
             candidate_image_id="sha256:"+"b"*64,
             provider_roles=self.roles,installed_manifest_digest=self.enrolled)
        state=code.audit(runtime_plan=self.plan, image_description=view,
                   registry=Registry(),original_source_policy=True,
                   role_bindings=self.roles,approved_blueprint=blueprint,
                   running_image_id="sha256:"+"a"*64)
        self.assertNotIn("BLUEGREEN_PLAN_NOT_BYTE_BOUND_TO_RUNTIME",
                         state["blockers"])
        self.assertNotIn("ORIGINAL_PROVIDER_NOT_ENROLLED:publisher",
                         state["blockers"])
        self.assertEqual(state["state"],"HOLD_ORIGINAL_NATIVE_RUNTIME_ACTIVATION")
        self.assertIn("ORIGINAL313_INDEPENDENT_FINALIZATION_NOT_ATTESTED",
                      state["blockers"])
        self.assertFalse(state["traffic_switched"])

    def test_bluegreen_source_manifest_drift_is_not_ignored(self):
        class Registry:
            def resolve(self,op):return {"operation_id":op}
            def manifest_sha256(self,op):return "f"*64
        blue=code.blueprint(self.plan,active_image_id="sha256:"+"a"*64,
             candidate_image_id="sha256:"+"b"*64,
             provider_roles=self.roles,installed_manifest_digest=self.enrolled)
        view={"Id":"sha256:"+"b"*64,
              "Config":{"Labels":{"org.mad4b.runtime.plan":
                 self.plan["runtime_plan_sha256"],
                 "org.mad4b.runtime.site":self.plan["site"]["site_uuid"]}}}
        state=code.audit(runtime_plan=self.plan,image_description=view,
                  registry=Registry(),original_source_policy=True,
                  role_bindings=self.roles,approved_blueprint=blue,
                  running_image_id="sha256:"+"a"*64)
        self.assertIn("ORIGINAL_PROVIDER_NOT_ENROLLED:publisher",
                      state["blockers"])

    def test_incomplete_or_unbound_whole_site_topology_blocks_activation(self):
        view={"Id":"sha256:"+"b"*64,
              "Config":{"Labels":{"org.mad4b.runtime.plan":
                 self.plan["runtime_plan_sha256"],
                 "org.mad4b.runtime.site":self.plan["site"]["site_uuid"]}}}
        missing=code.audit(runtime_plan=self.plan,image_description=view)
        self.assertIn("FULL_SITE_TOPOLOGY_READBACK_REQUIRED",missing["blockers"])
        wrong={"contract":"mad4b.immutable-runtime.site-topology-audit.v1",
              "runtime_plan_sha256":"0"*64,"blockers":[]}
        unbound=code.audit(runtime_plan=self.plan,image_description=view,
                           topology_readback=wrong)
        self.assertIn("FULL_SITE_TOPOLOGY_READBACK_REQUIRED",unbound["blockers"])
        real={"contract":"mad4b.immutable-runtime.site-topology-audit.v1",
             "runtime_plan_sha256":self.plan["runtime_plan_sha256"],
             "blockers":["FULL_INSTALLED_PLUGIN_CODE_COVERAGE_MISSING"]}
        incomplete=code.audit(runtime_plan=self.plan,image_description=view,
                              topology_readback=real)
        self.assertIn("FULL_SITE_CODE_AND_VOLUME_TOPOLOGY_INCOMPLETE",
                      incomplete["blockers"])

    def test_missing_provider_and_wrong_image_label_are_detected(self):
        view={"Id":"sha256:"+"b"*64,
              "Config":{"Labels":{"org.mad4b.runtime.plan":"z"*64}}}
        state=code.audit(runtime_plan=self.plan,image_description=view)
        self.assertIn("OCI_PLAN_LABEL_MISMATCH",state["blockers"])
        self.assertIn("ORIGINAL_PROVIDER_ROLE_REGISTRY_MISSING",state["blockers"])

    def test_faults_before_and_after_switch_restore_a_in_simulation(self):
        for phase in ("build","shadow","health","drain","switch","finalize"):
            with self.subTest(phase=phase):
                row=code.simulate_failure(fail_after_phase=phase)
                self.assertEqual(row["active_slot"],"A")
                self.assertEqual(row["rolled_back_to"],"A")
                self.assertFalse(row["real_traffic_changed"])
        self.assertEqual(code.simulate_failure()["active_slot"],"B")


if __name__=="__main__":
    unittest.main()
