"""Hermetic 305/312-scoped, signed host Apply -> independent 313 readback.

The Broker key is generated in a temporary test directory, never uses the
original host signer, no WordPress instance is contacted, and no real
Company Production files are written. This tests real Git blobs, exact
preimage, one-time nonce, local receipts, and independent SHA readback.
"""
from __future__ import annotations

import base64
import datetime as dt
import json
import os
import stat
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
import company_code_release as release
import original_313_code_release_contract as operation
import original_313_host_handler as host
import original_313_independent_readback as reader

class WholeReleaseLifecycleFixture(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        root=Path(self.tmp.name)
        self.repo=root/"source"
        self.site=root/"wordpress"
        self.receipts=root/"receipts"
        self.repo.mkdir();self.site.mkdir();self.receipts.mkdir(mode=0o700)
        # The live ledger always requires UID 0. Non-root CI exercises the
        # identical mode/path check with its own fixture UID only.
        if os.geteuid() != 0:
            from unittest.mock import patch
            fixture=patch.object(release,'assert_pinned_receipt_directory',
                lambda policy,receipts:release._verify_receipt_directory_owner(
                    policy,receipts,required_uid=os.geteuid()))
            fixture.start()
            self.addCleanup(fixture.stop)
        (self.repo/"ops"/"artifacts").mkdir(parents=True)
        (self.site/"wp-content"/"mu-plugins").mkdir(parents=True)
        self.rel="wp-content/mu-plugins/mad4b-full-cycle.php"
        self.source=self.repo/"ops"/"artifacts"/"sample.php"
        self.source.write_text("<?php echo 'RELEASED';\n")
        self.target=self.site/self.rel
        self.target.write_text("<?php echo 'PREVIOUS';\n")
        def git(*args):
            return subprocess.check_output(
                ["git","-C",str(self.repo),*args],
                stderr=subprocess.DEVNULL,text=True).strip()
        git("init","-q")
        subprocess.check_call(
            ["git","-C",str(self.repo),"-c","user.name=Test",
             "-c","user.email=test@example.invalid","add","."],
            stdout=subprocess.DEVNULL)
        subprocess.check_call(
            ["git","-C",str(self.repo),"-c","user.name=Test",
             "-c","user.email=test@example.invalid",
             "commit","-qm","one immutable git commit"])
        commit=git("rev-parse","HEAD")
        self.key=Ed25519PrivateKey.generate()
        public=self.key.public_key().public_bytes(
            serialization.Encoding.Raw,serialization.PublicFormat.Raw)
        self.site_uuid="cc1212aa-1111-4222-8333-456789012345"
        self.environment="production"
        self.policy={
            "contract":release.POLICY_CONTRACT,
            "source_git_root":str(self.repo),
            "receipt_dir":str(self.receipts),
            "broker_public_key_b64":base64.b64encode(public).decode(),
            "targets": {
                "demo:production":{
                    "root":str(self.site),"container":"test-php",
                    "site_uuid":self.site_uuid,
                    "origin":"https://demo.example.invalid",
                    "allowed_targets":[self.rel],
                },
            },
        }
        self.request={
            "contract":release.SPEC_CONTRACT,
            "company":"demo","environment":"production",
            "site_uuid":self.site_uuid,"git_commit":commit,
            "acceptance_checks":["wp_boot"],
            "files":[{
                "source":"ops/artifacts/sample.php","target":self.rel,
                "expected_before_sha256":release.digest(self.target.read_bytes()),
            }],
        }
        self.plan=release.make_plan(self.request,self.policy)
        self.job="b"*32
        self.authority={
            "contract":"mad4b.broker-exact-ssh-authority.v1",
            "verified":True,"eligible":True,
            "owner_enrolled_wpcli":True,"central_scope_only":True,
            "shell_executed":False,"job_id":self.job,
            "target_id":"srv2030908","company":"demo",
            "environment":"production","site_uuid":self.site_uuid,
            "origin":"https://demo.example.invalid",
            "target_scope":"wordpress","operation":"company.remote.wpcli.full",
            "command_sha256":operation.command_sha256(self.plan["plan_sha256"]),
            "expires_epoch":int(time.time())+800,
        }
        self.grant=self.signed_grant("apply","full_cycle_apply_nonce_123456")

    def tearDown(self):
        self.tmp.cleanup()

    def signed_grant(self,action,nonce):
        g={
            "contract":release.AUTH_CONTRACT,
            "issuer":"mad4b-company-broker","action":action,
            "plan_sha256":self.plan["plan_sha256"],
            "company":self.plan["company"],
            "environment":self.plan["environment"],
            "site_uuid":self.plan["site_uuid"],
            "original_job_id":self.job,
            "nonce":nonce,
            "expires_at":(dt.datetime.now(dt.timezone.utc)
                          +dt.timedelta(minutes=10)).isoformat(),
        }
        g["signature"]=base64.b64encode(
            self.key.sign(release.grant_message(g))).decode()
        return g

    def host_apply(self):
        return host.execute_once(
            self.job,self.plan,self.policy,self.grant,
            original_claim_provider=lambda job,plan: self.authority,
            runtime_check=lambda _p,_policy:None,
            code_check=lambda _p:None,
            candidate_preflight_check=lambda _p:None,
        )

    def fixture_private_receipt(self, path: Path) -> dict:
        # CI has no root: preserve exact fixed path, owner, symlink and mode
        # checks against the test UID only. Production trusted_load keeps UID0.
        self.assertEqual(path.parent.resolve(),self.receipts.resolve())
        self.assertFalse(path.is_symlink())
        mode=path.stat()
        self.assertEqual(mode.st_uid,os.geteuid())
        self.assertEqual(stat.S_IMODE(mode.st_mode) & 0o077,0)
        return json.loads(path.read_text())

    def independent(self):
        return reader.independent_verify(
            self.job,self.plan,self.policy,self.grant,self.authority,
            runtime_check=lambda _p,_policy:None,
            php_check=lambda _p:None,
            receipt_read=(host.trusted_load if os.geteuid()==0
                          else self.fixture_private_receipt),
        )

    def test_real_git_signed_apply_independent_readback_then_signed_rollback(self):
        result=self.host_apply()
        self.assertEqual(result["state"],"HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313")
        self.assertEqual(self.target.read_text(),"<?php echo 'RELEASED';\n")
        verified=self.independent()
        self.assertEqual(verified["state"],"HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313")
        self.assertFalse(verified["original_313_certified"])
        self.assertEqual(verified["file_count"],1)
        with self.assertRaisesRegex(release.Denied,"GRANT_ALREADY_USED"):
            self.host_apply()
        rollback=self.signed_grant("rollback","full_cycle_rollback_nonce_123")
        result=release.rollback_release(
            self.plan,self.policy,rollback,self.receipts,
            runtime_check=lambda _p,_policy:None)
        self.assertEqual(result["state"],"ROLLBACK_VERIFIED")
        self.assertEqual(self.target.read_text(),"<?php echo 'PREVIOUS';\n")

    def test_tampered_git_plan_or_target_blocks_independent(self):
        self.host_apply()
        self.target.write_text("<?php echo 'EXTERNAL_EDIT';\n")
        with self.assertRaisesRegex(release.Denied,"TARGET_CONTENT_DRIFT"):
            self.independent()

    def test_different_environment_original_312_rejected_before_write(self):
        self.authority["environment"]="staging"
        with self.assertRaisesRegex(operation.GateDenied,"SCOPE_DRIFT:environment"):
            self.host_apply()
        self.assertEqual(self.target.read_text(),"<?php echo 'PREVIOUS';\n")

    def test_wrong_job_in_signed_grant_denied_before_write(self):
        self.grant["original_job_id"]="c"*32
        with self.assertRaisesRegex(host.NotEnrolled,"JOB_AND_BROKER_GRANT_NOT_BOUND"):
            self.host_apply()
        self.assertEqual(self.target.read_text(),"<?php echo 'PREVIOUS';\n")

    def test_invalid_signed_grant_denied_before_write(self):
        self.grant["signature"]=base64.b64encode(b"tampered").decode()
        with self.assertRaisesRegex(release.Denied,"BROKER_SIGNATURE"):
            self.host_apply()
        self.assertEqual(self.target.read_text(),"<?php echo 'PREVIOUS';\n")


if __name__=="__main__":
    unittest.main()
