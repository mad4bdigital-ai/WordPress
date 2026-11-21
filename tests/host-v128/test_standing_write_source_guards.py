from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
SOURCE=(ROOT/"ops/host-authority-v128/standing_write.py").read_text()
FLEET=(ROOT/"ops/host-authority-v128/fleet_policy.py").read_text()
DOC=(ROOT/"docs/host-v128/persistent-standing-write-v130.md").read_text()
class StandingWriteSourceGuardTests(unittest.TestCase):
    def test_no_signing_keys_or_unapproved_executors(self):
        for token in ("from_private_bytes(","load_pem_private_key(",
                      "subprocess.run(", "os.system(", "wp option update",
                      "register_rest_route(", "update_option(", "paramiko."):
            with self.subTest(token=token):
                self.assertNotIn(token,SOURCE)
    def test_standing_alone_cannot_mutate(self):
        self.assertIn('"specific_write_authorized":False',SOURCE)
        self.assertIn('"broad_host_write_authorized":False',SOURCE)
        self.assertIn('"mutation_performed":False',SOURCE)
    def test_signed_operation_and_plan_remain_mandatory(self):
        for token in ("verify_operation(","PLAN_CONTRACT",
                      "PLAN_DOMAIN","STANDING_WRITE_VERIFIED_ATOMIC_PLAN_REQUIRED",
                      "STANDING_WRITE_REVOKED_OR_STALE",
                      "PRODUCTION_WRITE_REQUIRES_SEPARATE_APPROVAL",
                      "PRODUCTION_DOMAIN"):
            self.assertIn(token,SOURCE)
    def test_new_enrollments_default_write_without_issuance(self):
        self.assertIn('"standing_write_mode_default": standing["write_mode"]',FLEET)
        self.assertIn('"standing_write_owner305_signature_required": True',FLEET)
        self.assertIn("NOT an",DOC)
if __name__=="__main__":unittest.main()
