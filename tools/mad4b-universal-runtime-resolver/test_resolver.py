"""MAD4B universal runtime resolver isolated contract tests."""
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch

import resolver as r

SITE=r.SITE
SCOPE={"site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
       "canonical_origin":"https://staging.allroyalegypt.com",
       "operations":["remote_exec:site_root","remote_file_transfer:verified_zip"],
       "transport_binding":"hosting-2:staging",
       "identity_adapter":"central_scoped_v4",
       "agent_adapter":"are_signed_v4",
       "package":{"filename":"mad4b-are-staging-p256-v4.zip",
                  "sha256":"db544322956f2b50b6560b1ebfa0a80a98bd31e147d96df691fc185582dff863"},
       "workflow_operation":"signed_site_agent_install"}
PROD="all-royal-egypt:production"
INVENTORY={
    SITE:{"environment":"staging","site_uuid":SCOPE["site_uuid"],
          "canonical_origin":SCOPE["canonical_origin"]},
    PROD:{"environment":"production","site_uuid":None,
          "canonical_origin":"https://www.allroyalegypt.com"},
}
POLICY={"contract":r.CONTRACT,"enabled":True,"default_production_mutation":False,
        "scopes":{SITE:SCOPE}}

class ResolverTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.con=r.db_open(Path(self.tmp.name)/"state.sqlite3")
        self.inventory=patch.object(r,"inventory",return_value=INVENTORY)
        self.inventory.start()
    def tearDown(self):
        self.inventory.stop()
        self.con.close()
        self.tmp.cleanup()
    def event(self,target=SITE,trigger="manual",key=None):
        return r.enqueue(self.con,target,trigger,"tests",key or "test-event-"+__import__("uuid").uuid4().hex)
    def test_event_idempotency(self):
        a=r.enqueue(self.con,SITE,"intake","tests","repeatable-event-key-42")
        b=r.enqueue(self.con,SITE,"intake","tests","repeatable-event-key-42")
        self.assertEqual(a["event_id"],b["event_id"])
        self.assertTrue(b["idempotent"])
    def test_unregistered_target_denied(self):
        with self.assertRaises(ValueError):
            self.con.execute("BEGIN")
            r.enqueue(self.con,"unowned-site:production","intake","tests","valid-idempotency-000")
        self.con.rollback()
    def test_unknown_event_source_denied(self):
        with self.assertRaises(ValueError):
            r.enqueue(self.con,SITE,"intake",";rm -rf /","valid-idempotency-000")
    def test_authority_not_inferred_from_identity(self):
        bad={**SCOPE,"operations":["unrestricted_shell"]}
        d=r.inspect_authority(SITE,bad)
        self.assertEqual(d["state"],"review_required")
        self.assertEqual(d["reason"],"operation_policy_conflict")
    def test_production_stays_nonmutating(self):
        event=self.event(PROD)
        with patch.dict(r.ADAPTERS,{domain: lambda t,s: (_ for _ in ()).throw(
                    AssertionError("must not call live adapter")) for domain in r.DOMAINS}):
            result=r.reconcile(self.con,event["event_id"],POLICY)
        self.assertEqual(set(result["domains"].values()),{"awaiting_provider"})
        self.assertFalse(result["production_mutation"])
    def test_dependency_graph_prevents_downstream_execution(self):
        def transport(t,s):return r.result("blocked_dependency","fake","no_route")
        def fails(t,s):raise AssertionError("upstream dependency unverified")
        with patch.dict(r.ADAPTERS,{"transport":transport,
               "identity":fails,"authority":fails,"agent":fails,
               "package":fails,"workflow":fails}):
            result=r.reconcile(self.con,self.event()["event_id"],POLICY)
        self.assertEqual(result["domains"]["agent"],"blocked_dependency")
        self.assertEqual(result["domains"]["package"],"blocked_dependency")
    def test_coalesces_repeated_target_events(self):
        a=self.event();b=self.event();c=self.event(PROD)
        invoked=[]
        def ready(t,s):
            invoked.append(t)
            return r.result("verified","fake","valid")
        with patch.dict(r.ADAPTERS,{d:ready for d in r.DOMAINS}):
            result=r.sweep(self.con,POLICY,20)
        self.assertEqual(result["processed"],3)
        self.assertEqual(result["readback_cycles"],2)
        self.assertEqual(result["coalesced"],1)
        self.assertEqual(invoked.count(SITE),len(r.DOMAINS))
        self.assertEqual(self.con.execute(
           "select count(*) from events where state='completed'").fetchone()[0],3)
    def test_review_auto_resolves_on_subsequent_good_readback(self):
        state={"good":False}
        def transport(t,s):return r.result("verified","fake","transport")
        def identity(t,s):
            return r.result("verified" if state["good"] else "review_required",
                            "fake","key_conflict")
        def rest(t,s):return r.result("verified","fake","readback")
        adapters={name:rest for name in r.DOMAINS}
        adapters.update({"transport":transport,"identity":identity})
        with patch.dict(r.ADAPTERS,adapters):
            r.reconcile(self.con,self.event()["event_id"],POLICY)
            self.assertEqual(r.show_status(self.con)["open_reviews"],1)
            state["good"]=True
            r.reconcile(self.con,self.event()["event_id"],POLICY)
            self.assertEqual(r.show_status(self.con)["open_reviews"],0)
    def test_authorized_package_repair_has_digest_readback(self):
        expected="db544322956f2b50b6560b1ebfa0a80a98bd31e147d96df691fc185582dff863"
        def fake(argv,timeout=35):
            if "readback" in argv:return (75,'{"state":"FAIL_CLOSED"}',"")
            if "transfer" in argv:return (0,json.dumps({"status":"READBACK_VERIFIED","sha256":expected}),"")
            raise AssertionError("not allowlisted command")
        with patch.object(r,"command",side_effect=fake):
            v=r.inspect_package(SITE,SCOPE)
        self.assertEqual(v["state"],"verified")
        self.assertEqual(v["evidence"]["installed"],False)
        self.assertEqual(v["evidence"]["repair_action"],"verified_zip_transfer")
    def test_package_repair_requires_explicit_operation_scope(self):
        with patch.object(r,"command",return_value=(75,'{"state":"FAIL_CLOSED"}',"")):
            v=r.inspect_package(SITE,{**SCOPE,"operations":[]})
        self.assertEqual(v["reason"],"transfer_not_authorized")
        self.assertEqual(v["state"],"blocked_dependency")

    def test_full_authority_uses_target_provider_not_central_plugin(self):
        scope={**SCOPE,"full_authority_provider":{
            "mode":"native_target_owner","target":SITE,
            "environment":"staging","origin":SCOPE["canonical_origin"],
            "site_uuid":SCOPE["site_uuid"]}}
        with patch.object(r,"command",side_effect=AssertionError("central plugin probe must not be called")):
            observed=r.inspect_full_authority(SITE,scope)
        self.assertEqual(observed["state"],"awaiting_provider")
        self.assertEqual(observed["reason"],"target_native_owner_readback_adapter_not_connected")

    def test_full_authority_rejects_foreign_target(self):
        scope={**SCOPE,"full_authority_provider":{
            "mode":"native_target_owner","target":PROD,
            "environment":"staging","origin":SCOPE["canonical_origin"]}}
        observed=r.inspect_full_authority(SITE,scope)
        self.assertEqual(observed["state"],"review_required")
        self.assertEqual(observed["reason"],"target_native_owner_binding_mismatch")

    def test_unconfigured_production_scope_never_mutates(self):
        observed=r.reconcile(self.con,self.event(PROD)["event_id"],POLICY)
        self.assertEqual(set(observed["domains"].values()),{"awaiting_provider"})
        self.assertFalse(observed["production_mutation"])

    def test_native_owner_readback_requires_original_evidence(self):
        native={"native_owner":"MAD4B_SCP_Full_Staging_Authority"}
        plan={"contract":"mad4b.unified-full-authority.v1","site_uuid":SCOPE["site_uuid"],
              "environment":"staging","native_owner":native["native_owner"],
              "plan_sha256":"a"*64,"ready_to_apply":True,"native_owner_ready":True,
              "blockers":[],"mutation_performed":False,
              "production_mutation_performed":False}
        proof={"native_source_confirmed":False,"readback_confirmed":True,
               "exact_target_bound":True,"origin":SCOPE["canonical_origin"],
               "receipt_digest":"b"*64}
        observed=r.validate_original_owner_plan(SITE,SCOPE,native,plan,proof)
        self.assertEqual(observed["state"],"review_required")
        proof["native_source_confirmed"]=True
        self.assertEqual(r.validate_original_owner_plan(SITE,SCOPE,native,plan,proof)["state"],"verified")
        self.assertFalse(r.validate_original_owner_plan(SITE,SCOPE,native,plan,proof)["evidence"]["apply_executed"])

    def test_native_owner_rejects_wrong_actor_owner(self):
        native={"native_owner":"MAD4B_SCP_Full_Staging_Authority"}
        proof={"native_source_confirmed":True,"readback_confirmed":True,
               "exact_target_bound":True,"origin":SCOPE["canonical_origin"],
               "receipt_digest":"b"*64}
        plan={"contract":"mad4b.unified-full-authority.v1","site_uuid":SCOPE["site_uuid"],
              "environment":"staging","native_owner":"Another_Authority",
              "plan_sha256":"a"*64,"ready_to_apply":True,"native_owner_ready":True,
              "mutation_performed":False,"production_mutation_performed":False}
        observed=r.validate_original_owner_plan(SITE,SCOPE,native,plan,proof)
        self.assertEqual(observed["state"],"review_required")

    def test_production_requires_original_strict_policy(self):
        native={"native_owner":"MAD4B_SCP_Production_Full_Authority"}
        scope={"site_uuid":"b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b",
               "canonical_origin":"https://www.allroyalegypt.com"}
        plan={"contract":"mad4b.unified-full-authority.v1","site_uuid":scope["site_uuid"],
              "environment":"production","native_owner":native["native_owner"],
              "plan_sha256":"a"*64,"ready_to_apply":True,"native_owner_ready":True,
              "blockers":[],"mutation_performed":False,
              "production_mutation_performed":False}
        proof={"native_source_confirmed":True,"readback_confirmed":True,
               "exact_target_bound":True,"origin":scope["canonical_origin"],
               "receipt_digest":"b"*64}
        self.assertEqual(r.validate_original_owner_plan(PROD,scope,native,plan,proof)["state"],"review_required")
        plan["production_strict"]=True
        plan["requires_independent_production_readback"]=True
        self.assertEqual(r.validate_original_owner_plan(PROD,scope,native,plan,proof)["state"],"verified")

    def test_admin_shell_requires_original_host_scoped_grant_and_receipt(self):
        root=Path(self.tmp.name)
        policy=root/"native-operator-v1"
        (policy/"receipts").mkdir(parents=True)
        row={"contract":"mad4b.native-vps-operator.v1",
            "revoked":False,"allow_general_shell":True,
            "scopes":{SITE:{"allow_operator":True,"allow_remote_shell":True,
                "allow_mutation":True,"allow_production_mutation":False,
                "site_uuid":SCOPE["site_uuid"],
                "expected_root":"/expected/staging",
                "canonical_origin":SCOPE["canonical_origin"],
                "registry_binding":SCOPE["transport_binding"]}}}
        receipt={"contract":"mad4b.native-vps-operator.v1",
                 "lane":"registered_remote_ssh","company":"all-royal-egypt",
                 "environment":"staging","kind":"ssh","state":"VERIFIED",
                 "readback_matches_expected":True,
                 "mutating":False,"mutation_performed":False,
                 "production_mutation_authorized":False,
                 "readback_sha256":"c"*64}
        (policy/"host-authority.json").write_text(json.dumps(row))
        (policy/"receipts"/"are-staging-registry-general-shell-20261031.json").write_text(json.dumps(receipt))
        scope={**SCOPE,"expected_root":"/expected/staging"}
        with patch.object(r,"ROOT",root):
            self.assertEqual(r.inspect_admin_shell(SITE,scope)["state"],"verified")
            row["revoked"]=True
            (policy/"host-authority.json").write_text(json.dumps(row))
            self.assertEqual(r.inspect_admin_shell(SITE,scope)["state"],"blocked_authority")

    def test_admin_shell_receipt_must_match_exact_target(self):
        root=Path(self.tmp.name)
        policy=root/"native-operator-v1"
        (policy/"receipts").mkdir(parents=True)
        row={"contract":"mad4b.native-vps-operator.v1",
            "revoked":False,"allow_general_shell":True,
            "scopes":{SITE:{"allow_operator":True,"allow_remote_shell":True,
                "allow_mutation":True,"allow_production_mutation":False,
                "site_uuid":SCOPE["site_uuid"],"expected_root":"/expected/staging",
                "canonical_origin":SCOPE["canonical_origin"],
                "registry_binding":SCOPE["transport_binding"]}}}
        receipt={"contract":"mad4b.native-vps-operator.v1",
                 "lane":"registered_remote_ssh","company":"different-company",
                 "environment":"staging","kind":"ssh","state":"VERIFIED",
                 "readback_matches_expected":True,
                 "mutating":False,"mutation_performed":False,
                 "production_mutation_authorized":False}
        (policy/"host-authority.json").write_text(json.dumps(row))
        (policy/"receipts"/"are-staging-registry-general-shell-20261031.json").write_text(json.dumps(receipt))
        with patch.object(r,"ROOT",root):
            observed=r.inspect_admin_shell(SITE,{**SCOPE,"expected_root":"/expected/staging"})
        self.assertEqual(observed["state"],"review_required")

    def test_execution_route_uses_existing_registry_admin_shell(self):
        with patch.object(r,"inspect_admin_shell",return_value=r.result(
            "verified","native_vps_operator","exact_registry_proof")):
            out=r.inspect_execution_route(SITE,SCOPE)
        self.assertEqual(out["state"],"verified")
        self.assertEqual(out["evidence"]["selected_lane"],"registered_remote_ssh")
        self.assertFalse(out["evidence"]["wordpress_queued_shell_granted"])
        self.assertFalse(out["evidence"]["network_sandbox_equivalent"])

    def test_execution_route_declines_unverified_shell(self):
        with patch.object(r,"inspect_admin_shell",return_value=r.result(
            "blocked_authority","native_vps_operator","grant_missing")):
            out=r.inspect_execution_route(SITE,SCOPE)
        self.assertEqual(out["state"],"blocked_dependency")

    def test_central_scope_survives_rebuilt_company_inventory(self):
        root=Path(self.tmp.name)
        (root/"environment-inventory-v1.json").write_text(json.dumps({
            "contract":"mad4b.company-transport-inventory.v1",
            "targets":{SITE:INVENTORY[SITE]}}))
        self_policy=root/"central-policy.json"
        central={"site_uuid":"e5e64bca-ed62-48d2-b2b3-dff693cd702a",
            "canonical_origin":"https://tours.dev.mad4b.com",
            "environment":"staging",
            "central_execution_adapter":"root_owned_host_operator_v1"}
        self_policy.write_text(json.dumps({"scopes":{"tours-central:staging":central}}))
        self.inventory.stop()
        try:
            with patch.object(r,"ROOT",root):
                with patch.object(r,"POLICY",self_policy):
                    sites=r.inventory()
        finally:
            self.inventory.start()
        self.assertIn("tours-central:staging",sites)
        self.assertEqual(sites["tours-central:staging"]["environment"],"staging")
        self.assertFalse(sites["tours-central:staging"]["production_mutation"])

    def test_central_host_not_blocked_by_oauth_or_native_owner(self):
        central={"site_uuid":"e5e64bca-ed62-48d2-b2b3-dff693cd702a",
            "canonical_origin":"https://tours.dev.mad4b.com",
            "environment":"staging",
            "central_execution_adapter":"root_owned_host_operator_v1"}
        target="tours-central:staging"
        inv={**INVENTORY,target:{**central,"onboarding_state":"LOCAL_HOST"}}
        scoped={**POLICY,"scopes":{SITE:SCOPE,target:central}}
        def forbidden(t,s):
            raise AssertionError("remote transport or native owner must not run")
        with patch.object(r,"inventory",return_value=inv):
            event=self.event(target)
            with patch.dict(r.ADAPTERS,{name:forbidden for name in r.DOMAINS}):
                with patch.dict(r.ADAPTERS,{
                    "central_source":lambda t,s:r.result("blocked_dependency","source","commit_changed"),
                    "central_authority":forbidden,
                    "central_execution":lambda t,s:r.result("verified","host","original_scope_verified")}):
                    output=r.reconcile(self.con,event["event_id"],scoped)
        self.assertEqual(output["domains"]["central_execution"],"verified")
        self.assertEqual(output["domains"]["full_authority"],"not_applicable")
        self.assertEqual(output["domains"]["transport"],"not_applicable")
        self.assertEqual(output["domains"]["central_authority"],"blocked_dependency")
        self.assertFalse(output["production_mutation"])

if __name__=="__main__":
    unittest.main()
