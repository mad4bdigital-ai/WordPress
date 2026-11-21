"""Prevent accidental self-activation of source-only WordPress/Root v3 bundles."""
import hashlib
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[2]
DEPLOY=ROOT/"deploy/host-authority/reconciliation/v128"
OPS=ROOT/"ops/host-authority-v128"

class InstallSafetyTests(unittest.TestCase):
    def test_reader_sha_matches_exact_loader_pin(self):
        reader=DEPLOY/"class-mad4b-original318-host-v3-reader.php"
        loader=(DEPLOY/"410-mad4b-original318-v3-readback.php").read_text()
        digest=hashlib.sha256(reader.read_bytes()).hexdigest()
        self.assertIn("hash_equals('"+digest+"'",loader)
        self.assertIn("if (!is_file($reader_file)",loader)
        self.assertIn("is_link($reader_file)",loader)
        self.assertIn("return;",loader)

    def test_no_generic_wp_write_or_http_endpoint(self):
        for name in ("class-mad4b-original318-host-v3-reader.php",
                     "410-mad4b-original318-v3-readback.php"):
            content=(DEPLOY/name).read_text()
            for bad in ("update_option(", "add_option(", "delete_option(",
                        "register_rest_route(", "wp_ajax_", "wpdb->query("):
                self.assertNotIn(bad,content)
        self.assertIn("WP_CLI::add_command('mad4b-host-v3'",(
            DEPLOY/"410-mad4b-original318-v3-readback.php").read_text())

    def test_root_signer_code_has_no_private_key_file_loader(self):
        for name in ("original312_v3_issuer.py","original313_v3_checkpoint.py"):
            s=(OPS/name).read_text()
            for forbidden in ("from_private_bytes(", "read_bytes(", "open('/root/",
                              "subprocess.run(", "os.system(", "paramiko.",
                              "ssh -i "):
                self.assertNotIn(forbidden,s)
            self.assertNotIn("if __name__ == '__main__'",s)

    def test_per_site_config_must_be_root_bound_not_option(self):
        s=(DEPLOY/"410-mad4b-original318-v3-readback.php").read_text()
        self.assertIn("MAD4B_HOST_V3_PINNED_BINDINGS",s)
        self.assertIn("MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64",s)
        self.assertIn("MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64",s)
        self.assertIn("MAD4B_Company_Environment_Registry_V2::target",s)
        self.assertIn("ROOT_TRUST_BINDING_NOT_INSTALLED",s)
        self.assertIn("'write_authorized'=>false",s)

if __name__=="__main__":
    unittest.main()
