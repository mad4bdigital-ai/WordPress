"""Host v85 source manifest integrity and fail-closed handler regressions."""
from __future__ import annotations
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest


ROOT=Path(__file__).resolve().parents[2]
OPS=ROOT/"ops"
HOST=OPS/"host-anchor-v2"


def load(p):
    spec=importlib.util.spec_from_file_location("host_v85_"+p.stem,p)
    assert spec is not None and spec.loader is not None
    obj=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj


class HostAnchorSourceTests(unittest.TestCase):
    def test_manifests_deterministic_and_exactly_two(self):
        generator=load(HOST/"generate_manifests.py")
        artifacts=generator.artifacts()
        self.assertEqual(len(artifacts),4)
        for path,blob in artifacts.items():
            self.assertEqual(path.read_bytes(),blob)

    def test_separate_first_and_operational_original312_contracts(self):
        for name in ("issue","revoke"):
            op="company.host-authority.anchor."+name
            d=json.loads((OPS/"dynamic-operation-registry-v1"/"providers"/op/"manifest.json").read_text())
            self.assertEqual(d["operation_id"],op)
            self.assertEqual(d["resource_kind"],"host_anchor")
            self.assertEqual(d["job_type"],"signed_remote_hostinger_wpcli_v2")
            self.assertFalse(d["execution_enabled"])
            self.assertTrue(d["execution_requires_signed_originals"])
            self.assertEqual(d["command"]["format"],"host.anchor.ref.v2")
            self.assertTrue(d["original312_contracts"])
            if name=="issue":
                self.assertEqual(len(d["original312_contracts"]),2)
            else:
                self.assertEqual(len(d["original312_contracts"]),1)

    def test_pinned_handler_verifier_preclaim_finalizer_and_dependencies(self):
        import hashlib
        for name in ("issue","revoke"):
            p=OPS/"dynamic-operation-registry-v1"/"providers"/("company.host-authority.anchor."+name)/"manifest.json"
            m=json.loads(p.read_text())
            for spec in [m["handler"],m["verifier"],m["preclaim"],m["finalizer"],*m["dependencies"]]:
                self.assertTrue(spec["path"].startswith("host-anchor-v2/"))
                self.assertEqual(hashlib.sha256((OPS/spec["path"]).read_bytes()).hexdigest(),spec["sha256"])

    def test_untrusted_claim_cannot_enter_preclaim(self):
        preclaim=load(HOST/"anchor_preclaim.py")
        with self.assertRaisesRegex(ValueError,"HOST_ANCHOR_OPERATION_INVALID"):
            preclaim.verify_claimable(None,{}, {}, {})

    def test_non_original312_job_not_eligible_for_signer_verifier(self):
        verifier=load(HOST/"anchor_verifier.py")
        with self.assertRaisesRegex(ValueError,"HOST_ANCHOR_SIGNED_SOURCE_EVIDENCE_REQUIRED"):
            verifier.verify_signed_original312(None, {}, {}, {}, None)

    def test_handler_never_issues_or_revokes_without_signed_original_issuer(self):
        handler=load(HOST/"anchor_handler.py")
        with self.assertRaisesRegex(PermissionError,
                                    "HOST_ANCHOR_ISSUER_NOT_COMMISSIONED"):
            handler.execute_claimed()

    def test_handler_cli_holds_even_under_root(self):
        p=subprocess.run([sys.executable,str(HOST/"anchor_handler.py")],
                         capture_output=True,text=True,check=False,timeout=10)
        self.assertEqual(p.returncode,2)
        data=json.loads(p.stdout)
        self.assertFalse(data["grant_issued"])
        self.assertFalse(data["anchor_issued"])
        self.assertFalse(data["anchor_revoked"])
        self.assertFalse(data["original313_signed_receipt"])
        self.assertFalse(data["execution_performed"])


if __name__=="__main__":
    unittest.main()
