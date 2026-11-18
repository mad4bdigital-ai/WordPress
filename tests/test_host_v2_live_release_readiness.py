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
    def test_typed_disabled_host_providers_are_registered_but_not_issuers(self):
        import hashlib
        providers=[]
        for op in (m.ISSUE,m.REVOKE):
            path="dynamic-operation-registry-v1/providers/"+op+"/manifest.json"
            f=self.registry/path
            f.parent.mkdir(parents=True,exist_ok=True)
            doc={
                "operation_id":op,
                "resource_kind":"host_anchor",
                "source_family":"mad4b.original-host-authority-anchor.v2",
                "execution_enabled":False,
            }
            f.write_text(json.dumps(doc))
            providers.append({"manifest":path,"sha256":hashlib.sha256(
                f.read_bytes()).hexdigest()})
        self.index.write_text(json.dumps({
            "contract":"mad4b.dynamic-operation-index.v1",
            "providers":providers,
        }))
        result=self.view()
        self.assertTrue(result["checks"]["host_issue_provider_registered"])
        self.assertTrue(result["checks"]["host_revoke_provider_registered"])
        self.assertFalse(result["checks"]["host_issue_original312_issuer_commissioned"])
        self.assertFalse(result["checks"]["host_revoke_original312_issuer_commissioned"])
        self.assertTrue(result["state"]=="BLOCKED")
        self.assertFalse(result["anchor_issued"])

    def test_reviewed_v83_successor_does_not_regress_to_v82_pending(self):
        reviewed=SOURCE/"wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php"
        self.live.write_bytes(reviewed.read_bytes())
        report=self.view()
        self.assertTrue(report["checks"]["original318_live_reviewed"])
        self.assertFalse(report["checks"]["original318_live_v82"])
        self.assertTrue(report["checks"]["v83_signed_current_readback_code_present"])
        self.assertEqual(report["original318_live_reviewed_version"],"v83")
        self.assertNotIn("original318_live_v82",report["pending"])
        self.assertFalse(report["original313_receipt_signed"])
        self.assertEqual(report["state"],"BLOCKED")
    def test_unrecognized_version_remains_denied(self):
        self.live.write_text("<?php // deliberately unreviewed")
        report=self.view()
        self.assertFalse(report["checks"]["original318_live_reviewed"])
        self.assertIn("original318_live_reviewed",report["pending"])
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
