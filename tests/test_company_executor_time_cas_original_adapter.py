"""Source-only Original dynamic provider CAS tests. No real Job or signer."""
from __future__ import annotations
import base64
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import sys
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

BASE=Path(__file__).resolve().parents[1]
SRC=BASE/"ops/dynamic-operation-registry-v1"
HOST=SRC/"executor-time-cas-v1/host_handler.py"
READER=SRC/"executor-time-cas-v1/independent_readback.py"


def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


cas=load(HOST,"test_firstparty_cas_host")
readback=load(READER,"test_firstparty_cas_independent")


class Result:
    returncode=0
    stderr=b""
    def __init__(self,doc):
        self.stdout=(json.dumps(doc)+"\n").encode()


class CAS(unittest.TestCase):
    def setUp(self):
        self.dir=tempfile.TemporaryDirectory(prefix="mad4b-cas-test-")
        self.addCleanup(self.dir.cleanup)
        self.base=Path(self.dir.name)
        self.registry=self.base/"enrolled"
        self.store=self.base/"plans"
        self.reserved=self.base/"reserved"
        self.receipts=self.base/"receipts"
        self.specific_grants=self.base/"grants"
        for path in (self.registry,self.store,self.reserved,self.receipts,
                     self.specific_grants):
            path.mkdir(mode=0o700)
        selected=[
            "registry.py","admission.py",
            "executor-time-cas-v1/host_handler.py",
            "executor-time-cas-v1/independent_readback.py",
            "executor-time-cas-v1/preclaim.py",
            "executor-time-cas-v1/finalizer.py",
            "providers/company.resource.cas.apply/manifest.json",
            "providers/company.resource.cas.apply/evidence-schema.json",
        ]
        for rel in selected:
            target=self.registry/rel
            target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes((SRC/rel).read_bytes())
            target.chmod(0o600)
        self.testSigner=Ed25519PrivateKey.generate()
        pub=self.testSigner.public_key().public_bytes_raw()
        self.broker_public=self.base/"host-ed25519-public.base64"
        self.broker_public.write_bytes(base64.b64encode(pub))
        self.broker_public.chmod(0o600)
        man=(self.registry/"providers/company.resource.cas.apply/manifest.json").read_bytes()
        self.index={
            "contract":"mad4b.dynamic-operation-index.v1",
            "installed_by_first_party":True,
            "independent_verifier_public_key_b64":base64.b64encode(pub).decode(),
            "providers":[{"manifest":"providers/company.resource.cas.apply/manifest.json",
                          "sha256":hashlib.sha256(man).hexdigest()}],
        }
        (self.registry/"index.json").write_text(json.dumps(self.index))
        (self.registry/"index.json").chmod(0o600)
        if str(self.registry) not in sys.path:sys.path.insert(0,str(self.registry))
        import registry
        self.reg=registry.Registry(self.registry)
        self.manifest=self.reg.resolve(cas.OPERATION)
        self.job="a"*32
        self.plan={
            "contract":cas.CONTRACT,
            "company":"wayfatours","environment":"production",
            "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
            "origin":"https://wayfatours.com",
            "source_plan_sha256":"b"*64,
            "resource_type":"wordpress.post","resource_id":"61152",
            "post_type":"tour-rates","meta_key":"rate_title","meta_id":902,
            "expected_before_sha256":"c"*64,
            "expected_manifest_sha256":"d"*64,
            "expected_company_preservation_sha256":"e"*64,
            "expected_meta_value_sha256":hashlib.sha256(b"old-rate-value").hexdigest(),
            "new_meta_value_sha256":hashlib.sha256(b"new-rate-value").hexdigest(),
            "expected_after_sha256":"1"*64,
            "new_meta_value":"new-rate-value",
            "old_meta_value":"old-rate-value",
        }
        self.make_plan()
        self.cmd_sha=self.reg.command_sha256(cas.OPERATION,self.plan_sha)
        self.authority={
            "verified":True,"eligible":True,"owner_enrolled_wpcli":True,
            "job_id":self.job,"operation":"company.remote.wpcli.full",
            "command_sha256":self.cmd_sha,"target_id":"srv2030908",
            "company":"wayfatours","environment":"production",
            "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
            "expires_epoch":int(time.time())+600,
            "grant_id":"1"*32,"transport_id":"2"*32,
        }
        (self.reserved/(self.job+".reserved")).write_bytes(
           ("dynamic:"+cas.OPERATION+" "+self.cmd_sha+"\n").encode())
        (self.reserved/(self.job+".reserved")).chmod(0o600)

    def make_plan(self):
        # Dynamic source plan hash is not tied to the later assigned 305 Job ID.
        import registry
        self.plan_sha=registry.sha(registry.canonical(self.plan))
        self.doc={**self.plan,"plan_sha256":self.plan_sha}
        path=self.store/(self.plan_sha+".json")
        path.write_text(json.dumps(self.doc))
        path.chmod(0o600)

    def pinned(self):
        with patch.object(cas,"DYNAMIC",self.registry),patch.object(cas,"PLANS",self.store),\
            patch.object(cas,"RESERVED",self.reserved),\
            patch("admission.fresh_original_312",return_value=self.authority):
            return cas.pinned_plan(self.job,self.plan_sha)

    def test_manifest_discovered_by_first_party_registry(self):
        self.assertEqual(self.manifest["operation_id"],cas.OPERATION)
        self.assertEqual(self.manifest["version"],1)
        self.assertIn(cas.OPERATION,self.reg.summary()["enrolled_operations"])

    def test_registration_uses_exact_immutable_plan_sha(self):
        _,p,authority,digest=self.pinned()
        self.assertEqual(p["resource_id"],"61152")
        self.assertEqual(authority["site_uuid"],p["site_uuid"])
        self.assertEqual(digest,self.cmd_sha)

    def test_signed_derivative_witness_ephemeral_fixture(self):
        _,p,auth,_=self.pinned()
        proof=cas.derivative_original_witness(self.job,p,auth,self.testSigner,int(time.time()))
        self.assertEqual(list(proof),cas.FIELDS+["signature"])
        msg="\n".join(str(proof[k]) for k in cas.FIELDS).encode()
        self.testSigner.public_key().verify(base64.b64decode(proof["signature"]),msg)
        self.assertEqual(proof["source_plan_sha256"],self.plan["source_plan_sha256"])
        self.assertEqual(proof["native_operation"],cas.OPERATION)

    def test_proof_never_issues_grants_or_original313(self):
        _,p,auth,_=self.pinned()
        proof=cas.derivative_original_witness(self.job,p,auth,self.testSigner,int(time.time()))
        self.assertNotIn("original_313_signed",proof)
        self.assertNotIn("grant_issued",proof)

    def test_unregistered_operation_holds(self):
        (self.registry/"index.json").write_text(json.dumps({
            **self.index,"providers":[]}))
        (self.registry/"index.json").chmod(0o600)
        with self.assertRaises(Exception),patch.object(cas,"DYNAMIC",self.registry):
            self.pinned()

    def test_missing_original_worker_reservation_holds(self):
        (self.reserved/(self.job+".reserved")).unlink()
        with self.assertRaises(Exception):self.pinned()

    def test_wrong_job_reservation_digest_holds(self):
        (self.reserved/(self.job+".reserved")).write_text("dynamic:wrong "+self.cmd_sha+"\n")
        with self.assertRaises(Exception):self.pinned()

    def test_original_312_fails_closed_on_mismatched_company(self):
        self.authority["company"]="other-company"
        with self.assertRaises(Exception):self.pinned()

    def test_original_312_fails_closed_on_mismatched_sha(self):
        self.authority["command_sha256"]="0"*64
        with self.assertRaises(Exception):self.pinned()

    def test_original_312_fails_closed_on_expiry(self):
        self.authority["expires_epoch"]=int(time.time())+20
        with self.assertRaises(Exception):self.pinned()

    def test_plan_resource_data_tamper_holds(self):
        path=self.store/(self.plan_sha+".json")
        row=json.loads(path.read_text())
        row["new_meta_value"]="unauthorized-price"
        path.write_text(json.dumps(row));path.chmod(0o600)
        with self.assertRaises(Exception):self.pinned()

    def test_missing_original312_verification_holds(self):
        self.authority["verified"]=False
        with self.assertRaises(Exception):self.pinned()

    def republish_modified_plan(self,updates):
        self.plan.update(updates)
        self.make_plan()

    def test_price_meta_key_not_in_provider_signed_scope(self):
        self.republish_modified_plan({"meta_key":"single_price"})
        with self.assertRaisesRegex(Exception,"FIRST_PARTY_PROVIDER_MUTATION_SCOPE_NOT_ADMITTED"):
            self.pinned()

    def test_other_commercial_price_meta_key_not_in_provider_scope(self):
        self.republish_modified_plan({"meta_key":"double_price"})
        with self.assertRaisesRegex(Exception,"FIRST_PARTY_PROVIDER_MUTATION_SCOPE_NOT_ADMITTED"):
            self.pinned()

    def test_unapproved_post_type_denied_by_provider(self):
        self.republish_modified_plan({"post_type":"shop_order"})
        with self.assertRaisesRegex(Exception,"FIRST_PARTY_PROVIDER_MUTATION_SCOPE_NOT_ADMITTED"):
            self.pinned()

    def test_wrong_old_meta_bytes_cannot_pass_digest(self):
        self.republish_modified_plan({"old_meta_value":"tampered old value"})
        with self.assertRaisesRegex(Exception,"EXACT_RESTORABLE_BEFORE_META_VALUE_NOT_PINNED"):
            self.pinned()

    def test_absent_rollback_old_value_denied(self):
        self.plan.pop("old_meta_value")
        self.make_plan()
        with self.assertRaisesRegex(Exception,"DYNAMIC_CAS_PLAN_FIELD_SET_INVALID"):
            self.pinned()

    def test_unpinned_registry_manifest_changes_are_denied(self):
        p=self.registry/"providers/company.resource.cas.apply/manifest.json"
        raw=json.loads(p.read_text())
        raw["cas_scope"]["allowed_meta_keys"].append("single_price")
        p.write_text(json.dumps(raw))
        with self.assertRaisesRegex(Exception,"REGISTRY_MANIFEST_DRIFT"):
            self.pinned()

    def test_missing_broker_grant_not_implicitly_claimed(self):
        self.assertNotIn("original_305_grant_created",self.plan)
        self.assertNotIn("original_313_signed",self.plan)

    def signed_grant(self,**updates):
        now=int(time.time())
        row={
            "contract":cas.GRANT_CONTRACT,
            "issuer":"mad4b-company-broker",
            "action":"apply",
            "original_job_id":self.job,
            "operation_id":cas.OPERATION,
            "company":self.plan["company"],
            "environment":self.plan["environment"],
            "site_uuid":self.plan["site_uuid"],
            "origin":self.plan["origin"],
            "grant_id":self.authority["grant_id"],
            "transport_id":self.authority["transport_id"],
            "plan_sha256":self.plan_sha,
            "source_plan_sha256":self.plan["source_plan_sha256"],
            "command_sha256":self.cmd_sha,
            "resource_key":"wordpress.post:61152",
            "meta_key":"rate_title",
            "expected_before_sha256":self.plan["expected_before_sha256"],
            "expected_after_sha256":self.plan["expected_after_sha256"],
            "expected_company_preservation_sha256":
                self.plan["expected_company_preservation_sha256"],
            "issued_epoch":now,
            "expires_epoch":now+180,
            "nonce":"f"*32
        }
        row.update(updates)
        import registry
        canonical=registry.canonical({k:row[k] for k in cas.GRANT_FIELDS})
        row["signature"]=base64.b64encode(self.testSigner.sign(canonical)).decode()
        return row

    def checked_specific_grant(self,grant=None):
        if grant is not None:
            path=self.specific_grants/(self.job+".json")
            path.write_text(json.dumps(grant))
            path.chmod(0o600)
        with patch.object(cas,"CAS_GRANTS",self.specific_grants),             patch.object(cas,"BROKER_KEY",self.broker_public):
            return cas.verify_signed_specific_grant(
                self.job,self.doc,self.authority,self.cmd_sha)

    def test_no_specific_grant_is_a_hard_execution_hold(self):
        with self.assertRaises((FileNotFoundError,cas.CASRejected,Exception)):
            self.checked_specific_grant()

    def test_fresh_operation_specific_signed_grant_admitted(self):
        grant=self.signed_grant()
        state=self.checked_specific_grant(grant)
        self.assertTrue(state["signed_release_grant_verified"])
        self.assertFalse(state["new_original_305_job_created"])

    def test_signed_grant_excludes_commercial_pricing(self):
        grant=self.signed_grant(meta_key="single_price")
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_EXACT_OPERATION_RELEASE_SCOPE_OR_AGE_INVALID"):
            self.checked_specific_grant(grant)

    def test_signed_grant_wrong_company_never_admitted(self):
        grant=self.signed_grant(company="another-company")
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_EXACT_OPERATION_RELEASE_SCOPE_OR_AGE_INVALID"):
            self.checked_specific_grant(grant)

    def test_signed_grant_wrong_site_uuid_never_admitted(self):
        grant=self.signed_grant(site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05f")
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_EXACT_OPERATION_RELEASE_SCOPE_OR_AGE_INVALID"):
            self.checked_specific_grant(grant)

    def test_signed_grant_wrong_source_plan_never_admitted(self):
        grant=self.signed_grant(source_plan_sha256="0"*64)
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_EXACT_OPERATION_RELEASE_SCOPE_OR_AGE_INVALID"):
            self.checked_specific_grant(grant)

    def test_signed_grant_wrong_original_job_id_never_admitted(self):
        grant=self.signed_grant(original_job_id="b"*32)
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_EXACT_OPERATION_RELEASE_SCOPE_OR_AGE_INVALID"):
            self.checked_specific_grant(grant)

    def test_signed_grant_wrong_original_transport_id_never_admitted(self):
        grant=self.signed_grant(transport_id="3"*32)
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_EXACT_OPERATION_RELEASE_SCOPE_OR_AGE_INVALID"):
            self.checked_specific_grant(grant)

    def test_signed_grant_past_expiry_never_admitted(self):
        grant=self.signed_grant(expires_epoch=int(time.time())-1)
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_EXACT_OPERATION_RELEASE_SCOPE_OR_AGE_INVALID"):
            self.checked_specific_grant(grant)

    def test_signed_grant_forged_signature_never_admitted(self):
        grant=self.signed_grant()
        grant["signature"]=base64.b64encode(b"x"*64).decode()
        with self.assertRaisesRegex(Exception,"SIGNED_CAS_BROKER_GRANT_SIGNATURE_NOT_VALID"):
            self.checked_specific_grant(grant)

    def proof(self):
        _,p,a,_=self.pinned()
        return cas.derivative_original_witness(self.job,p,a,self.testSigner,int(time.time()))

    def correct_result(self,proof):
        return {
          "contract":"mad4b.company.original-executor-resource-cas-result.v1",
          "state":"ATOMIC_CAS_APPLIED_PENDING_INDEPENDENT_313",
          "original_job_id":proof["original_job_id"],
          "resource_key":"wordpress.post:61152",
          "site_uuid":proof["site_uuid"],
          "canonical_origin":proof["canonical_origin"],
          "environment":proof["environment"],
          "before_sha256":proof["expected_before_sha256"],
          "after_sha256":proof["expected_after_sha256"],
          "company_preservation_sha256":proof["expected_company_preservation_sha256"],
          "before_cas_verified":True,"atomic_mutation_applied":True,
          "original_313_signed":False,"automatic_retry_authorized":False,
        }

    def test_exact_target_call_validates_complete_postcondition(self):
        proof=self.proof()
        called=[]
        def runner(argv,**kwargs):
            called.append(argv)
            return Result(self.correct_result(proof))
        actual=cas.target_call("a"*64,1,proof,self.plan["new_meta_value"],runner=runner)
        self.assertEqual(actual["state"],"ATOMIC_CAS_APPLIED_PENDING_INDEPENDENT_313")
        self.assertEqual(called[0][0:4],["/usr/bin/docker","exec","-u","33:33"])
        self.assertNotIn("--shell",called[0])
        self.assertNotIn("bash",called[0])

    def test_untrusted_container_or_actor_denied(self):
        proof=self.proof()
        with self.assertRaises(cas.CASRejected):
            cas.target_call("any-container",1,proof,self.plan["new_meta_value"])
        with self.assertRaises(cas.CASRejected):
            cas.target_call("a"*64,0,proof,self.plan["new_meta_value"])

    def test_host_result_cannot_forge_signed313(self):
        proof=self.proof()
        row=self.correct_result(proof)
        row["original_313_signed"]=True
        with self.assertRaises(cas.CASRejected):
            cas.target_call("a"*64,1,proof,self.plan["new_meta_value"],
              runner=lambda *args,**kwargs:Result(row))

    def test_changed_target_afterimage_refuses_host_success(self):
        proof=self.proof()
        row=self.correct_result(proof)
        row["after_sha256"]="0"*64
        with self.assertRaises(cas.CASRejected):
            cas.target_call("a"*64,1,proof,self.plan["new_meta_value"],
              runner=lambda *args,**kwargs:Result(row))

    def test_host_timeout_is_nonretryable(self):
        proof=self.proof()
        with self.assertRaises(cas.CASRejected):
            cas.target_call("a"*64,1,proof,self.plan["new_meta_value"],
              runner=lambda *args,**kwargs:(_ for _ in ()).throw(
                  subprocess.TimeoutExpired(args[0],38)))

    def test_independent_readback_matches_exact_after_and_one_use_fence(self):
        doc={
          "resource":{
            "state":"EXACT_RESOURCE_READ_ONLY_OBSERVED",
            "site_uuid":self.plan["site_uuid"],
            "canonical_origin":self.plan["origin"],
            "environment":self.plan["environment"],
            "resource_key":"wordpress.post:61152",
            "before_sha256":self.plan["expected_after_sha256"],
            "company_preservation_sha256":self.plan["expected_company_preservation_sha256"],
          },
          "fence_sha256":self.plan["new_meta_value_sha256"]
        }
        result=readback.exact_target_readback(self.job,self.doc,"a"*64,1,
            runner=lambda *args,**kwargs:Result(doc))
        self.assertEqual(result["state"],"INDEPENDENT_TARGET_CAS_POSTIMAGE_VERIFIED_UNSIGNED")
        self.assertFalse(result["original_313_signed"])

    def test_independent_readback_rejects_missing_replay_fence(self):
        doc={
          "resource":{
            "state":"EXACT_RESOURCE_READ_ONLY_OBSERVED",
            "site_uuid":self.plan["site_uuid"],
            "canonical_origin":self.plan["origin"],
            "environment":self.plan["environment"],
            "resource_key":"wordpress.post:61152",
            "before_sha256":self.plan["expected_after_sha256"],
            "company_preservation_sha256":self.plan["expected_company_preservation_sha256"],
          },
          "fence_sha256":None
        }
        with self.assertRaises(readback.Hold):
            readback.exact_target_readback(self.job,self.doc,"a"*64,1,
              runner=lambda *args,**kwargs:Result(doc))


if __name__=="__main__":
    unittest.main()
