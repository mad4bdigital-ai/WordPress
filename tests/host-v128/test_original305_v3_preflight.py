"""Original305 live readiness reader: Root pin and sticky HOLD tests."""
import base64
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"tools"))
import mad4b_original305_v3_preflight as p

class Original305PreflightTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.reg=self.root/"reg"
        self.reg.mkdir()
        self.manifest=p.MANIFEST
        m=self.reg/self.manifest
        m.parent.mkdir(parents=True)
        m.write_text(json.dumps({"version":8,"operation_id":"host.source.release"}))
        m.chmod(0o600)
        self.index=self.reg/"index.json"
        self.pub312=b"\x11"*32
        self.pub313=b"\x22"*32
        self.payload={"installed_by_first_party":True,
            "providers":[{"manifest":self.manifest,
                           "sha256":hashlib.sha256(m.read_bytes()).hexdigest()}],
            "host_anchor_312_public_key_b64":base64.b64encode(self.pub312).decode(),
            "independent_verifier_public_key_b64":base64.b64encode(self.pub313).decode()}
        self.index.write_text(json.dumps(self.payload))
        self.index.chmod(0o600)
        self.profile=self.root/"profile.json"
        self.prof={"allowed_operations":["site.code.current.read"],
                   "live_current_binding":{
                       "company":"mad4b-central","environment":"staging",
                       "site_uuid":p.EXPECTED_SITE,"origin":p.EXPECTED_ORIGIN}}
        self.profile.write_text(json.dumps(self.prof))
        self.profile.chmod(0o600)
        self.trust=self.root/"public.php"
        self.trust.write_text("<?php // verifier fixture")
        self.trust.chmod(0o600)
        self.hold=self.root/"hold.json"
        self.hold.write_text(json.dumps({
          "state":"AMBIGUOUS_SIGNED_WRITE_HOLD",
          "reason_code":"ISSUER_SIGNED_RESULT_UNCONFIRMED",
          "automatic_retry_allowed":False}))
        self.hold.chmod(0o600)
        patches=(
          mock.patch.object(p,"INDEX",self.index),
          mock.patch.object(p,"REGISTRY",self.reg),
          mock.patch.object(p,"MANIFEST",self.manifest),
          mock.patch.object(p,"PROFILE",self.profile),
          mock.patch.object(p,"HOLD",self.hold),
          mock.patch.object(p,"ROOT_TRUST",self.trust),
          mock.patch.object(p,"MU_PUBLIC_SHA",hashlib.sha256(self.trust.read_bytes()).hexdigest()),
          mock.patch.object(p.os,"geteuid",return_value=0),
          # CI may run as a non-root user. These fixture files represent
          # independently trusted Root files; production still enforces
          # UID=0, mode=0600 and the original index/manifest checks.
          mock.patch.object(p,"check_root_file",side_effect=lambda path:path.read_bytes()),
        )
        for patch in patches:patch.start();self.addCleanup(patch.stop)

    def test_real_registered_v8_manifest_verified_without_v3_approval(self):
        result=p.status()
        self.assertEqual(result["state"],"HOLD_MISSING_OWNER_V3_SIGNED_IDENTITY")
        self.assertTrue(result["original305_v8_manifest_pin_verified"])
        self.assertTrue(result["original305_legacy_ambiguous_write_hold_preserved"])
        self.assertEqual(result["original305_legacy_hold_reason"],"ISSUER_SIGNED_RESULT_UNCONFIRMED")
        self.assertFalse(result["approved_write"])
        self.assertFalse(result["automatic_signature_attempted"])

    def test_manifest_index_tamper_denied(self):
        self.payload["providers"][0]["sha256"]="a"*64
        self.index.write_text(json.dumps(self.payload))
        with self.assertRaisesRegex(p.ReadinessHold,"ORIGINAL305_BROKER_INDEX_DRIFT"):
            p.status()

    def test_unsigned_owner_grant_never_inferred_from_root_profile(self):
        self.prof["allowed_operations"]=["site.code.current.read","site.code.atomic.install"]
        self.profile.write_text(json.dumps(self.prof))
        with self.assertRaisesRegex(p.ReadinessHold,"SITE_BINDING_READONLY_PROFILE_CHANGED"):
            p.status()

    def test_distinct_312_313_key_required(self):
        self.payload["independent_verifier_public_key_b64"]=self.payload["host_anchor_312_public_key_b64"]
        self.index.write_text(json.dumps(self.payload))
        with self.assertRaisesRegex(p.ReadinessHold,"ORIGINAL312_313_PIN_INVALID"):
            p.status()

    def test_mutated_original305_hold_marker_denied(self):
        self.hold.write_text(json.dumps({
            "state":"RESOLVED","automatic_retry_allowed":True}))
        with self.assertRaisesRegex(p.ReadinessHold,"ORIGINAL305_HOLD_STATE_UNEXPECTED"):
            p.status()

    def test_missing_owner_hold_requires_separate_incident_review(self):
        self.hold.unlink()
        with self.assertRaisesRegex(p.ReadinessHold,"ORIGINAL305_HOLD_MARKER_CHANGED_REVIEW_REQUIRED"):
            p.status()

    def test_missing_root_public_anchor_denied(self):
        self.trust.unlink()
        with self.assertRaises(FileNotFoundError):
            p.status()

    def test_wrong_site_or_origin_denied(self):
        self.prof["live_current_binding"]["site_uuid"]="f"*8+"-ffff-4fff-8fff-"+"f"*12
        self.profile.write_text(json.dumps(self.prof))
        with self.assertRaisesRegex(p.ReadinessHold,"SITE_BINDING_READONLY_PROFILE_CHANGED"):
            p.status()

    def test_manifest_claim_for_wrong_operation_denied(self):
        (self.reg/self.manifest).write_text(json.dumps({"version":8,"operation_id":"other"}))
        self.payload["providers"][0]["sha256"]=hashlib.sha256((self.reg/self.manifest).read_bytes()).hexdigest()
        self.index.write_text(json.dumps(self.payload))
        with self.assertRaisesRegex(p.ReadinessHold,"ORIGINAL305_BROKER_MANIFEST_INVALID"):
            p.status()
if __name__=="__main__":unittest.main()
