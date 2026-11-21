from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
CODE=(ROOT/"ops/host-authority-v128/exact_staging_publisher.py").read_text()
DOC=(ROOT/"docs/host-v128/original318-v130-transactional-publisher.md").read_text()
class PublisherSourceSafetyTests(unittest.TestCase):
    def test_no_generic_root_mutation_or_new_signer_api(self):
        for forbidden in ("subprocess.run(", "os.system(", "paramiko.",
                          "from_private_bytes(", "load_pem_private_key(",
                          "register_rest_route(", "wp_ajax_", "wpdb",
                          "update_option(", "shell=True"):
            self.assertNotIn(forbidden,CODE)
    def test_signed_write_desired_and_backup_required(self):
        for expected in ("admit_exact_standing_write(", "verify_desired(",
                         "verify_previous_backup(", "read_current(",
                         "ORIGINAL318_POSTWRITE_READBACK_MISMATCH",
                         "ORIGINAL318_APPLY_FAILED_ROLLED_BACK"):
            self.assertIn(expected,CODE)
    def test_never_generic_filesystem_operation(self):
        self.assertIn("MU_PAIR =",CODE)
        self.assertIn("EXACT_MU_PAIR_ONLY",CODE)
        self.assertIn("O_NOFOLLOW",CODE)
        self.assertIn("fcntl.flock",CODE)
    def test_is_not_activated_source_only(self):
        self.assertIn("not an exposed Host Runner command",DOC)
        self.assertIn("Production remains untouched",DOC)
        self.assertIn("allowed_operations=[site.code.current.read]",DOC)
if __name__=="__main__":unittest.main()
