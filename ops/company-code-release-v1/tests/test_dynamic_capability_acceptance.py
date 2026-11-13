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
        self.policy={"capability_acceptance":{
            "contract":cap.REGISTRY_CONTRACT,
            "catalog_path":"ops/company-code-release-v1/catalog/capability-acceptance.json",
            "providers":{"mad4b.example":{"enabled":True,"capabilities":["ui.widget"],
                "public_key_b64":base64.b64encode(key).decode()}}}}
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

    def test_duplicate_check_rejected(self):
        self.doc["checks"].append(dict(self.doc["checks"][0]))
        with self.assertRaisesRegex(cap.AcceptanceDenied,"CHECK_DUPLICATE"):
            self.make()

    def test_catalog_cannot_inject_shell_php_url_or_callbacks(self):
        self.doc["checks"][0]["command"]="touch /tmp/injected"
        with self.assertRaisesRegex(cap.AcceptanceDenied,"CHECK_INVALID"):
            self.make()

    def test_root_private_signed_provider_evidence(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp)/"capability-acceptance"
            directory.mkdir(mode=0o700)
            if directory.stat().st_uid != 0:
                self.skipTest("root-only receipt admission fixture")
            nonce="example_nonce_12345678"
            now=dt.datetime.now(dt.timezone.utc)
            begin=(now-dt.timedelta(seconds=2)).isoformat()
            plan={"plan_sha256":"b"*64,
                  "site_uuid":"12345678-1234-1234-1234-123456789abc",
                  "git_commit":"a"*40,
                  "capability_acceptance":self.snapshot}
            payload={"contract":cap.EVIDENCE_CONTRACT,
                "provider":"mad4b.example","plan_sha256":plan["plan_sha256"],
                "site_uuid":plan["site_uuid"],"git_commit":plan["git_commit"],
                "apply_nonce":nonce,
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
            got=cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin)
            self.assertEqual(got["verified_checks"],1)
            row=json.loads(path.read_text())
            row["results"][0]["passed"]=False
            path.write_text(json.dumps(row))
            with self.assertRaisesRegex(cap.AcceptanceDenied,"CHECKS_NOT_PROVEN"):
                cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin)
            write(payload)
            row=json.loads(path.read_text())
            row["catalog_sha256"]="c"*64
            path.write_text(json.dumps(row))
            with self.assertRaisesRegex(cap.AcceptanceDenied,"BINDING_INVALID"):
                cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin)
            write(payload)
            # Signed pre-Apply evidence must not certify a later postimage.
            with self.assertRaisesRegex(cap.AcceptanceDenied, "EVIDENCE_TIME_INVALID"):
                cap.verify_evidence(
                    plan, self.policy, Path(temp), nonce,
                    (now + dt.timedelta(seconds=2)).isoformat())
            write(payload)
            row=json.loads(path.read_text())
            row["signature"]=base64.b64encode(b"invalid").decode()
            path.write_text(json.dumps(row))
            with self.assertRaisesRegex(cap.AcceptanceDenied,"SIGNATURE_INVALID"):
                cap.verify_evidence(plan,self.policy,Path(temp),nonce,begin)

if __name__=="__main__":
    unittest.main()
