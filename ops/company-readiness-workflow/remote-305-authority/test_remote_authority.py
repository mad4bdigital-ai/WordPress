#!/usr/bin/env python3
"""Hermetic remote-authorization regression tests. No DB or live 305 writes."""
import base64
import copy
from pathlib import Path
import tempfile
import time
import unittest

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
import remote_authority as m


class RemoteTests(unittest.TestCase):
    def setUp(self):
        self.signer = Ed25519PrivateKey.generate()
        self.public = self.signer.public_key().public_bytes_raw()
        self.now = int(time.time())
        self.uuid = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"
        self.origin = "https://staging.example.com"
        self.base = {"contract": m.CONTRACT, "action": "preflight",
                     "host_id": m.HOST, "company": "example-company",
                     "environment": "staging", "site_uuid": self.uuid,
                     "canonical_origin": self.origin, "issued": self.now,
                     "expires": self.now + 40, "nonce": "a" * 32}
        self.item = {"target": "example-company:staging",
                     "site_uuid": self.uuid, "canonical_origin": self.origin,
                     "site_identity_registered": True,
                     "verified_host_scope_id": m.HOST,
                     "full_host_scope_observed": True,
                     "original_305_active_pair_observed": False}
        self.report = {"contract": "mad4b.full-host-authority-live-reconciliation.v1",
                       "grants_created": False, "operations_executed": False,
                       "production_mutated": False, "targets": [self.item]}
        self.calls = []
        self.temp = tempfile.TemporaryDirectory()
        self.ledger = Path(self.temp.name) / "ledger.json"

    def tearDown(self):
        self.temp.cleanup()

    def signed(self, **edits):
        row = dict(self.base, **edits)
        row["signature"] = base64.b64encode(self.signer.sign(m.wire_bytes(row))).decode()
        return row

    def execute(self, row, native=None, refresh=None):
        def default(action, company, environment):
            self.calls.append((action, company, environment))
            return True, ("ORIGINAL_NATIVE_SIGNED_ENROLLMENT_ADMISSIBLE" if action == "plan"
                          else "ORIGINAL_305_PERSISTENT_PAIR_ENROLLED"), {"grant_issued": True}
        return m.process(row, self.public, self.report, native=native or default,
                         now=self.now, ledger=self.ledger, refresh=refresh)

    def denied(self, field, value, reason):
        with self.assertRaisesRegex(m.Denied, reason):
            self.execute(self.signed(**{field: value}))

    def test_valid_signed_preflight_no_grant(self):
        v = self.execute(self.signed())
        self.assertEqual(v["state"], "ORIGINAL_305_PLAN_ADMISSIBLE")
        self.assertFalse(v["grant_issued"])
        self.assertFalse(v["execution_performed"])
        self.assertFalse(v["production_mutated"])
        self.assertFalse(v["original_manager_authority_inferred_from_ssh"])
        self.assertEqual(self.calls, [("plan", "example-company", "staging")])

    def test_block_remote_without_exact_scope(self):
        self.item["verified_host_scope_id"] = None
        v = self.execute(self.signed())
        self.assertEqual(v["blockers"], ["EXACT_HOST_SCOPE_AND_BINDING_PENDING"])
        self.assertFalse(self.calls)

    def test_partial_host_scope(self):
        self.item["full_host_scope_observed"] = False
        v = self.execute(self.signed())
        self.assertIn("FULL_SHELL_SQL_AND_HOST_SCOPE_PENDING", v["blockers"])
        self.assertFalse(self.calls)

    def test_manager_denied_is_not_granted(self):
        def missing(*args):
            return False, "ENROLLED_WORDPRESS_COMPANY_MANAGER_NOT_VERIFIED", {}
        v = self.execute(self.signed(), native=missing)
        self.assertEqual(v["state"], "BLOCKED")
        self.assertIn("ENROLLED_WORDPRESS_COMPANY_MANAGER_NOT_VERIFIED", v["blockers"])

    def test_original_pair_no_double_issue(self):
        self.item["original_305_active_pair_observed"] = True
        v = self.execute(self.signed(action="enroll305"))
        self.assertEqual(v["state"], "ALREADY_ENROLLED_ORIGINAL_305_READBACK")
        self.assertFalse(self.calls)

    def test_enrollment_confirmed_only_after_independent_source(self):
        fresh = copy.deepcopy(self.report)
        fresh["targets"][0]["original_305_active_pair_observed"] = True
        v = self.execute(self.signed(action="enroll305"), refresh=lambda:fresh)
        self.assertEqual(v["state"], "ORIGINAL_305_CONFIRMED")
        self.assertTrue(v["grant_issued"])
        self.assertEqual([x[0] for x in self.calls], ["plan","enroll"])

    def test_success_receipt_with_missing_live_pair_is_not_success(self):
        v = self.execute(self.signed(action="enroll305"),refresh=lambda:self.report)
        self.assertEqual(v["state"], "ORIGINAL_305_READBACK_NOT_CONFIRMED")
        self.assertFalse(v["grant_issued"])

    def test_uncertain_original_response_no_retry(self):
        def native(action,company,env):
            if action == "plan":
                return True,"ORIGINAL_NATIVE_SIGNED_ENROLLMENT_ADMISSIBLE",{}
            return False, "ORIGINAL_305_RECEIPT_INVALID",{}
        v = self.execute(self.signed(action="enroll305"), native=native,refresh=lambda:self.report)
        self.assertEqual(v["state"], "ORIGINAL_305_OUTCOME_UNCERTAIN")
        with self.assertRaisesRegex(m.Denied,"SIGNED_REQUEST_REPLAY"):
            self.execute(self.signed(action="enroll305"), native=native,refresh=lambda:self.report)

    def test_wrong_broker_host_denied(self):
        self.denied("host_id", "other-host", "REQUEST_HOST_OR_ACTION_INVALID")

    def test_cross_company_denied(self):
        with self.assertRaisesRegex(m.Denied,"TARGET_NOT_UNIQUELY_REGISTERED"):
            self.execute(self.signed(company="other-company"))

    def test_wrong_uuid_denied(self):
        self.denied("site_uuid", "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb", "SITE_BINDING_MISMATCH")

    def test_origin_mismatch_denied(self):
        self.denied("canonical_origin", "https://other.example.com", "SITE_BINDING_MISMATCH")

    def test_production_denied(self):
        self.denied("environment", "production", "PRODUCTION_ENROLLMENT_NOT_ALLOWED")

    def test_expired_denied(self):
        self.denied("issued", self.now-120, "REQUEST_EXPIRED_OR_NOT_YET_VALID")

    def test_long_lived_denied(self):
        self.denied("expires", self.now+600, "REQUEST_EXPIRED_OR_NOT_YET_VALID")

    def test_invalid_signature_denied(self):
        r = self.signed()
        r["nonce"] = "b"*32
        with self.assertRaisesRegex(m.Denied,"BROKER_SIGNATURE_INVALID"):
            self.execute(r)

    def test_client_public_key_must_be_pinned(self):
        other = Ed25519PrivateKey.generate().public_key().public_bytes_raw()
        with self.assertRaisesRegex(m.Denied,"BROKER_SIGNATURE_INVALID"):
            m.check_request(self.signed(), other,self.now)

    def test_unknown_extra_fields_denied(self):
        r = self.signed()
        r["shell"] = "id"
        with self.assertRaisesRegex(m.Denied,"REQUEST_CONTRACT_INVALID"):
            self.execute(r)

    def test_signed_bad_action_denied(self):
        self.denied("action", "shell", "REQUEST_HOST_OR_ACTION_INVALID")

    def test_duplicate_site_denied(self):
        self.report["targets"].append(copy.deepcopy(self.item))
        with self.assertRaisesRegex(m.Denied,"TARGET_NOT_UNIQUELY_REGISTERED"):
            self.execute(self.signed())

    def test_core_claims_cannot_be_authority(self):
        self.report["grants_created"] = True
        with self.assertRaisesRegex(m.Denied,"RECONCILIATION_UNTRUSTED"):
            self.execute(self.signed())

    def test_original_site_identity_required(self):
        self.item["site_identity_registered"]=False
        with self.assertRaisesRegex(m.Denied,"ORIGINAL_SITE_IDENTITY_NOT_REGISTERED"):
            self.execute(self.signed())

    def test_preflight_does_not_write_replay_cache(self):
        self.execute(self.signed())
        self.assertFalse(self.ledger.exists())

    def test_nonce_is_single_use_even_when_readback_uncertain(self):
        v = self.execute(self.signed(action="enroll305"),refresh=lambda:self.report)
        self.assertEqual(v["state"],"ORIGINAL_305_READBACK_NOT_CONFIRMED")
        with self.assertRaisesRegex(m.Denied,"SIGNED_REQUEST_REPLAY"):
            self.execute(self.signed(action="enroll305"),refresh=lambda:self.report)

    def test_modified_ledger_permissions_rejected(self):
        self.ledger.write_text("{}")
        self.ledger.chmod(0o644)
        with self.assertRaisesRegex(m.Denied,"REPLAY_LEDGER_NOT_ROOT_PROTECTED"):
            self.execute(self.signed(action="enroll305"),refresh=lambda:self.report)

    def test_manager_rejection_never_reserves_nonce(self):
        def missing(*args):
            return False, "MANAGER_NOT_VERIFIED", {}
        self.execute(self.signed(action="enroll305"), native=missing,refresh=lambda:self.report)
        self.assertFalse(self.ledger.exists())

    def test_enroll_needs_independent_refresh(self):
        with self.assertRaisesRegex(m.Denied,"INDEPENDENT_READBACK_REQUIRED"):
            self.execute(self.signed(action="enroll305"))
        self.assertFalse(self.ledger.exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)

