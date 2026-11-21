"""An installed site's signed file-state is 'current'; Git is only candidate."""
from __future__ import annotations

import base64
import hashlib
import json
import sys
import unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import live_current_source as m
from fleet_policy import SiteBinding
from persistent_enrollment import ConnectionDenied

NOW=1800000000
ORIGIN="https://staging.example.test"
PATH_READER="wp-content/mu-plugins/class-mad4b-original318-host-v3-reader.php"
PATH_LOADER="wp-content/mu-plugins/410-mad4b-original318-v3-readback.php"

def f(path,sha="ABSENT",size=0):
    return {"relative_path":path,"state":"ABSENT" if sha=="ABSENT" else "PRESENT",
            "sha256":sha,"size_bytes":size}

class LiveCurrentSourceTests(unittest.TestCase):
    def setUp(self):
        self.key=Ed25519PrivateKey.generate()
        self.pub=self.key.public_key().public_bytes(
            Encoding.Raw,PublicFormat.Raw)
        self.binding=SiteBinding(
            company="all-royal-egypt", environment="staging",
            site_uuid="11111111-1111-4111-8111-111111111111",
            host_id="hosting-2",ssh_principal="u123456789",
            host_key_sha256="a"*64,policy_sha256="b"*64)
        self.receipt={
            "contract":m.CONTRACT,"company":self.binding.company,
            "environment":self.binding.environment,
            "site_uuid":self.binding.site_uuid,
            "target_host_id":self.binding.host_id,
            "host_key_sha256":self.binding.host_key_sha256,
            "policy_sha256":self.binding.policy_sha256,
            "origin":ORIGIN,"observed_epoch":NOW-1,
            "snapshot_nonce":"1"*32,"read_only":True,
            "state":"VERIFIED_LIVE_INSTALLED_CODE",
            "files":[f(PATH_LOADER),f(PATH_READER)],
        }
        self.receipt=self.sign(self.receipt)
        self.candidate={
            "contract":m.CANDIDATE_CONTRACT,
            "company":self.binding.company,
            "environment":self.binding.environment,
            "site_uuid":self.binding.site_uuid,
            "target_host_id":self.binding.host_id,
            "source_head_sha":"8"*40,
            "files":[f(PATH_LOADER,"a"*64,500),f(PATH_READER,"b"*64,1000)],
        }

    def sign(self,body):
        clean={k:body[k] for k in m.FIELDS}
        clean["signature"]=base64.b64encode(
            self.key.sign(m.canonical(clean))).decode()
        return clean

    def current(self,**kw):
        return m.read_current(
            self.binding,lambda _:self.receipt,self.pub,ORIGIN,
            now=kw.get("now",NOW))

    def deny_current(self,code):
        with self.assertRaisesRegex(ConnectionDenied,code):
            self.current()

    def test_live_code_is_only_current_after_signed_site_readback(self):
        current=self.current()
        self.assertEqual(current.site_uuid,self.binding.site_uuid)
        self.assertEqual(current.file_map()[PATH_READER],("ABSENT","ABSENT",0))
        self.assertEqual(current.snapshot_sha256,
                         hashlib.sha256(m.canonical(self.receipt)).hexdigest())
        self.assertNotIn("source_head_sha",current.__dict__)

    def test_candidate_does_not_replace_current(self):
        old=self.current()
        plan=m.plan_candidate(old,self.candidate,self.binding)
        self.assertEqual(plan["current"]["source"],"verified_live_installed_code")
        self.assertEqual(plan["candidate"]["source"],"exact_git_candidate_not_installed")
        self.assertEqual(plan["change_count"],2)
        self.assertTrue(plan["requires_precommit_fresh_current_readback"])
        self.assertFalse(plan["host_write_authorized"])
        self.assertFalse(plan["deployment_performed"])
        self.assertEqual(plan["previous"]["state"],"AWAITING_HOST_RUNNER_BACKUP")
        self.assertFalse(plan["previous"]["backup_verified"])
        self.assertEqual(plan["previous"]["snapshot_sha256"],old.snapshot_sha256)
        self.assertEqual(old.file_map()[PATH_READER][0],"ABSENT")

    def test_postwrite_signed_live_readback_promotes_current(self):
        old=self.current()
        updated=dict(self.receipt)
        updated["files"]=self.candidate["files"]
        updated["snapshot_nonce"]="2"*32
        updated["observed_epoch"]=NOW
        updated=self.sign(updated)
        new=m.confirm_new_current(
            old,self.candidate,self.binding,lambda _:updated,self.pub,
            ORIGIN,now=NOW)
        self.assertEqual(new.file_map()[PATH_READER],("PRESENT","b"*64,1000))
        self.assertEqual(old.file_map()[PATH_READER],("ABSENT","ABSENT",0))

    def test_postwrite_mismatch_does_not_promote(self):
        old=self.current()
        updated=dict(self.receipt)
        updated["snapshot_nonce"]="2"*32
        updated["observed_epoch"]=NOW
        updated=self.sign(updated)
        with self.assertRaisesRegex(ConnectionDenied,"POSTWRITE_LIVE_SOURCE_NOT_EQUAL_CANDIDATE"):
            m.confirm_new_current(old,self.candidate,self.binding,
                                  lambda _:updated,self.pub,ORIGIN,now=NOW)

    def test_same_snapshot_cannot_promote(self):
        old=self.current()
        with self.assertRaisesRegex(ConnectionDenied,"POSTWRITE_CURRENT_NOT_FRESH"):
            m.confirm_new_current(old,self.candidate,self.binding,
                                  lambda _:self.receipt,self.pub,ORIGIN,now=NOW)

    def test_no_unsigned_wordpress_or_git_snapshot_accepted(self):
        del self.receipt["signature"]
        self.deny_current("LIVE_CURRENT_SIGNED_SHAPE_INVALID")

    def test_invalid_signature_rejected(self):
        self.receipt["files"][1]["sha256"]="f"*64
        self.receipt["files"][1]["state"]="PRESENT"
        self.receipt["files"][1]["size_bytes"]=25
        self.deny_current("LIVE_CURRENT_SIGNATURE_INVALID")

    def test_stale_readback_rejected(self):
        self.receipt=self.sign({**self.receipt,"observed_epoch":NOW-121})
        self.deny_current("LIVE_CURRENT_STALE_OR_REPLAYABLE")

    def test_wrong_company_binding_rejected(self):
        self.receipt=self.sign({**self.receipt,"company":"wayfatours"})
        self.deny_current("LIVE_CURRENT_SITE_BINDING_MISMATCH")

    def test_wrong_origin_rejected(self):
        self.receipt=self.sign({**self.receipt,"origin":"https://unknown.example.test"})
        self.deny_current("LIVE_CURRENT_ORIGIN_DRIFT")

    def test_source_signer_must_be_independently_pinned(self):
        alternative=Ed25519PrivateKey.generate().public_key().public_bytes(
            Encoding.Raw,PublicFormat.Raw)
        with self.assertRaisesRegex(ConnectionDenied,"LIVE_CURRENT_SIGNATURE_INVALID"):
            m.read_current(self.binding,lambda _:self.receipt,alternative,
                           ORIGIN,now=NOW)

    def test_duplicate_file_entries_rejected(self):
        self.receipt=self.sign({
            **self.receipt,"files":[self.receipt["files"][0]]*2})
        self.deny_current("CURRENT_FILES_DUPLICATED_OR_UNSORTED")

    def test_forbidden_paths_cannot_be_current(self):
        for path in (
            "wp-config.php",
            "wp-content/uploads/secret.php",
            "wp-content/plugins/elementor/elementor.php",
            "wp-content/mu-plugins/../wp-config.php",
            "wp-content/plugins/mad4b-platform/../../wp-config.php",
            "/etc/passwd",
        ):
            with self.subTest(path=path):
                self.receipt=self.sign({
                    **self.receipt,"files":[f(path)]})
                self.deny_current("CURRENT_SOURCE_PATH_NOT_ALLOWED")

    def test_candidate_requires_exact_live_preimage(self):
        plan=dict(self.candidate)
        plan["files"]=[self.candidate["files"][0]]
        with self.assertRaisesRegex(ConnectionDenied,"CANDIDATE_MUST_HAVE_LIVE_PREIMAGE_FOR_ALL_FILES"):
            m.plan_candidate(self.current(),plan,self.binding)

    def test_candidate_cross_site_denied(self):
        plan=dict(self.candidate)
        plan["site_uuid"]="22222222-2222-4222-8222-222222222222"
        with self.assertRaisesRegex(ConnectionDenied,"CANDIDATE_SCOPE_OR_SOURCE_INVALID"):
            m.plan_candidate(self.current(),plan,self.binding)

    def test_no_reader_denied(self):
        with self.assertRaisesRegex(ConnectionDenied,"LIVE_HOST_RUNNER_READER_REQUIRED"):
            m.read_current(self.binding,None,self.pub,ORIGIN,now=NOW)

    def test_readonly_receipt_required(self):
        self.receipt=self.sign({**self.receipt,"read_only":False})
        self.deny_current("LIVE_CURRENT_SIGNED_SHAPE_INVALID")

    def test_mad4b_plugin_live_code_path_supported(self):
        self.assertTrue(m.code_path(
            "wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php"))

if __name__=="__main__":unittest.main()
