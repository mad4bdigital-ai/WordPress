#!/usr/bin/env python3
"""Dynamic acceptance checks run without tenant-specific executable rules."""
import base64
import datetime as dt
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

spec=importlib.util.spec_from_file_location("capability_acceptance_under_test",
    Path(__file__).resolve().parents[1]/"capability_acceptance.py")
cap=importlib.util.module_from_spec(spec)
spec.loader.exec_module(cap)
T="wp-content/plugins/mad4b-example/includes/widget.php"

class DynamicAcceptanceTest(unittest.TestCase):
    def setUp(self):
        self.sk=Ed25519PrivateKey.generate()
        key=self.sk.public_key().public_bytes(serialization.Encoding.Raw,
                                               serialization.PublicFormat.Raw)
        self.manifest={"operation_id":"mad4b.example.functional-check",
                       "provider":"mad4b.example"}
        self.policy={"capability_acceptance":{
            "contract":cap.REGISTRY_CONTRACT,
            "catalog_path":"ops/company-code-release-v1/catalog/capability-acceptance.json",
            "providers":{"mad4b.example":{"enabled":True,"capabilities":["ui.widget"],
                "public_key_b64":base64.b64encode(key).decode(),
                "producer_operation_id":self.manifest["operation_id"],
                "producer_manifest_sha256":cap.sha(
                    cap.canonical(self.manifest))}}}}
        self.doc={"contract":cap.CATALOG_CONTRACT,"checks":[{
            "provider":"mad4b.example","capability":"ui.widget",
            "id":"widget.render.parity","targets":[T]}]}
        self.selected=[{"target":T}]
        self.allow=lambda v:v.startswith("wp-content/plugins/mad4b-")
        self.get=lambda *_:cap.canonical(self.doc)
        self.snapshot=self.make()

    def make(self,selected=None):
        return cap.snapshot(selected if selected is not None else self.selected,
                            self.policy,Path("/unused"),"a"*40,self.get,self.allow)

    def test_no_enrollment_preserves_existing_source_release(self):
        result=cap.snapshot(self.selected,{},Path("/unused"),
                            "a"*40,self.get,self.allow)
        self.assertEqual(result["checks"],[])

    def test_single_provider_scoped_and_pinned(self):
        self.assertEqual(len(self.snapshot["checks"]),1)
        self.assertEqual(self.snapshot["checks"][0]["targets"],[T])
        self.assertEqual(self.snapshot["catalog_sha256"],
                         cap.sha(cap.canonical(self.doc)))

    def test_uncovered_target_fails_closed_by_default(self):
        with self.assertRaisesRegex(cap.AcceptanceDenied,"COVERAGE_MISSING"):
            self.make([*self.selected,{"target":T+"-extra.php"}])

    def test_unknown_provider_cannot_be_admitted_by_git(self):
        self.doc["checks"][0]["provider"]="unapproved.provider"
        with self.assertRaisesRegex(cap.AcceptanceDenied,"PROVIDER_NOT_ENROLLED"):
            self.make()

    def test_disabled_unused_provider_does_not_block_other_producers(self):
        config=self.policy["capability_acceptance"]
        config["providers"]["another.provider"]={
            "enabled":False,
            "capabilities":["ui.widget"],
            "producer_operation_id":"another.provider.check",
            "producer_manifest_sha256":"0"*64,
            "public_key_b64":config["providers"][
                "mad4b.example"]["public_key_b64"],
        }
        self.assertEqual(len(self.make()["checks"]),1)
        config["providers"]["mad4b.example"]["enabled"]=False
        with self.assertRaisesRegex(cap.AcceptanceDenied,
                                    "PROVIDER_NOT_ENROLLED"):
            self.make()

    def test_noncanonical_catalog_target_rejected(self):
        self.doc["checks"][0]["targets"]=[T.replace("/includes/", "//includes/")]
        with self.assertRaisesRegex(cap.AcceptanceDenied, "TARGET_INVALID"):
            self.make()

    def test_duplicate_check_rejected(self):
        self.doc["checks"].append(dict(self.doc["checks"][0]))
        with self.assertRaisesRegex(cap.AcceptanceDenied,"CHECK_DUPLICATE"):
            self.make()

    def test_impact_graph_requires_every_owner_capability_not_one_check_per_file(self):
        self.policy["capability_acceptance"]["require_impact_coverage"]=True
        self.doc["requirements"]=[{
            "target":T,"provider":"mad4b.example",
            "capabilities":["ui.widget"]}]
        self.assertEqual(self.make()["impact_requirements"],
                         [[T,"mad4b.example","ui.widget"]])
        self.doc["requirements"][0]["capabilities"]=["ui.widget","ui.second"]
        self.policy["capability_acceptance"]["providers"][
            "mad4b.example"]["capabilities"].append("ui.second")
        with self.assertRaisesRegex(cap.AcceptanceDenied,"IMPACT_CHECK_MISSING"):
            self.make()
        self.doc["requirements"]=[]
        with self.assertRaisesRegex(cap.AcceptanceDenied,"IMPACT_GRAPH_INCOMPLETE"):
            self.make()

    def test_catalog_cannot_inject_shell_php_url_or_callbacks(self):
        self.doc["checks"][0]["command"]="touch /tmp/injected"
        with self.assertRaisesRegex(cap.AcceptanceDenied,"CHECK_INVALID"):
            self.make()

    def test_unregistered_producer_is_denied_before_execution(self):
        class MissingRegistry:
            def resolve(self, operation):
                raise ValueError("missing operation")
        plan={"capability_acceptance":self.snapshot}
        with self.assertRaisesRegex(cap.AcceptanceDenied,
                                    "PRODUCER_NOT_REGISTERED"):
            cap.verify_producer_enrollment(
                plan,self.policy,registry_factory=lambda:MissingRegistry())

    def test_exact_installed_producer_manifest_required(self):
        class Registry:
            def __init__(self, manifest):self.manifest=manifest
            def resolve(self, operation):return self.manifest
            def manifest_sha256(self, operation):
                return cap.sha(cap.canonical(self.manifest))
        plan={"capability_acceptance":self.snapshot}
        success=cap.verify_producer_enrollment(
            plan,self.policy,registry_factory=lambda:Registry(self.manifest))
        self.assertEqual(success["producer_operations"],
                         [self.manifest["operation_id"]])
        fake={**self.manifest,"provider":"foreign.owner"}
        with self.assertRaisesRegex(cap.AcceptanceDenied,
                                    "PRODUCER_MANIFEST_DRIFT"):
            cap.verify_producer_enrollment(
                plan,self.policy,registry_factory=lambda:Registry(fake))

    def test_signed_provider_payload_runs_in_unprivileged_ci(self):
        now=dt.datetime.now(dt.timezone.utc)
        payload={
            "contract":cap.EVIDENCE_CONTRACT,
            "provider":"mad4b.example",
            "producer_operation_id":self.manifest["operation_id"],
            "plan_sha256":"b"*64,
            "site_uuid":"12345678-1234-1234-1234-123456789abc",
            "git_commit":"a"*40,
            "apply_nonce":"example_nonce_12345678",
            "challenge":"c"*64,
            "postimage_sha256":"d"*64,
            "catalog_sha256":self.snapshot["catalog_sha256"],
            "observed_at":now.isoformat(),
            "results":[{"capability":"ui.widget",
                        "id":"widget.render.parity","passed":True}],
        }
        def signed(item):
            obj=json.loads(json.dumps(item))
            obj["signature"]=base64.b64encode(
                self.sk.sign(cap.canonical(item))).decode()
            return obj
        plan={"plan_sha256":payload["plan_sha256"],
              "site_uuid":payload["site_uuid"],
              "git_commit":payload["git_commit"],
              "capability_acceptance":self.snapshot}
        kwargs={
            "plan":plan,
            "enrolled":self.policy["capability_acceptance"]["providers"][
                "mad4b.example"],
            "provider":"mad4b.example",
            "declared":self.snapshot["checks"],
            "apply_nonce":payload["apply_nonce"],
            "postimage_verified_at":now-dt.timedelta(seconds=1),
            "challenge":payload["challenge"],
            "postimage_sha256":payload["postimage_sha256"],
        }
        cap.verify_provider_payload(signed(payload),**kwargs)
        tampered=signed(payload)
        tampered["results"][0]["passed"]=False
        with self.assertRaisesRegex(cap.AcceptanceDenied,"CHECKS_NOT_PROVEN"):
            cap.verify_provider_payload(tampered,**kwargs)
        modified=signed(payload)
        modified["challenge"]="e"*64
        with self.assertRaisesRegex(cap.AcceptanceDenied,"BINDING_INVALID"):
            cap.verify_provider_payload(modified,**kwargs)
        wrong_signing_key=Ed25519PrivateKey.generate()
        forged=dict(payload)
        forged["signature"]=base64.b64encode(
            wrong_signing_key.sign(cap.canonical(payload))).decode()
        with self.assertRaisesRegex(cap.AcceptanceDenied,"SIGNATURE_INVALID"):
            cap.verify_provider_payload(forged,**kwargs)

    def test_v2_typed_witness_rejects_incorrect_measurements_and_time(self):
        enrolled=self.policy["capability_acceptance"]["providers"]["mad4b.example"]
        enrolled["require_witnesses"]=True
        enrolled["witness_schemas"]={
            "widget.render.parity":{
                "response_status":{"type":"integer","equals":200},
                "render_sha256":{"type":"sha256"}}}
        now=dt.datetime.now(dt.timezone.utc)
        result={"capability":"ui.widget","id":"widget.render.parity",
                "passed":True,"witness_sha256":"e"*64}
        witness={"contract":cap.WITNESS_CONTRACT,
                 "provider":"mad4b.example",
                 "producer_operation_id":self.manifest["operation_id"],
                 "site_uuid":"12345678-1234-1234-1234-123456789abc",
                 "apply_nonce":"example_nonce_12345678",
                 "postimage_sha256":"d"*64, "challenge":"c"*64,
                 "capability":"ui.widget","id":"widget.render.parity",
                 "observed_at":now.isoformat(),
                 "readings":{"response_status":200,"render_sha256":"f"*64}}
        kwargs={"plan":{"site_uuid":witness["site_uuid"]},
                "enrolled":enrolled,"provider":"mad4b.example",
                "result":result,"apply_nonce":witness["apply_nonce"],
                "postimage_sha256":witness["postimage_sha256"],
                "challenge":witness["challenge"],
                "postimage_verified_at":now-dt.timedelta(seconds=1),
                "proof_observed_at":now+dt.timedelta(seconds=1)}
        cap.validate_witness(witness,**kwargs)
        invalid=json.loads(json.dumps(witness))
        invalid["readings"]["response_status"]=500
        with self.assertRaisesRegex(cap.AcceptanceDenied,"WITNESS_READING_INVALID"):
            cap.validate_witness(invalid,**kwargs)
        invalid=json.loads(json.dumps(witness))
        invalid["challenge"]="a"*64
        with self.assertRaisesRegex(cap.AcceptanceDenied,"WITNESS_BINDING_INVALID"):
            cap.validate_witness(invalid,**kwargs)
        with self.assertRaisesRegex(cap.AcceptanceDenied,"WITNESS_TIME_INVALID"):
            cap.validate_witness(witness,**{**kwargs,
                "postimage_verified_at":now+dt.timedelta(seconds=10)})

    def test_v2_signed_witness_file_is_checked_twice(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp)/"capability-acceptance"
            witnesses=directory/"witnesses"
            witnesses.mkdir(parents=True,mode=0o700)
            directory.chmod(0o700)
            if witnesses.stat().st_uid != 0:
                self.skipTest("Root-private verifier fixture")
            enrollment=self.policy["capability_acceptance"]["providers"][
                "mad4b.example"]
            enrollment["require_witnesses"]=True
            enrollment["witness_schemas"]={
                "widget.render.parity":{
                    "response_status":{"type":"integer","equals":200}}}
            nonce="example_nonce_12345678"
            begin=dt.datetime.now(dt.timezone.utc)-dt.timedelta(seconds=2)
            observed=dt.datetime.now(dt.timezone.utc)
            plan={"plan_sha256":"b"*64,
                  "site_uuid":"12345678-1234-1234-1234-123456789abc",
                  "git_commit":"a"*40,
                  "capability_acceptance":self.snapshot}
            witness={"contract":cap.WITNESS_CONTRACT,
                     "provider":"mad4b.example",
                     "producer_operation_id":self.manifest["operation_id"],
                     "site_uuid":plan["site_uuid"],"apply_nonce":nonce,
                     "postimage_sha256":"d"*64,"challenge":"c"*64,
                     "capability":"ui.widget","id":"widget.render.parity",
                     "observed_at":observed.isoformat(),
                     "readings":{"response_status":200}}
            payload=json.dumps(witness).encode()
            wp=witnesses/(nonce+".mad4b.example.widget.render.parity.json")
            wp.write_bytes(payload);wp.chmod(0o600)
            evidence={"contract":cap.EVIDENCE_V2_CONTRACT,
                "provider":"mad4b.example",
                "producer_operation_id":self.manifest["operation_id"],
                "plan_sha256":plan["plan_sha256"],
                "site_uuid":plan["site_uuid"],
                "git_commit":plan["git_commit"],
                "apply_nonce":nonce,"challenge":"c"*64,
                "postimage_sha256":"d"*64,
                "catalog_sha256":self.snapshot["catalog_sha256"],
                "observed_at":observed.isoformat(),
                "results":[{"capability":"ui.widget",
                            "id":"widget.render.parity","passed":True,
                            "witness_sha256":cap.sha(payload)}]}
            evidence["signature"]=base64.b64encode(
                self.sk.sign(cap.canonical(evidence))).decode()
            ep=directory/(nonce+".mad4b.example.json")
            ep.write_text(json.dumps(evidence));ep.chmod(0o600)
            result=cap.verify_evidence(plan,self.policy,Path(temp),nonce,
                    begin.isoformat(),"c"*64,"d"*64)
            self.assertEqual(result["verified_checks"],1)
            wp.write_text(wp.read_text().replace("200","500"))
            with self.assertRaisesRegex(cap.AcceptanceDenied,
                                        "WITNESS_DIGEST_DRIFT"):
                cap.verify_evidence(plan,self.policy,Path(temp),nonce,
                    begin.isoformat(),"c"*64,"d"*64)

    def test_root_private_signed_provider_evidence(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp)/"capability-acceptance"
            directory.mkdir(mode=0o700)
            if directory.stat().st_uid != 0:
                self.skipTest("root-only receipt admission fixture")
            nonce="example_nonce_12345678"
            challenge="c"*64
            postimage_sha256="d"*64
            now=dt.datetime.now(dt.timezone.utc)
            begin=(now-dt.timedelta(seconds=2)).isoformat()
            plan={"plan_sha256":"b"*64,
                  "site_uuid":"12345678-1234-1234-1234-123456789abc",
                  "git_commit":"a"*40,
                  "capability_acceptance":self.snapshot}
            payload={"contract":cap.EVIDENCE_CONTRACT,
                "provider":"mad4b.example",
                "producer_operation_id":self.manifest["operation_id"],
                "plan_sha256":plan["plan_sha256"],
                "site_uuid":plan["site_uuid"],"git_commit":plan["git_commit"],
                "apply_nonce":nonce, "challenge":challenge,
                "postimage_sha256":postimage_sha256,
                "catalog_sha256":self.snapshot["catalog_sha256"],
                "observed_at":now.isoformat(),
                "results":[{"capability":"ui.widget",
                            "id":"widget.render.parity","passed":True}]}
            path=directory/(nonce+".mad4b.example.json")
            def write(obj):
                evidence=dict(obj)
                evidence["signature"]=base64.b64encode(
                    self.sk.sign(cap.canonical(obj))).decode()
                path.write_text(json.dumps(evidence))
                path.chmod(0o600)
            write(payload)
            got=cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin,challenge,postimage_sha256)
            self.assertEqual(got["verified_checks"],1)
            row=json.loads(path.read_text())
            row["results"][0]["passed"]=False
            path.write_text(json.dumps(row))
            with self.assertRaisesRegex(cap.AcceptanceDenied,"CHECKS_NOT_PROVEN"):
                cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin,challenge,postimage_sha256)
            write(payload)
            row=json.loads(path.read_text())
            row["catalog_sha256"]="c"*64
            path.write_text(json.dumps(row))
            with self.assertRaisesRegex(cap.AcceptanceDenied,"BINDING_INVALID"):
                cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin,challenge,postimage_sha256)
            write(payload)
            # Signed pre-Apply evidence must not certify a later postimage.
            with self.assertRaisesRegex(cap.AcceptanceDenied, "EVIDENCE_TIME_INVALID"):
                cap.verify_evidence(
                    plan, self.policy, Path(temp), nonce,
                    (now + dt.timedelta(seconds=2)).isoformat(),
                    challenge, postimage_sha256)
            write(payload)
            with self.assertRaisesRegex(cap.AcceptanceDenied, "BINDING_INVALID"):
                cap.verify_evidence(
                    plan, self.policy, Path(temp), nonce, begin,
                    "e"*64, postimage_sha256)
            with self.assertRaisesRegex(cap.AcceptanceDenied, "BINDING_INVALID"):
                cap.verify_evidence(
                    plan, self.policy, Path(temp), nonce, begin,
                    challenge, "f"*64)
            row=json.loads(path.read_text())
            row["signature"]=base64.b64encode(b"invalid").decode()
            path.write_text(json.dumps(row))
            with self.assertRaisesRegex(cap.AcceptanceDenied,"SIGNATURE_INVALID"):
                cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin,challenge,postimage_sha256)

if __name__=="__main__":
    unittest.main()
