"""The Desired ledger stays Root-only and cannot self-authorize site writes."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
SOURCE=(ROOT/"ops/host-authority-v128/desired_registry_store.py").read_text()
DOC=(ROOT/"docs/host-v128/desired-registry-root-v129.md").read_text()

class DesiredRegistrySourceGuards(unittest.TestCase):
    def test_no_signing_private_key_or_wordpress_mutation_api(self):
        for forbidden in ("from_private_bytes(", "load_pem_private_key(",
                          "update_option(", "wpdb", "register_rest_route(",
                          "subprocess.run(", "os.system(", "paramiko.",
                          "os.system(", "wp cli", "curl_exec("):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden,SOURCE)

    def test_monotonic_atomicity_required(self):
        for required in (
            "BEGIN IMMEDIATE", "DESIRED_LEDGER_NON_MONOTONIC_REVISION",
            "DESIRED_LEDGER_CAS_PREIMAGE_MISMATCH",
            "DESIRED_LEDGER_PREDECESSOR_BASE_DRIFT",
            "DESIRED_LEDGER_GENESIS_NOT_LIVE_BASED",
            "COMMIT", "ROLLBACK",
        ):
            self.assertIn(required,SOURCE)

    def test_signed_current_and_independent_desired_required(self):
        self.assertIn("read_current(",SOURCE)
        self.assertIn("verify_desired(",SOURCE)
        self.assertIn("independent_highwater_checked",SOURCE)
        self.assertIn('"host_write_authorized":False',SOURCE)

    def test_historical_head_never_claims_active_authority(self):
        self.assertIn('"current_authority_verified":False',SOURCE)
        self.assertIn("new high-water",DOC.lower())
        self.assertIn("No production or Staging Desired",DOC)

if __name__=="__main__":unittest.main()
