"""Cross-language real Ed25519 PHP reader/Original318 source-contract tests."""
import base64
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import unittest
import sys

import test_persistent_enrollment as fixture_module

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT / "ops" / "host-authority-v128"))
PHP_READER = ROOT / "deploy/host-authority/reconciliation/v128/class-mad4b-original318-host-v3-reader.php"
TEST_DRIVER = """
$payload=json_decode(base64_decode(getenv('V128_PHP_READER_TEST_INPUT')),true);
if(!is_array($payload)) throw new RuntimeException('TEST_FIXTURE_ENV_REQUIRED');
$out=MAD4B_Original318_Host_V3_Reader::verify_readback(
    $payload['enrollment'],$payload['checkpoint'],$payload['site'],
    base64_decode($payload['host_pub'],true),
    base64_decode($payload['checkpoint_pub'],true), $payload['now']);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
"""

class Original318PHPv3Tests(unittest.TestCase):
    def setUp(self):
        self.fixture = fixture_module.PersistentHostContractTests("test_default_mode_is_persistent")
        self.fixture.setUp()
        f = self.fixture
        self.site = {
            key: f.enrollment[key]
            for key in ("company","environment","site_uuid","target_host_id",
                        "ssh_principal","host_key_sha256","policy_sha256")
        }

    def php_readback(self, *, host_pub=None, checkpoint_pub=None,
                     now=1800000000):
        cmd = shlex.split(os.environ.get("MAD4B_TEST_PHP", "php"))
        if not cmd or shutil.which(cmd[0]) is None:
            if os.environ.get("MAD4B_REQUIRE_PHP") == "1":
                self.fail("PHP CLI required in GitHub Original318 contract CI")
            self.skipTest("PHP CLI unavailable (required in GitHub contract CI)")
        payload={
            "enrollment":self.fixture.enrollment,
            "checkpoint":self.fixture.checkpoint,
            "site":self.site,
            "host_pub":base64.b64encode(host_pub or self.fixture.hp).decode(),
            "checkpoint_pub":base64.b64encode(checkpoint_pub or self.fixture.cp).decode(),
            "now":now,
        }
        env=os.environ.copy()
        env["V128_PHP_READER_TEST_INPUT"]=base64.b64encode(
            json.dumps(payload,separators=(",",":"),ensure_ascii=False).encode()).decode()
        result=subprocess.run(
            cmd, input=PHP_READER.read_text()+"\n"+TEST_DRIVER,
            capture_output=True, text=True, env=env, timeout=12)
        self.assertEqual(result.returncode,0,result.stderr)
        return json.loads(result.stdout)

    def test_php_reader_exactly_matches_independent_python_verified_shape(self):
        php=self.php_readback()
        f=self.fixture
        self.assertTrue(php["readback_verified"])
        self.assertTrue(php["signature_verified"])
        self.assertFalse(php["write_authorized"])
        self.assertFalse(php["grant_created"])
        self.assertEqual(
            f.connection()["state"],
            "PERSISTENT_CONNECTED"
        )
        self.assertEqual(php["revision"],2)
        self.assertEqual(php["host_key_sha256"],f.enrollment["host_key_sha256"])
        import persistent_enrollment as v
        verified=v.verify_connection(f.enrollment,f.checkpoint,php,f.hp,f.cp,now=1800000000)
        self.assertTrue(verified["connection_verified"])
        self.assertFalse(verified["host_write_authorized"])

    def test_cross_site_mismatch_denied(self):
        self.site["site_uuid"]="22222222-2222-4222-8222-222222222222"
        self.assertFalse(self.php_readback()["readback_verified"])

    def test_missing_signer_independence_denied(self):
        self.assertFalse(
            self.php_readback(checkpoint_pub=self.fixture.hp)["readback_verified"])

    def test_tampered_signature_denied(self):
        self.fixture.enrollment["host_key_sha256"]="e"*64
        self.assertFalse(self.php_readback()["readback_verified"])

    def test_legacy_v2_signed_document_denied(self):
        self.fixture.enrollment["contract"]="mad4b.company-host-authority-anchor.v2"
        self.assertFalse(self.php_readback()["readback_verified"])

    def test_stale_highwater_denied(self):
        self.assertFalse(self.php_readback(now=1800000100)["readback_verified"])

    def test_root_highwater_revoked_denied(self):
        self.fixture.update_checkpoint(state="REVOKED")
        self.assertFalse(self.php_readback()["readback_verified"])

    def test_persistent_readback_without_running_operation(self):
        f=self.fixture
        f.checkpoint=f.sign(
            dict({k:f.checkpoint[k] for k in
                  ("contract","company","environment","site_uuid","revision",
                   "enrollment_sha256","state","operation_id","activity_sha256",
                   "activity_seq","observed_epoch")},
                 operation_id=None, activity_sha256=None, activity_seq=None),
            ("contract","company","environment","site_uuid","revision",
             "enrollment_sha256","state","operation_id","activity_sha256",
             "activity_seq","observed_epoch"), f.highwater_key)
        php=self.php_readback()
        self.assertTrue(php["readback_verified"])
        self.assertIsNone(php["operation_id"])
        self.assertFalse(php["write_authorized"])

    def test_scoped_registration_is_not_generic_write_permission(self):
        f=self.fixture
        f.enrollment["mode"]="operation_scoped"
        import persistent_enrollment as v
        f.enrollment=f.sign(
            {k:f.enrollment[k] for k in v.ENROLLMENT_FIELDS},
            v.ENROLLMENT_FIELDS,f.host_key)
        f.update_checkpoint()
        php=self.php_readback()
        self.assertTrue(php["readback_verified"])
        self.assertFalse(php["write_authorized"])
        result=v.verify_connection(f.enrollment,f.checkpoint,php,f.hp,f.cp,now=1800000000)
        self.assertFalse(result["connection_verified"])

if __name__=="__main__":
    unittest.main()
