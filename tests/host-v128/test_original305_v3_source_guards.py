from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
P=(ROOT/"ops/host-authority-v128/original305_v3_owner_provider.py").read_text()
D=(ROOT/"tools/mad4b_original305_v3_preflight.py").read_text()
DOC=(ROOT/"docs/host-v128/original305-v132-owner-stepup-and-root-readiness.md").read_text()
class Original305SourceGuards(unittest.TestCase):
    def test_approval_source_has_no_key_loading_or_web_endpoint(self):
        for forbidden in ("load_pem_private_key(","from_private_bytes(",
                          "subprocess.run(","os.system(","wp option update",
                          "update_option(","register_rest_route(","paramiko."):
            self.assertNotIn(forbidden,P)
    def test_exact_issuer_requires_human_signature_and_predecessor(self):
        for required in ("verify_exact_owner_stepup(", "validate_predecessor(",
                         "keys_independent(", "ORIGINAL305_REGISTERED_SIGNER_REQUIRED",
                         "ORIGINAL305_OWNER_REQUEST_ALREADY_CONSUMED",
                         "verify_signed(approval,OWNER_FIELDS",
                         "pinned_owner305_public"):
            self.assertIn(required,P)
    def test_preflight_is_read_only(self):
        for forbidden in ("Ed25519PrivateKey(",".sign(", "write_text(",
                          "write_bytes(","os.replace(","subprocess.run(",
                          "wp option update","chattr "):
            self.assertNotIn(forbidden,D)
        self.assertIn('"approved_write":False',D)
        self.assertIn('"automatic_signature_attempted":False',D)
    def test_unregistered_candidate_keys_not_assumed_trusted(self):
        self.assertIn("NOT enrolled",DOC)
        self.assertIn("ambiguous-write HOLD",DOC)
        self.assertIn("never declare NONE",DOC)
if __name__=="__main__":unittest.main()
