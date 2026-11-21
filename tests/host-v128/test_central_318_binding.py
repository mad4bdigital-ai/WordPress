"""Central Origin318 PHP v3 identity validation, separate from company fleet."""
import base64
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[2]
LOADER=ROOT/"deploy/host-authority/reconciliation/v128/410-mad4b-original318-v3-readback.php"
SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
BINDING="32749c43cd77b0b070d6987815a627e9221df74ea859ff4580b23f175ccd05d0"
ORIGIN="https://tours.dev.mad4b.com"

class Central318BindingTests(unittest.TestCase):
    def setUp(self):
        self.payload={
            "profile":{
                "contract":"mad4b.site-profile.v2", "version":2,
                "site_uuid":SITE,"environment":"staging",
                "canonical_origin":ORIGIN,"deployment_binding_digest":BINDING,
            },
            "pins":{
                "mad4b-central:staging":{
                    "company":"mad4b-central","environment":"staging",
                    "site_uuid":SITE,"target_host_id":"hostinger-primary",
                    "ssh_principal":"root@hostinger-primary",
                    "host_key_sha256":"a"*64,"policy_sha256":"b"*64,
                    "origin":ORIGIN,
                },
                "all-royal-egypt:staging":{
                    "company":"all-royal-egypt","environment":"staging",
                    "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
                    "target_host_id":"hosting-2","ssh_principal":"u123456789",
                    "host_key_sha256":"a"*64,"policy_sha256":"b"*64,
                    "origin":ORIGIN,
                },
            },
            "312pub":base64.b64encode(b"\x10"*32).decode(),
            "313pub":base64.b64encode(b"\x20"*32).decode(),
            "central_digest":BINDING,"company":"mad4b-central",
            "environment":"staging", "origin":ORIGIN,
            "company_target":None,
        }

    def outcome(self):
        cmd=shlex.split(os.environ.get("MAD4B_TEST_PHP","php"))
        if not cmd or shutil.which(cmd[0]) is None:
            if os.getenv("MAD4B_REQUIRE_PHP")=="1":self.fail("PHP CLI required")
            self.skipTest("PHP not available")
        # The cross-language suite may run other PHP fixtures in a running
        # WordPress container without mounting the source tree. Use a fresh
        # isolated PHP container for THIS exact-source integration test.
        if cmd[0]=='docker' and 'exec' in cmd and not os.getenv('MAD4B_TEST_MOUNT_ROOT'):
            cmd=['docker','run','--rm','-i','--network','none',
                 '--read-only','--tmpfs','/tmp:rw,mode=1777',
                 '-v',str(ROOT)+':/src:ro','-w','/src','php:8.5-cli','php']
            mount_root='/src'
        else:
            mount_root=os.environ.get('MAD4B_TEST_MOUNT_ROOT')
        encoded=base64.b64encode(json.dumps(self.payload).encode()).decode()
        # Source code is untrusted by mocked values, but the true loader+reader
        # are executed. Avoid any WordPress database or network connection.
        loader=(Path(mount_root)/LOADER.relative_to(ROOT)) if mount_root else LOADER
        code='''<?php
$d=json_decode(base64_decode("%s"),true);
define('ABSPATH','/tmp/');
define('MAD4B_HOST_V3_PINNED_BINDINGS',$d['pins']);
define('MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64',$d['312pub']);
define('MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64',$d['313pub']);
define('MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256',$d['central_digest']);
function home_url($path){global $d;return $d['origin'].'/';}
function untrailingslashit($value){return rtrim($value,'/');}
function get_option($name,$default=false){global $d;return $name==='mad4b_scp_site_profile_v2'?$d['profile']:$default;}
class MAD4B_Company_Environment_Registry_V2{
    public static function target($company,$environment){global $d;return $d['company_target'];}
}
require '%s';
echo json_encode(MAD4B_Original318_Host_V3_Readback::current($d['company'],$d['environment']),JSON_UNESCAPED_SLASHES);
'''%(encoded,str(loader))
        run=subprocess.run(cmd,input=code,text=True,capture_output=True,timeout=12)
        self.assertEqual(run.returncode,0,run.stderr)
        return json.loads(run.stdout)

    def test_central_site_profile_passes_identity_checks_but_not_signature(self):
        proof=self.outcome()
        self.assertEqual(proof["state"],"MISSING_OR_INVALID")
        self.assertNotIn("reason",proof)
        self.assertFalse(proof["write_authorized"])
        self.assertFalse(proof["readback_verified"])

    def test_central_profile_uuid_mismatch_rejected(self):
        self.payload["profile"]["site_uuid"]="22222222-2222-4222-8222-222222222222"
        self.assertEqual(self.outcome()["reason"],"CENTRAL_SITE_PROFILE_BINDING_DRIFT")

    def test_central_binding_digest_mismatch_rejected(self):
        self.payload["profile"]["deployment_binding_digest"]="f"*64
        self.assertEqual(self.outcome()["reason"],"CENTRAL_SITE_PROFILE_BINDING_DRIFT")

    def test_central_origin_mismatch_rejected(self):
        self.payload["profile"]["canonical_origin"]="https://wrong.example"
        self.assertEqual(self.outcome()["reason"],"CENTRAL_SITE_PROFILE_BINDING_DRIFT")

    def test_central_production_cannot_use_staging_exception(self):
        self.payload["environment"]="production"
        self.assertIn(self.outcome()["reason"],
                      ("PINNED_SITE_IDENTITY_INVALID","ENROLLED_COMPANY_SITE_DRIFT"))

    def test_other_company_retains_company_registry_gate(self):
        self.payload["company"]="all-royal-egypt"
        self.payload["company_target"]={
            "company_id":"all-royal-egypt","environment":"staging",
            "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
            "canonical_origin":ORIGIN,
        }
        proof=self.outcome()
        self.assertEqual(proof["state"],"MISSING_OR_INVALID")
        self.assertNotIn("reason",proof)

    def test_other_company_must_use_registered_uuid(self):
        self.payload["company"]="all-royal-egypt"
        self.payload["company_target"]={
            "company_id":"all-royal-egypt","environment":"staging",
            "site_uuid":SITE,"canonical_origin":ORIGIN,
        }
        self.assertEqual(self.outcome()["reason"],"ENROLLED_COMPANY_SITE_DRIFT")

if __name__=="__main__":unittest.main()
