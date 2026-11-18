"""Release observer is fail-closed and never changes live files or grants."""
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest

SOURCE=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("host_v2_readiness",SOURCE/"scripts/host_v2_live_release_readiness.py")
m=importlib.util.module_from_spec(spec);sys.modules[spec.name]=m;spec.loader.exec_module(m)
class Readiness(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix="host-release-readiness-unit-")
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.live=self.root/"318-live.php"
        self.candidate=self.root/"318-v68.php"
        self.registry=self.root/"registry"
        self.registry.mkdir(mode=0o700)
        self.candidate.write_text("<?php // source fixture")
        self.live.write_text("<?php // older fixture")
        self.index=self.registry/"index.json"
        self.index.write_text(json.dumps(dict(contract="mad4b.dynamic-operation-index.v1",providers=[])))
        (self.registry/"worker_adapter.py").write_text('old worker')
        (self.registry/"evidence.py").write_text('old evidence')
    def view(self):
        return m.inspect(live318=self.live,registry=self.registry,candidate=self.candidate)
    def test_unpublished_blocked(self):
        a=self.view()
        self.assertEqual(a["state"],"BLOCKED")
        self.assertFalse(a["release_applied"])
        self.assertFalse(a["original313_receipt_signed"])
    def test_missing_registry_blocked(self):
        self.index.unlink()
        self.assertFalse(self.view()["checks"]["installed_registry_manifest_digest_verified"])
    def test_manifest_traversal_blocked(self):
        self.index.write_text(json.dumps(dict(contract="mad4b.dynamic-operation-index.v1",
          providers=[dict(manifest="../../escape.json",sha256="a"*64)])))
        self.assertFalse(self.view()["checks"]["installed_registry_manifest_digest_verified"])
    def test_unsigned_provider_path_denied(self):
        self.index.write_text(json.dumps(dict(contract="mad4b.dynamic-operation-index.v1",
          providers=[dict(manifest="dynamic-operation-registry-v1/providers/fake/manifest.json",sha256="a"*64)])))
        self.assertFalse(self.view()["checks"]["host_issue_provider_registered"])
    def test_no_synthetic_receipt_from_source_presence(self):
        (self.registry/"worker_adapter.py").write_text("signed_remote_hostinger_wpcli_v2 resolve_remote_host_v2_claim")
        (self.registry/"evidence.py").write_text("signed_remote_hostinger_wpcli_v2 company.host-authority.anchor.revoke")
        a=self.view()
        self.assertTrue(a["checks"]["installed_worker_has_explicit_v2_admission"])
        self.assertFalse(a["checks"]["independent_signer_and_real_receipt_certified"])
        self.assertFalse(a["original313_receipt_signed"])
    def test_never_mutates_inputs(self):
        before=(self.live.read_bytes(),self.candidate.read_bytes(),self.index.read_bytes())
        self.view()
        self.assertEqual(before,(self.live.read_bytes(),self.candidate.read_bytes(),self.index.read_bytes()))
    def test_result_never_implies_execution(self):
        a=self.view()
        self.assertFalse(a["anchor_issued"])
        self.assertFalse(a["anchor_revoked"])
        self.assertFalse(a["production_mutated"])
        self.assertFalse(a["grant_created"])
if __name__=="__main__":unittest.main(verbosity=2)
