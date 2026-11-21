"""Source-based, no-WordPress-mutation test of the additive v3 CLI reader."""
import base64
import json
import os
import re
from pathlib import Path
import shlex
import shutil
import subprocess
import sys
import time
import unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import test_persistent_enrollment as fixtures

READER=ROOT/"deploy/host-authority/reconciliation/v128/class-mad4b-original318-host-v3-reader.php"
LOADER=ROOT/"deploy/host-authority/reconciliation/v128/410-mad4b-original318-v3-readback.php"
SCOPE="all-royal-egypt:staging"
ORIGIN="https://staging.example.test"

def code_bundle():
    reader=READER.read_text().replace("<?php","",1)
    loader=LOADER.read_text().replace("<?php","",1)
    # The reader source is already included above. Skip only its in-place
    # filesystem admission block for the synthetic STDIN PHP harness.
    loader, count=re.subn(
        r"// Never load a partial, modified or symlinked runtime bundle\.[\s\S]*?require_once \$reader_file;",
        "/* reader preloaded in test harness */", loader, count=1)
    if count!=1:raise AssertionError("loader integrity guard drift")
    return """<?php
define('ABSPATH','/tmp/v128-test');
define('WP_CLI',false);
$payload=json_decode(base64_decode(getenv('V128_WPCLI_PHP_TEST_INPUT')),true);
if(!is_array($payload)) throw new RuntimeException('FIXTURE_INPUT_REQUIRED');
define('MAD4B_HOST_V3_PINNED_BINDINGS',$payload['bindings']);
define('MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64',$payload['root312']);
define('MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64',$payload['root313']);
function home_url($x='/'){return $GLOBALS['payload']['origin'].'/';}
function untrailingslashit($x){return rtrim($x,'/');}
function get_option($x,$default=false) {
 return array_key_exists($x,$GLOBALS['payload']['options'])
   ?$GLOBALS['payload']['options'][$x]:$default;
}
class MAD4B_Company_Environment_Registry_V2 {
 public static function target($company,$environment){
  return $GLOBALS['payload']['registry'];
 }
}
""" + reader + "\n" + loader + """
$row=MAD4B_Original318_Host_V3_Readback::current(
    $payload['company'],$payload['environment']);
echo json_encode($row, JSON_UNESCAPED_SLASHES);
"""

