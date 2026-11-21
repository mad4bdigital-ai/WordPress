"""Host Runner authenticates LIVE multi-file Current with its enrolled key."""
import importlib.util
import json
import os
import sys
import tempfile
from pathlib import Path
from unittest import TestCase, mock, main
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import (
    Encoding, PublicFormat, PrivateFormat, NoEncryption
)
import base64

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location(
    "host_runner_live_current_v129",ROOT/"tools/mad4b_host_runner.py")
runner=importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import live_current_source as current_contract
from fleet_policy import SiteBinding

SITE_UUID="11111111-1111-4111-8111-111111111111"
ORIGIN="https://staging.example.test"
MU="wp-content/mu-plugins/class-mad4b-original318-host-v3-reader.php"
MU_OTHER="wp-content/mu-plugins/410-mad4b-original318-v3-readback.php"
PLUG="wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php"

class HostRunnerSignedCurrentTests(TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base=Path(self.tmp.name)
        self.wp=self.base/"wordpress"
        self.wp.mkdir()
        (self.wp/"wp-config.php").write_text("<?php // test only\n")
        for name, data in ((MU,b"<?php echo 'hello';"),(PLUG,b"<?php return true;")):
            path=self.wp/name
            path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(data)
        self.key=Ed25519PrivateKey.generate()
        pem=self.key.private_bytes(Encoding.PEM,PrivateFormat.PKCS8,NoEncryption())
        self.sign_key=self.base/"original-host-enrolled-private.pem"
        self.sign_key.write_bytes(pem)
        self.sign_key.chmod(0o600)
        self.pub=self.key.public_key().public_bytes(
            Encoding.Raw,PublicFormat.Raw)
        self.hmac_key=self.base/"integrity.key"
        self.hmac_key.write_bytes(os.urandom(32))
        self.hmac_key.chmod(0o600)
        self.bind={
            "company":"all-royal-egypt","environment":"staging",
            "site_uuid":SITE_UUID,"target_host_id":"hosting-2",
            "host_key_sha256":"a"*64,"policy_sha256":"b"*64,
            "origin":ORIGIN,
        }
        self.profile_file=self.base/"profile.json"
        self.raw_profile={
            "contract":runner.PROFILE_CONTRACT,
            "profile_id":"staging-v129-fixture",
            "site_uuid":SITE_UUID,"environment":"staging",
            "wordpress_root":str(self.wp),
            "integrity_key_file":str(self.hmac_key),
            "expected_runner_sha256":runner.sha256_file(
                ROOT/"tools/mad4b_host_runner.py"),
            "allowed_operations":["site.code.current.read"],
            "host_environment_receipt_signing_key_file":str(self.sign_key),
            "host_environment_receipt_signing_public_key_b64":
                base64.b64encode(self.pub).decode(),
            "live_current_binding":self.bind,
        }
        self.write_profile()
        self.profile=runner.load_profile(self.profile_file)
        self.job={"operation_id":"site.code.current.read",
                  "input":{"paths":sorted([MU,MU_OTHER,PLUG])}}
        self.site=SiteBinding(
            company=self.bind["company"],environment=self.bind["environment"],
            site_uuid=SITE_UUID,host_id=self.bind["target_host_id"],
            ssh_principal="u123456789",
            host_key_sha256=self.bind["host_key_sha256"],
            policy_sha256=self.bind["policy_sha256"],
        )

    def write_profile(self):
        self.profile_file.write_text(json.dumps(self.raw_profile))

    def run_read(self):
        return runner.execute_site_code_current(self.profile,self.job)

    def verified(self):
        result=self.run_read()
        source=result["signed_current"]
        current=current_contract.verify_current_receipt(
            source,self.site,self.pub,ORIGIN,now=source["observed_epoch"])
        return result,current

    def test_genuine_installed_code_becomes_signed_current(self):
        result,current=self.verified()
        self.assertEqual(result["readback_verdict"],"PASS")
        self.assertFalse(result["write_authorized"])
        self.assertFalse(result["mutation_performed"])
        files=current.file_map()
        self.assertEqual(files[MU][1],runner.sha256_bytes(
            (self.wp/MU).read_bytes()))
        self.assertEqual(files[PLUG][1],runner.sha256_bytes(
            (self.wp/PLUG).read_bytes()))
        self.assertEqual(files[MU_OTHER],("ABSENT","ABSENT",0))

    def test_signer_pins_public_key_in_first_party_profile(self):
        other_pub=Ed25519PrivateKey.generate().public_key().public_bytes(
            Encoding.Raw,PublicFormat.Raw)
        signed=self.run_read()["signed_current"]
        with self.assertRaisesRegex(ValueError,"Host receipt signer does not match"):
            self.profile["host_environment_receipt_signing_public_key_b64"]=(
                base64.b64encode(other_pub).decode())
            self.run_read()
        with self.assertRaisesRegex(
                Exception,"LIVE_CURRENT_SIGNATURE_INVALID"):
            current_contract.verify_current_receipt(
                signed,self.site,other_pub,ORIGIN,now=signed["observed_epoch"])

    def test_non_mad4b_path_never_read(self):
        self.job["input"]["paths"]=["wp-config.php"]
        with self.assertRaisesRegex(ValueError,"allowlist"):
            self.run_read()

    def test_directory_traversal_never_read(self):
        self.job["input"]["paths"]=["wp-content/plugins/mad4b-platform/../../wp-config.php"]
        with self.assertRaisesRegex(ValueError,"allowlist"):
            self.run_read()

    def test_wrong_site_profile_identity_rejected(self):
        self.raw_profile["live_current_binding"]["site_uuid"]="2"*36
        self.write_profile()
        with self.assertRaisesRegex(ValueError,"site identity"):
            runner.load_profile(self.profile_file)

    def test_production_profile_never_gets_live_snapshot(self):
        self.profile["environment"]="production"
        self.profile["live_current_binding"]["environment"]="production"
        with self.assertRaisesRegex(ValueError,"Staging-only"):
            self.run_read()

    def test_caller_cannot_change_site_binding(self):
        self.job["input"]["company"]="wayfatours"
        with self.assertRaisesRegex(ValueError,"only exact path set"):
            self.run_read()

    def test_operation_is_not_active_without_enrollment(self):
        self.profile["allowed_operations"]=[]
        with self.assertRaisesRegex(ValueError,"not enrolled"):
            self.run_read()

    def test_symlink_to_private_file_is_rejected(self):
        secret=self.base/"secret.txt"
        secret.write_text("do not reveal")
        path=self.wp/MU
        path.unlink()
        path.symlink_to(secret)
        with self.assertRaises(OSError):
            self.run_read()

    def test_parent_directory_symlink_is_rejected(self):
        path=self.wp/PLUG
        path.unlink()
        folder=path.parent
        folder.rmdir()
        folder.symlink_to(self.base,target_is_directory=True)
        with self.assertRaises(OSError):
            self.run_read()

    def test_inplace_concurrent_edit_fails_closed(self):
        original=runner._host_read_live_code_file
        calls={"n":0}
        def changed(root,path):
            calls["n"]+=1
            row=original(root,path)
            if calls["n"]>3 and path==MU:
                row["sha256"]="f"*64
            return row
        with mock.patch.object(runner,"_host_read_live_code_file",changed):
            with self.assertRaisesRegex(ValueError,"changed during snapshot"):
                self.run_read()

    def test_key_in_wordpress_root_rejected(self):
        self.profile["host_environment_receipt_signing_key_file"]=str(
            self.wp/"secret.pem")
        (self.wp/"secret.pem").write_bytes(self.sign_key.read_bytes())
        (self.wp/"secret.pem").chmod(0o600)
        with self.assertRaisesRegex(ValueError,"cannot reside inside WordPress"):
            self.run_read()

    def test_runner_profile_requires_source_hash_repin(self):
        self.raw_profile["expected_runner_sha256"]="a"*64
        self.write_profile()
        with self.assertRaisesRegex(ValueError,"executable identity"):
            runner.load_profile(self.profile_file)

    def test_readonly_operation_does_not_gain_approval(self):
        op=runner.OPERATIONS["site.code.current.read"]
        self.assertEqual(op["risk"],"read_only")
        self.assertNotIn("requires_approval",op)
        self.assertNotIn("requires_plan",op)
        self.assertEqual(op["zones"],["wordpress_root"])

if __name__=="__main__":
    main()
