"""Ed25519 v128 host-mode tests. Ephemeral signing keys, no site secrets."""
import base64
import copy
import importlib.util
from pathlib import Path
import time
import unittest

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

SOURCE = (Path(__file__).resolve().parents[2] / "ops" /
          "host-authority-v128" / "persistent_enrollment.py")
spec = importlib.util.spec_from_file_location("persistent_enrollment_v128", SOURCE)
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)
NOW = 1_800_000_000
HEX = "1" * 32
SITE = "11111111-1111-4111-8111-111111111111"

class PersistentHostContractTests(unittest.TestCase):
    def setUp(self):
        self.host_key = Ed25519PrivateKey.generate()
        self.highwater_key = Ed25519PrivateKey.generate()
        self.grant_key = Ed25519PrivateKey.generate()
        self.activity_key = Ed25519PrivateKey.generate()
        self.hp, self.cp, self.gp, self.ap = [
            k.public_key().public_bytes(
                encoding=serialization.Encoding.Raw,
                format=serialization.PublicFormat.Raw)
            for k in (self.host_key, self.highwater_key, self.grant_key,
                      self.activity_key)]
        self.enrollment = self.sign({
            "contract": v.ENROLLMENT_CONTRACT,
            "company": "all-royal-egypt", "environment": "staging",
            "site_uuid": SITE, "target_host_id": "hosting-2",
            "ssh_principal": "u123456789",
            "host_key_sha256": "a"*64, "policy_sha256": "b"*64,
            "revision": 2, "nonce": HEX,
            "issued_epoch": NOW - 100000, "mode": v.DEFAULT_CONNECTION_MODE,
            "expires_epoch": None, "state": "ACTIVE",
        }, v.ENROLLMENT_FIELDS, self.host_key)
        self.grant = self.sign({
            "contract": v.GRANT_CONTRACT,
            "company": self.enrollment["company"],
            "environment": self.enrollment["environment"],
            "site_uuid": SITE, "revision": 2, "operation_id": "2"*32,
            "action": "company.code.release", "grant_nonce": "3"*32,
            "approved_epoch": NOW-200, "idle_limit_seconds": 120,
        }, v.GRANT_FIELDS, self.grant_key)
        self.activity = self.sign({
            "contract": v.ACTIVITY_CONTRACT,
            "operation_id": self.grant["operation_id"],
            "grant_sha256": v.digest(self.grant,v.GRANT_FIELDS,v.GRANT_CONTRACT),
            "seq": 2, "state": "RUNNING",
            "last_heartbeat_epoch": NOW-2,
        }, v.ACTIVITY_FIELDS, self.activity_key)
        self.update_checkpoint()

    @staticmethod
    def sign(body, fields, key):
        b = copy.deepcopy(body)
        b["signature"] = base64.b64encode(
            key.sign(v.canonical(b, fields, b["contract"]))).decode()
        return b

    def update_checkpoint(self, *, state="ACTIVE", observed=NOW):
        self.checkpoint = self.sign({
            "contract": v.CHECKPOINT_CONTRACT,
            "company": self.enrollment["company"],
            "environment": self.enrollment["environment"],
            "site_uuid": self.enrollment["site_uuid"],
            "revision": self.enrollment["revision"],
            "enrollment_sha256": v.digest(
                self.enrollment, v.ENROLLMENT_FIELDS, v.ENROLLMENT_CONTRACT),
            "state": state,
            "operation_id": self.grant["operation_id"],
            "activity_sha256": v.digest(
                self.activity, v.ACTIVITY_FIELDS, v.ACTIVITY_CONTRACT),
            "activity_seq": self.activity["seq"], "observed_epoch": observed,
        }, v.CHECKPOINT_FIELDS, self.highwater_key)
        self.wp = {
            "contract": v.READBACK_CONTRACT, "source": "original318_current_wp_readback",
            "readback_verified": True, "signature_verified": True,
            "host_key_sha256": self.enrollment["host_key_sha256"],
            "revision": self.enrollment["revision"],
            "enrollment_sha256": self.checkpoint["enrollment_sha256"],
            "state": state, "operation_id": self.checkpoint["operation_id"],
            "activity_sha256": self.checkpoint["activity_sha256"],
            "activity_seq": self.checkpoint["activity_seq"],
            "write_authorized": False,
        }

    def connection(self, *, now=NOW):
        return v.verify_connection(self.enrollment, self.checkpoint, self.wp,
                                   self.hp, self.cp, now=now)

    def operation(self, *, now=NOW, action="company.code.release"):
        return v.verify_operation(
            self.enrollment, self.checkpoint, self.wp,
            self.grant, self.activity, self.hp, self.cp, self.gp, self.ap,
            action, now=now)

    def denied(self, call, reason):
        with self.assertRaises(v.ConnectionDenied) as e:
            call()
        self.assertEqual(str(e.exception), reason)

    def test_default_mode_is_persistent(self):
        self.assertEqual(v.DEFAULT_CONNECTION_MODE, "persistent")
        r = self.connection()
        self.assertEqual(r["state"], "PERSISTENT_CONNECTED")
        self.assertIsNone(r["enrollment_expires_epoch"])
        self.assertFalse(r["host_write_authorized"])

    def test_new_enrollment_factory_defaults_persistent(self):
        proposal = v.prepare_new_enrollment(
            company="all-royal-egypt", environment="staging", site_uuid=SITE,
            target_host_id="hosting-2", ssh_principal="u123456789",
            host_key_sha256="a"*64, policy_sha256="b"*64,
            revision=2, nonce=HEX, issued_epoch=NOW)
        self.assertEqual(proposal["mode"], "persistent")
        self.assertIsNone(proposal["expires_epoch"])
        self.assertNotIn("signature", proposal)

    def test_new_enrollment_factory_scoped_explicit(self):
        proposal = v.prepare_new_enrollment(
            company="all-royal-egypt", environment="staging", site_uuid=SITE,
            target_host_id="hosting-2", ssh_principal="u123456789",
            host_key_sha256="a"*64, policy_sha256="b"*64,
            revision=2, nonce=HEX, issued_epoch=NOW,
            mode="operation_scoped")
        self.assertEqual(proposal["mode"], "operation_scoped")
        self.assertIsNone(proposal["expires_epoch"])

    def test_reject_unknown_mode_in_factory(self):
        self.denied(lambda:v.prepare_new_enrollment(
            company="all-royal-egypt", environment="staging", site_uuid=SITE,
            target_host_id="hosting-2", ssh_principal="u123456789",
            host_key_sha256="a"*64, policy_sha256="b"*64,
            revision=2, nonce=HEX, issued_epoch=NOW,
            mode="never_check_anything"), "CONNECTION_MODE_INVALID")

    def test_connected_years_later_with_fresh_independent_readback(self):
        later = NOW + 365 * 86400 * 20
        self.update_checkpoint(observed=later)
        r = self.connection(now=later)
        self.assertTrue(r["connection_verified"])
        self.assertIsNone(r["enrollment_expires_epoch"])

    def test_scoped_mode_must_have_operation(self):
        self.enrollment["mode"] = v.LEGACY_COMPATIBLE_MODE
        self.enrollment = self.sign({k: self.enrollment[k]
                                     for k in v.ENROLLMENT_FIELDS},
                                    v.ENROLLMENT_FIELDS, self.host_key)
        self.update_checkpoint()
        self.assertEqual(self.connection()["mode"], "operation_scoped")
        self.assertFalse(self.connection()["connection_verified"])
        self.assertTrue(self.operation()["connection_verified"])

    def test_persistent_connection_without_running_job(self):
        self.checkpoint = self.sign(
            dict({k:self.checkpoint[k] for k in v.CHECKPOINT_FIELDS},
                 operation_id=None, activity_sha256=None, activity_seq=None),
            v.CHECKPOINT_FIELDS, self.highwater_key)
        self.wp["operation_id"] = None
        self.wp["activity_sha256"] = None
        self.wp["activity_seq"] = None
        self.assertTrue(self.connection()["connection_verified"])

    def test_scoped_enrollment_without_running_job_is_not_connected(self):
        self.enrollment["mode"] = "operation_scoped"
        self.enrollment = self.sign(
            {k:self.enrollment[k] for k in v.ENROLLMENT_FIELDS},
            v.ENROLLMENT_FIELDS,self.host_key)
        self.update_checkpoint()
        self.checkpoint = self.sign(
            dict({k:self.checkpoint[k] for k in v.CHECKPOINT_FIELDS},
                 operation_id=None, activity_sha256=None, activity_seq=None),
            v.CHECKPOINT_FIELDS,self.highwater_key)
        self.wp["enrollment_sha256"] = self.checkpoint["enrollment_sha256"]
        self.wp["operation_id"] = None
        self.wp["activity_sha256"] = None
        self.wp["activity_seq"] = None
        self.denied(self.connection,"SCOPED_CONNECTION_REQUIRES_OPERATION")

    def test_expired_v2_cannot_silently_upgrade(self):
        self.enrollment["contract"] = "mad4b.company-host-authority-anchor.v2"
        self.denied(self.connection, "SIGNED_CONTRACT_INVALID")

    def test_persistent_mode_rejects_any_expiry_timestamp(self):
        self.enrollment["expires_epoch"] = NOW+100
        self.enrollment = self.sign({k: self.enrollment[k]
                                     for k in v.ENROLLMENT_FIELDS},
                                    v.ENROLLMENT_FIELDS, self.host_key)
        self.update_checkpoint()
        self.denied(self.connection, "PERSISTENT_MODE_MUST_NOT_USE_EXPIRY")

    def test_tampered_enrollment_rejected(self):
        self.enrollment["host_key_sha256"] = "f"*64
        self.denied(self.connection, "SIGNED_SIGNATURE_INVALID")

    def test_tampered_highwater_rejected(self):
        self.checkpoint["revision"] = 3
        self.denied(self.connection, "SIGNED_SIGNATURE_INVALID")

    def test_forged_signature_rejected(self):
        self.enrollment["signature"] = base64.b64encode(b"x"*64).decode()
        self.denied(self.connection, "SIGNED_SIGNATURE_INVALID")

    def test_revoked_highwater_deny(self):
        self.update_checkpoint(state="REVOKED")
        self.denied(self.connection, "HOST_REVOKED_OR_DISABLED")

    def test_enrollment_revocation_deny(self):
        self.enrollment["state"] = "REVOKED"
        self.enrollment = self.sign({k:self.enrollment[k]
                                     for k in v.ENROLLMENT_FIELDS},
                                    v.ENROLLMENT_FIELDS,self.host_key)
        self.update_checkpoint()
        self.denied(self.connection, "ENROLLMENT_NOT_ACTIVE")

    def test_stale_independent_checkpoint_deny(self):
        self.update_checkpoint(observed=NOW-40)
        self.denied(self.connection, "HIGHWATER_READBACK_STALE")

    def test_wrong_wp_host_key_denied(self):
        self.wp["host_key_sha256"] = "f"*64
        self.denied(self.connection,
                    "ORIGINAL318_READBACK_NOT_VERIFIED_OR_DRIFTED")

    def test_unverified_wp_denied(self):
        self.wp["readback_verified"] = False
        self.denied(self.connection,
                    "ORIGINAL318_READBACK_NOT_VERIFIED_OR_DRIFTED")

    def test_write_eligible_wp_cannot_be_connection_proof(self):
        self.wp["write_authorized"] = True
        self.denied(self.connection,
                    "ORIGINAL318_READBACK_NOT_VERIFIED_OR_DRIFTED")

    def test_independent_signers_required(self):
        self.denied(lambda:v.verify_connection(
            self.enrollment,self.checkpoint,self.wp,self.hp,self.hp,now=NOW),
            "INDEPENDENT_SIGNERS_REQUIRED")

    def test_live_operation_is_valid_without_wallclock_deadline(self):
        r = self.operation()
        self.assertEqual(r["state"], "EXACT_OPERATION_ADMISSION_VERIFIED")
        self.assertIsNone(r["fixed_end_epoch"])
        self.assertFalse(r["broad_host_write_authorized"])

    def test_operation_can_run_for_days_with_current_signed_heartbeat(self):
        later = NOW + 30*86400
        self.activity["last_heartbeat_epoch"] = later-1
        self.activity["seq"] += 1
        self.activity = self.sign(
            {k:self.activity[k] for k in v.ACTIVITY_FIELDS},
            v.ACTIVITY_FIELDS,self.activity_key)
        self.update_checkpoint(observed=later)
        self.assertEqual(self.operation(now=later)["operation_seq"],3)

    def test_finished_operation_is_immediately_ineligible(self):
        self.activity["state"] = "COMPLETED"
        self.activity = self.sign(
            {k:self.activity[k] for k in v.ACTIVITY_FIELDS},
            v.ACTIVITY_FIELDS,self.activity_key)
        self.update_checkpoint()
        self.denied(self.operation, "OPERATION_TERMINAL_OR_NOT_RUNNING")

    def test_failed_cancelled_revoked_operations_denied(self):
        for state in ("FAILED","CANCELLED","REVOKED"):
            self.setUp()
            self.activity["state"] = state
            self.activity = self.sign(
                {k:self.activity[k] for k in v.ACTIVITY_FIELDS},
                v.ACTIVITY_FIELDS,self.activity_key)
            self.update_checkpoint()
            self.denied(self.operation, "OPERATION_TERMINAL_OR_NOT_RUNNING")

    def test_operation_hang_rejected_not_fixed_total_timer(self):
        self.activity["last_heartbeat_epoch"] = NOW-130
        self.activity = self.sign(
            {k:self.activity[k] for k in v.ACTIVITY_FIELDS},
            v.ACTIVITY_FIELDS,self.activity_key)
        self.update_checkpoint()
        self.denied(self.operation, "OPERATION_HEARTBEAT_LOST")

    def test_mismatched_scope_denied(self):
        self.denied(lambda:self.operation(action="company.host-authority.anchor.issue"),
                    "OPERATION_ACTION_NOT_IN_GRANT")

    def test_wrong_site_denied(self):
        self.grant["site_uuid"] = "00000000-0000-4000-8000-000000000000"
        self.grant = self.sign(
            {k:self.grant[k] for k in v.GRANT_FIELDS},
            v.GRANT_FIELDS,self.grant_key)
        self.denied(self.operation, "OPERATION_GRANT_SCOPE_DRIFT")

    def test_replayed_operation_state_rejected_by_highwater(self):
        newer = self.sign(
            dict({k:self.activity[k] for k in v.ACTIVITY_FIELDS},
                 seq=3, state="COMPLETED"),
            v.ACTIVITY_FIELDS,self.activity_key)
        self.activity = newer
        self.update_checkpoint()
        self.activity = self.sign(
            dict({k:self.activity[k] for k in v.ACTIVITY_FIELDS},
                 seq=2, state="RUNNING"),
            v.ACTIVITY_FIELDS,self.activity_key)
        self.denied(self.operation, "LATEST_OPERATION_HIGHWATER_MISMATCH")

    def test_host_key_rotation_invalidates_old_enrollment(self):
        self.wp["host_key_sha256"] = "b"*64
        self.denied(self.operation,
                    "ORIGINAL318_READBACK_NOT_VERIFIED_OR_DRIFTED")

    def test_old_grant_revision_invalid_after_rotation(self):
        self.enrollment["revision"] = 3
        self.enrollment = self.sign(
            {k:self.enrollment[k] for k in v.ENROLLMENT_FIELDS},
            v.ENROLLMENT_FIELDS,self.host_key)
        self.update_checkpoint()
        self.denied(self.operation,"OPERATION_GRANT_SCOPE_DRIFT")

    def test_idle_limit_is_bounded_and_signed(self):
        self.grant["idle_limit_seconds"] = 999999
        self.grant = self.sign({k:self.grant[k] for k in v.GRANT_FIELDS},
                               v.GRANT_FIELDS,self.grant_key)
        self.denied(self.operation, "OPERATION_IDLE_POLICY_INVALID")

    def test_cross_signed_state_forgery_denied(self):
        self.activity["state"] = "RUNNING"
        self.activity["signature"] = base64.b64encode(
            b"invalid-signature".ljust(64,b"0")).decode()
        self.denied(self.operation,"SIGNED_SIGNATURE_INVALID")

    def test_no_artificial_root_authorization(self):
        r=self.operation()
        self.assertNotIn("write_authorized",r)
        self.assertFalse(r["execution_performed"])

if __name__ == "__main__":
    unittest.main()