class WPCLIHostV3ReadbackTests(unittest.TestCase):
    def setUp(self):
        f=fixtures.PersistentHostContractTests("test_default_mode_is_persistent")
        f.setUp()
        self.now=int(time.time())
        import persistent_enrollment as v
        f.enrollment["issued_epoch"]=self.now-100
        f.enrollment=f.sign(
            {k:f.enrollment[k] for k in v.ENROLLMENT_FIELDS},
            v.ENROLLMENT_FIELDS,f.host_key)
        f.update_checkpoint(observed=self.now)
        self.f=f
        self.values={
            k:f.enrollment[k] for k in
            ("company","environment","site_uuid","target_host_id",
             "ssh_principal","host_key_sha256","policy_sha256")
        }
        self.payload={
            "company":f.enrollment["company"],
            "environment":f.enrollment["environment"],
            "bindings":{SCOPE:{**self.values,"origin":ORIGIN}},
            "origin":ORIGIN,
            "root312":base64.b64encode(f.hp).decode(),
            "root313":base64.b64encode(f.cp).decode(),
            "registry":{
                "company_id":f.enrollment["company"],
                "environment":f.enrollment["environment"],
                "site_uuid":f.enrollment["site_uuid"],
                "canonical_origin":ORIGIN,
            },
            "options":{
                "mad4b_host_v3_signed_enrollment_all-royal-egypt_staging":f.enrollment,
                "mad4b_host_v3_signed_checkpoint_all-royal-egypt_staging":f.checkpoint,
            },
        }

    def current(self):
        cmd=shlex.split(os.environ.get("MAD4B_TEST_PHP","php"))
        if not cmd or shutil.which(cmd[0]) is None:
            if os.environ.get("MAD4B_REQUIRE_PHP")=="1":
                self.fail("PHP CLI required")
            self.skipTest("PHP CLI unavailable")
        env=os.environ.copy()
        env["V128_WPCLI_PHP_TEST_INPUT"]=base64.b64encode(json.dumps(
            self.payload,separators=(',',':'),ensure_ascii=False).encode()).decode()
        # Docker exec needs explicit env forwarding; without it all tests
        # could falsely pass from the default negative response.
        if cmd[0]=="docker" and "-e" not in cmd:
            cmd=cmd[:3]+["-e","V128_WPCLI_PHP_TEST_INPUT"]+cmd[3:]
        res=subprocess.run(cmd,input=code_bundle(),text=True,
                           capture_output=True,env=env,timeout=12)
        self.assertEqual(res.returncode,0,res.stderr)
        return json.loads(res.stdout)

    def test_correct_site_and_fresh_independent_checkpoint(self):
        r=self.current()
        self.assertEqual(r["state"],"ACTIVE")
        self.assertTrue(r["readback_verified"])
        self.assertTrue(r["connection_verified"])
        self.assertFalse(r["write_authorized"])

    def test_missing_signed_checkpoint_denied(self):
        del self.payload["options"]["mad4b_host_v3_signed_checkpoint_all-royal-egypt_staging"]
        self.assertFalse(self.current()["readback_verified"])

    def test_wrong_site_registry_denied(self):
        self.payload["registry"]["site_uuid"]="99999999-9999-4999-8999-999999999999"
        self.assertEqual(self.current()["reason"],"ENROLLED_COMPANY_SITE_DRIFT")

    def test_wrong_runtime_trusted_bindings_denied(self):
        self.payload["bindings"][SCOPE]["origin"]="https://foreign.example.test"
        self.assertEqual(self.current()["reason"],"PINNED_SITE_IDENTITY_INVALID")

    def test_untrusted_option_cannot_replace_signed_enrollment(self):
        self.payload["options"]["mad4b_host_v3_signed_enrollment_all-royal-egypt_staging"]["host_key_sha256"]="1"*64
        self.assertFalse(self.current()["readback_verified"])

    def test_malformed_signed_enrollment_cannot_crash_reader(self):
        self.payload["options"]["mad4b_host_v3_signed_enrollment_all-royal-egypt_staging"]["signature"]={"not":"base64"}
        self.assertFalse(self.current()["readback_verified"])

    def test_malformed_checkpoint_digest_cannot_crash_reader(self):
        self.payload["options"]["mad4b_host_v3_signed_checkpoint_all-royal-egypt_staging"]["enrollment_sha256"]={"not":"a digest"}
        self.assertFalse(self.current()["readback_verified"])

    def test_original313_revoked_highwater_denied(self):
        self.f.update_checkpoint(state="REVOKED",observed=self.now)
        self.payload["options"]["mad4b_host_v3_signed_checkpoint_all-royal-egypt_staging"]=self.f.checkpoint
        self.assertFalse(self.current()["connection_verified"])

    def test_scoped_only_not_persistent_connection(self):
        import persistent_enrollment as v
        f=self.f
        f.enrollment["mode"]="operation_scoped"
        f.enrollment=f.sign({k:f.enrollment[k] for k in v.ENROLLMENT_FIELDS},
                            v.ENROLLMENT_FIELDS,f.host_key)
        f.update_checkpoint(observed=self.now)
        self.payload["options"]["mad4b_host_v3_signed_enrollment_all-royal-egypt_staging"]=f.enrollment
        self.payload["options"]["mad4b_host_v3_signed_checkpoint_all-royal-egypt_staging"]=f.checkpoint
        r=self.current()
        self.assertTrue(r["readback_verified"])
        self.assertFalse(r["connection_verified"])

if __name__=="__main__":unittest.main()
