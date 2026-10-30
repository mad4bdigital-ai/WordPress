#!/usr/bin/env python3
"""Hermetic integration: real temp-file mutation, signed admission and restore.

Never uses VPS, WordPress, networking, databases or real customer data.
"""
import base64
import datetime as dt
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

EXEC=Path(__file__).resolve().parents[1]/"executor/company_stage_executor.py"
spec=importlib.util.spec_from_file_location("company_stage_executor",EXEC)
engine=importlib.util.module_from_spec(spec)
spec.loader.exec_module(engine)
NAME="product-navigation-tags.php"
IDENTITY="b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b"

class NativeExecutorTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix="mad4b-isolated-")
        base=Path(self.tmp.name)
        self.src=base/"source";self.src.mkdir()
        self.dest=base/"tenant";self.dest.mkdir()
        self.backup=base/"backup";self.backup.mkdir()
        self.receipts=base/"receipts"
        self.source_php="<?php echo 'new portable provider';\n"
        self.previous_php="<?php echo 'tenant previous version';\n"
        (self.src/NAME).write_text(self.source_php)
        (self.dest/NAME).write_text(self.previous_php)
        sums=[]
        for name in ("wayfatours-db.sql.gz","wayfatours-site.tar.gz"):
            body=("fixture-"+name).encode()
            (self.backup/name).write_bytes(body)
            sums.append(engine.digest(body)+"  "+name)
        (self.backup/"SHA256SUMS").write_text("\n".join(sums)+"\n")
        self.key=Ed25519PrivateKey.from_private_bytes(b"K"*32)
        pub=self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.policy={
            "company":"wayfatours","site_uuid":IDENTITY,
            "canonical_origin":"https://wayfatours.mad4b.com",
            "source_mu_root":str(self.src),"target_mu_root":str(self.dest),
            "portable_mu_allowlist":[NAME],"never_copy":[],
            "executor_contract":"mad4b.company-vps-executor.v1",
            "mutation_enabled":False,"backup_root":str(self.backup),
            "wordpress_container":"wayfatours-production-php",
            "wordpress_database":"wayfatours_production",
            "owner_public_key_id":"test-only-ed25519",
            "owner_ed25519_public_key":base64.b64encode(pub).decode(),
        }
        self.manifest={
            "contract":"mad4b.company-stage-job.v1","company_code":"wayfatours",
            "site_uuid":IDENTITY,"origin":"https://wayfatours.mad4b.com",
            "mode":"repair_existing","preserve_existing":True,
            "stage":"mu_providers","action":"company.mu-providers.converge",
            "run_id":"1"*64,"plan_sha256":"2"*64,"stage_revision":"3"*64,
            "artifacts":[{"path":NAME,"sha256":engine.digest(self.source_php.encode())}],
        }
        self.manifest_path=base/"manifest.json";self.policy_path=base/"policy.json"
        self.ticket_path=base/"ticket.json"
        self.save()

    def tearDown(self):
        self.tmp.cleanup()

    def save(self):
        self.manifest_path.write_text(json.dumps(self.manifest))
        self.policy_path.write_text(json.dumps(self.policy))

    def ticket(self,purpose,valid=True):
        now=dt.datetime.now(dt.timezone.utc)
        scope={k:self.manifest[k] for k in ("company_code","site_uuid","origin","mode","action","run_id","plan_sha256","stage_revision")}
        ticket={"contract":"mad4b.company-executor-ticket.v1",
             "purpose":purpose,"key_id":"test-only-ed25519","scope":scope,
             "bundle_sha256":engine.digest(engine.canonical(self.manifest["artifacts"])),
             "issued_at":(now-dt.timedelta(seconds=10)).isoformat(),
             "expires_at":(now+dt.timedelta(minutes=5)).isoformat()}
        ticket["signature"]=base64.b64encode(self.key.sign(engine.canonical(ticket))).decode()
        if not valid:ticket["scope"]["run_id"]="9"*64
        self.ticket_path.write_text(json.dumps(ticket))
        return self.ticket_path

    def call(self,op,ticket=None):
        return engine.main([op,"--policy",str(self.policy_path),
            "--manifest",str(self.manifest_path),
            "--receipt-dir",str(self.receipts)]
            +(["--ticket",str(ticket)] if ticket is not None else []))

    def test_plan_real_hashes_no_mutation(self):
        self.assertEqual(self.call("plan")["state"],"PLAN_VALIDATED")
        self.assertEqual((self.dest/NAME).read_text(),self.previous_php)

    def test_fail_closed_existing_never_copy_policy(self):
        self.policy["never_copy"]=["mu-plugins"]
        self.save()
        self.assertEqual(self.call("plan")["reason"],"EXISTING_POLICY_FORBIDS_MU_COPY")

    def test_wrong_tenant_rejected(self):
        self.manifest["site_uuid"]="1"*8+"-"+IDENTITY[9:]
        self.save()
        self.assertEqual(self.call("plan")["reason"],"COMPANY_IDENTITY_MISMATCH")

    def test_source_artifact_hash_mismatch(self):
        (self.src/NAME).write_text("<?php echo 'tampered';")
        self.assertEqual(self.call("plan")["reason"],"SOURCE_ARTIFACT_HASH_MISMATCH")

    def test_denied_without_signed_ticket(self):
        self.policy["mutation_enabled"]=True;self.save()
        self.assertEqual(self.call("apply")["reason"],"CENTRAL_SIGNED_TICKET_REQUIRED")

    def test_denied_bad_signed_scope(self):
        self.policy["mutation_enabled"]=True;self.save()
        t=self.ticket("apply",valid=False)
        self.assertEqual(self.call("apply",t)["reason"],"TICKET_NOT_BOUND_TO_EXACT_STAGE")

    def test_signed_ticket_still_denied_when_mutation_disabled(self):
        t=self.ticket("apply")
        self.assertEqual(self.call("apply",t)["reason"],"MUTATION_NOT_ENROLLED")

    def test_applied_only_with_live_identity_and_backup_then_rollback(self):
        self.policy["mutation_enabled"]=True;self.save()
        t=self.ticket("apply")
        with patch.object(engine,"inspect_live_wordpress",return_value=None) as probe:
            out=self.call("apply",t)
        probe.assert_called_once()
        self.assertEqual(out["state"],"APPLIED_FILES_VERIFIED",out)
        self.assertTrue(out["requires_wordpress_runtime_readback"])
        self.assertFalse(out["commercial_distribution_authorized"])
        self.assertEqual((self.dest/NAME).read_text(),self.source_php)
        self.assertTrue(self.call("readback")["verified"])
        # Source removed and new applications disabled: signed emergency
        # restoration must work from the preimage without the source tree.
        (self.src/NAME).unlink()
        self.policy["never_copy"]=["mu-plugins"]
        self.policy["mutation_enabled"]=False
        self.save()
        t=self.ticket("rollback")
        rolled=self.call("rollback",t)
        self.assertEqual(rolled["state"],"ROLLED_BACK_VERIFIED",rolled)
        self.assertEqual((self.dest/NAME).read_text(),self.previous_php)

    def test_reject_missing_backup(self):
        self.policy["mutation_enabled"]=True
        (self.backup/"SHA256SUMS").unlink()
        self.save()
        t=self.ticket("apply")
        with patch.object(engine,"inspect_live_wordpress",return_value=None):
            self.assertEqual(self.call("apply",t)["reason"],"BACKUP_HAS_NO_CHECKSUM_MANIFEST")
        self.assertEqual((self.dest/NAME).read_text(),self.previous_php)

    def test_symlink_escape_denied(self):
        (self.src/NAME).unlink()
        (self.src/NAME).symlink_to(self.dest/NAME)
        self.assertEqual(self.call("plan")["reason"],"SYMLINK_ARTIFACT_FORBIDDEN")

if __name__=="__main__":
    unittest.main(verbosity=2)
