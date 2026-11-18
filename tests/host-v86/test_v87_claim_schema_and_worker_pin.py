"""Regression against actual first-party Original306 Claim, not a synthetic seal."""
import hashlib
import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[2]
src=ROOT/"ops/host-anchor-v87/upgrade_worker_classifier_pin.py"
spec=importlib.util.spec_from_file_location("host_v87_upgrade",src)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
AUDIT=Path('/opt/mad4b-company-provisioning/reports/host-v86-live-original306-20261118')


class Original306ClaimSchemaTests(unittest.TestCase):
    def test_claim_fields_exact(self):
        preclaim=(ROOT/"ops/host-anchor-v2/anchor_preclaim.py").read_text()
        v86=(ROOT/"ops/host-anchor-v86/original306_typed_claim.py").read_text()
        for value in ("claimed_epoch","production_mutation_performed",
                      "mad4b.central-vps-broker.v1","host_id","nonce"):
            self.assertIn(value,preclaim)
            self.assertIn(value,v86)
        self.assertNotIn('original_claim.get("command_sha256")',preclaim)
        self.assertNotIn('original_claim.get("command_sha256")',v86)

    def test_legacy_v86_preimage_can_migrate_by_exact_CAS(self):
        old=AUDIT/"worker-candidate-v86.py"
        if not old.is_file():
            self.skipTest("VPS installed exact Worker preimage not mounted")
        result=module.render(old.read_bytes())
        self.assertEqual(hashlib.sha256(result).hexdigest(),module.EXPECTED_SHA256)
        self.assertEqual(result.count(module.NEW_CLASSIFIER_SHA256.encode()),1)
        self.assertEqual(result.count(module.OLD_CLASSIFIER_SHA256.encode()),0)

    def test_changed_worker_cannot_migrate(self):
        with self.assertRaisesRegex(ValueError,"PREIMAGE_DRIFT"):
            module.render(b"changed")

    def test_only_one_static_pin_changed(self):
        old=AUDIT/"worker-candidate-v86.py"
        if not old.is_file():
            self.skipTest("VPS original Worker preimage not mounted")
        before=old.read_bytes()
        after=module.render(before)
        self.assertEqual(
            after, before.replace(module.OLD_CLASSIFIER_SHA256.encode(),
                                  module.NEW_CLASSIFIER_SHA256.encode(),1))


if __name__=="__main__":
    unittest.main()
