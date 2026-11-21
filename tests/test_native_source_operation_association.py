"""Temporary, independently signed operation association: no worker execution."""
from __future__ import annotations

import base64
from copy import deepcopy
from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import os
import sys
import tempfile
import unittest

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "ops/native-source-publisher-v1/operation_plan_association.py"
spec = importlib.util.spec_from_file_location("mad4b_operation_association_offline", SRC)
assoc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(assoc)
import test_native_source_publisher_v1 as fixtures
lib = fixtures.lib


class AssociationContract(unittest.TestCase):
    def setUp(self):
        self.source = fixtures.SourcePublisher("test_two_operators_two_sites_one_engine")
        self.source.setUp()
        self.addCleanup(self.source.doCleanups)
        self.key = Ed25519PrivateKey.generate()
        self.public = base64.b64encode(self.key.public_key().public_bytes(
            Encoding.Raw, PublicFormat.Raw)).decode()
        self.other_public = base64.b64encode(
            Ed25519PrivateKey.generate().public_key().public_bytes(
                Encoding.Raw, PublicFormat.Raw)).decode()
        self.core_plan = lib.core_plan(self.source.plan1)
        self.manifest = self.source.manifest1
        self.now = 1794480000
        self.record = self.make_association(
            self.core_plan, self.manifest, self.now
        )
        self.temp = tempfile.TemporaryDirectory(prefix="mad4b-plan-association-")
        self.addCleanup(self.temp.cleanup)
        self.store = Path(self.temp.name) / "trusted"
        self.store.mkdir(mode=0o700)

    def make_association(self, plan, manifest, now, **changes):
        row = {
            "contract": assoc.CONTRACT,
            "issuer": "mad4b-first-party-plan-association",
            "nonce": "a" * 64,
            "plan_sha256": plan["plan_sha256"],
            "operation_id": manifest["operation_id"],
            "manifest_sha256": assoc.digest(assoc.canonical(manifest)),
            "provider": manifest["provider"],
            "approval_ability": manifest["approval_ability"],
            "company": plan["company"],
            "environment": plan["environment"],
            "site_uuid": plan["site_uuid"],
            "origin": plan["origin"],
            "issued_epoch": now - 10,
            "expires_epoch": now + 240,
        }
        row.update(changes)
        row["signature"] = base64.b64encode(
            self.key.sign(assoc.signed_payload(row))
        ).decode()
        return row

    def persist(self, record=None):
        path = self.store / (self.core_plan["plan_sha256"] + ".json")
        path.write_text(json.dumps(record or self.record))
        path.chmod(0o600)
        return path

    def test_two_providers_one_core_plan_selects_exactly_signed_operation(self):
        self.assertEqual(len(self.source.registry.manifests), 2)
        self.persist()
        result = assoc.exclusive_operation(
            self.core_plan, self.source.registry, self.store, self.public,
            now=self.now,
        )
        self.assertEqual(result["operation_id"], self.manifest["operation_id"])
        self.assertEqual(result["plan_sha256"], self.core_plan["plan_sha256"])
        self.assertFalse(result["authority_issued"])
        self.assertFalse(result["job_claimed"])
        self.assertFalse(result["original313_signed_receipt_issued"])

    def test_original_cas_plan_remains_valid_without_embedded_binding(self):
        self.assertNotIn("source_binding", self.core_plan)
        lib.core.confirm_plan(
            self.core_plan, lib.company_policy(self.source.policy),
            expect_preimage=True,
        )
        original_admission = Path(
            "/opt/mad4b-company-transport/native-313-independent/"
            "dynamic-operation-registry-v1/enrolled/admission.py"
        )
        self.assertTrue(original_admission.is_file())

    def test_installed_original_plan_validator_accepts_plain_plan(self):
        original_registry_root = Path(
            "/opt/mad4b-company-transport/native-313-independent/"
            "dynamic-operation-registry-v1/enrolled"
        )
        sys.path.insert(0, str(original_registry_root))
        import admission
        self.persist()
        with tempfile.TemporaryDirectory(prefix="mad4b-original-plan-plain-") as temp:
            plans = Path(temp)
            plans.chmod(0o700)
            file = plans / (self.core_plan["plan_sha256"] + ".json")
            file.write_text(json.dumps(self.core_plan, sort_keys=True))
            file.chmod(0o600)
            observed = admission.checked_plan(
                plans, self.core_plan["plan_sha256"], self.manifest
            )
            self.assertEqual(observed["plan_sha256"], self.core_plan["plan_sha256"])
            verified = assoc.exclusive_operation(
                observed, self.source.registry, self.store,
                self.public, now=self.now
            )
            self.assertEqual(verified["operation_id"], self.manifest["operation_id"])
            self.assertFalse(verified["authority_issued"])

    def test_tampered_operation_or_provider_is_rejected(self):
        for field, value in (
            ("operation_id", self.source.manifest2["operation_id"]),
            ("provider", self.source.manifest2["provider"]),
            ("approval_ability", self.source.manifest2["approval_ability"]),
            ("manifest_sha256", "b" * 64),
            ("company", "wrong-company"),
            ("site_uuid", "deadbeef-0000-4000-8000-000000000000"),
        ):
            with self.subTest(field=field):
                mutated = deepcopy(self.record)
                mutated[field] = value
                with self.assertRaises(assoc.AssociationDenied):
                    assoc.verify_association(
                        self.core_plan, self.manifest, mutated,
                        self.public, now=self.now,
                    )

    def test_re_signing_for_another_manifest_without_scope_approval_is_not_inferred(self):
        # Independent authorization cannot be derived from a plan alone.
        with self.assertRaises(assoc.AssociationDenied):
            assoc.verify_association(
                self.core_plan, self.source.manifest2, self.record,
                self.public, now=self.now,
            )

    def test_untrusted_signer_cannot_select_operation(self):
        with self.assertRaises(assoc.AssociationDenied):
            assoc.verify_association(
                self.core_plan, self.manifest, self.record,
                self.other_public, now=self.now,
            )
        malformed = deepcopy(self.record)
        malformed["signature"] = "AQ=="
        with self.assertRaises(assoc.AssociationDenied):
            assoc.verify_association(
                self.core_plan, self.manifest, malformed,
                self.public, now=self.now,
            )

    def test_expiration_future_and_replay_window_denied(self):
        for args, clock in (
            ({"expires_epoch": self.now - 1}, self.now),
            ({"issued_epoch": self.now + 20}, self.now),
            ({"expires_epoch": self.now + 901}, self.now),
            ({}, self.now + 600),
        ):
            with self.subTest(args=args, clock=clock):
                row = self.make_association(self.core_plan, self.manifest,
                                            self.now, **args)
                with self.assertRaises(assoc.AssociationDenied):
                    assoc.verify_association(
                        self.core_plan, self.manifest, row,
                        self.public, now=clock,
                    )

    def test_signed_expiry_is_distinct_from_malformed_timing(self):
        verified_expired = self.make_association(
            self.core_plan, self.manifest, self.now-1200)
        with self.assertRaisesRegex(assoc.AssociationDenied,
                                    "ASSOCIATION_EXPIRED"):
            assoc.verify_association(self.core_plan,self.manifest,
                                      verified_expired,self.public,now=self.now)
        invalid = self.make_association(
            self.core_plan,self.manifest,self.now,
            expires_epoch=self.now-11)
        with self.assertRaisesRegex(assoc.AssociationDenied,
                                    "ASSOCIATION_TIME_INVALID"):
            assoc.verify_association(self.core_plan,self.manifest,
                                      invalid,self.public,now=self.now)

    def test_tampered_expiry_does_not_hide_invalid_signature(self):
        forged=deepcopy(self.record)
        forged["expires_epoch"]=self.now-1
        with self.assertRaisesRegex(assoc.AssociationDenied,
                                    "ASSOCIATION_INDEPENDENT_SIGNATURE_INVALID"):
            assoc.verify_association(self.core_plan,self.manifest,
                                      forged,self.public,now=self.now)

    def test_no_signed_association_denied(self):
        with self.assertRaises(assoc.AssociationDenied):
            assoc.exclusive_operation(
                self.core_plan, self.source.registry, self.store,
                self.public, now=self.now,
            )

    def test_untrusted_store_permissions_denied(self):
        path = self.persist()
        self.store.chmod(0o777)
        with self.assertRaises(assoc.AssociationDenied):
            assoc.protected_association(self.store, self.core_plan["plan_sha256"])
        self.store.chmod(0o700)
        path.chmod(0o666)
        with self.assertRaises(assoc.AssociationDenied):
            assoc.protected_association(self.store, self.core_plan["plan_sha256"])
        path.chmod(0o600)
        self.assertEqual(
            assoc.protected_association(self.store, self.core_plan["plan_sha256"]),
            self.record,
        )

    def test_symbolic_link_store_entry_denied(self):
        self.persist().unlink()
        real_file = self.store / "outside.json"
        real_file.write_text(json.dumps(self.record))
        (self.store / (self.core_plan["plan_sha256"] + ".json")).symlink_to(real_file)
        with self.assertRaises(assoc.AssociationDenied):
            assoc.protected_association(self.store, self.core_plan["plan_sha256"])

    def test_extra_field_and_mutated_core_plan_denied(self):
        bad = deepcopy(self.record)
        bad["approved"] = True
        with self.assertRaises(assoc.AssociationDenied):
            assoc.verify_association(
                self.core_plan, self.manifest, bad, self.public, now=self.now,
            )
        tampered = deepcopy(self.core_plan)
        tampered["origin"] = "https://untrusted.invalid"
        with self.assertRaises(assoc.AssociationDenied):
            assoc.verify_association(
                tampered, self.manifest, self.record, self.public, now=self.now,
            )
        with self.assertRaises(assoc.AssociationDenied):
            assoc.verify_association(
                self.source.plan1, self.manifest,
                self.record, self.public, now=self.now,
            )


if __name__ == "__main__":
    unittest.main()
