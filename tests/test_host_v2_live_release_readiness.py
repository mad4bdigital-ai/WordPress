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

    def test_reviewed_v103_successor_does_not_regress_to_v82_pending(self):
        reviewed=SOURCE/"wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php"
        self.live.write_bytes(reviewed.read_bytes())
        report=self.view()
        self.assertTrue(report["checks"]["original318_live_reviewed"])
        self.assertFalse(report["checks"]["original318_live_v82"])
        self.assertTrue(report["checks"]["v83_signed_current_readback_code_present"])
        self.assertTrue(report["checks"]["original318_dual_signed_reconcile_present"])
        self.assertTrue(report["checks"]["original318_revocation_binding_v96_present"])
        self.assertEqual(report["original318_live_reviewed_version"],"v103")
        self.assertTrue(report["checks"]["original318_expired_signed_readback_v103_present"])
        self.assertNotIn("original318_live_v82",report["pending"])
        self.assertNotIn("original318_live_reviewed",report["pending"])
        self.assertFalse(report["checks"]["host_issue_original312_issuer_commissioned"])
        self.assertFalse(report["checks"]["independent_signer_and_real_receipt_certified"])
        self.assertFalse(report["original313_receipt_signed"])
        self.assertEqual(report["state"],"BLOCKED")

    def test_reviewed_v83_stays_source_only_not_signing(self):
        from unittest.mock import patch
        self.live.write_text("<?php // v83 content is intentionally mocked")
        with patch.object(m, "digest", side_effect=lambda p:
             m.EXPECTED318_V83 if p == self.live else
             m.EXPECTED318 if p == self.candidate else None):
            report=self.view()
        self.assertTrue(report["checks"]["original318_live_reviewed"])
        self.assertEqual(report["original318_live_reviewed_version"],"v83")
        self.assertFalse(report["checks"]["original318_dual_signed_reconcile_present"])
        self.assertFalse(report["original313_receipt_signed"])
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
    def test_original305_no_host_job_is_precise_not_authorization(self):
        from types import SimpleNamespace
        reply={"contract":"mad4b.original305-306.host-job-presence.v1",
               "scanned":{"jobs":46,"requests":48},
               "issue_count":0,"revoke_count":0,"incomplete":False}
        evidence=m.original305_306_host_job_probe(
            run=lambda source: SimpleNamespace(returncode=0,
                 stdout=json.dumps(reply)))
        self.assertEqual(evidence["state"],"NO_HOST_AUTHORITY_JOB")
        self.assertFalse(evidence["authorization_verified"])
        result=m.inspect(self.live,self.registry,self.candidate,
              owner_probe=lambda:evidence,
              enrollment_probe=lambda:{"state":"NO_INDEPENDENT_ENROLLMENT",
                                        "authorization_verified":False})
        self.assertEqual(result["first_unresolved_runtime_dependency"],
                         "ORIGINAL305_AUTHENTIC_OWNER_HOST_JOB_MISSING")
        self.assertEqual(result["state"],"BLOCKED")

    def test_present_owner_job_does_not_certify_signature(self):
        from types import SimpleNamespace
        reply={"contract":"mad4b.original305-306.host-job-presence.v1",
               "scanned":{"jobs":3,"requests":4},
               "issue_count":1,"revoke_count":0,"incomplete":False}
        evidence=m.original305_306_host_job_probe(
            run=lambda source:SimpleNamespace(returncode=0,
                  stdout=json.dumps(reply)))
        self.assertEqual(evidence["state"],"PRESENT_UNVERIFIED")
        self.assertFalse(evidence["authorization_verified"])
        result=m.inspect(self.live,self.registry,self.candidate,
              owner_probe=lambda:evidence,
              enrollment_probe=lambda:{"state":"NO_INDEPENDENT_ENROLLMENT",
                                        "authorization_verified":False})
        self.assertEqual(result["first_unresolved_runtime_dependency"],
              "ORIGINAL313_INDEPENDENT_OWNER_ENROLLMENT_MISSING")
        self.assertFalse(result["original313_receipt_signed"])

    def test_paged_or_corrupt_job_count_is_not_declared_absent(self):
        from types import SimpleNamespace
        for record in (
            {"contract":"mad4b.original305-306.host-job-presence.v1",
             "scanned":{"jobs":256,"requests":12},
             "issue_count":0,"revoke_count":0,"incomplete":False},
            {"contract":"mad4b.original305-306.host-job-presence.v1",
             "scanned":{"jobs":2,"requests":2},
             "issue_count":0,"revoke_count":0,"incomplete":True},
            {"contract":"wrong","scanned":{"jobs":0,"requests":0},
             "issue_count":0,"revoke_count":0,"incomplete":False},
            {"contract":"mad4b.original305-306.host-job-presence.v1",
             "scanned":{"jobs":1,"requests":1},
             "issue_count":False,"revoke_count":0,"incomplete":False}
        ):
            value=m.original305_306_host_job_probe(
                run=lambda source:SimpleNamespace(returncode=0,
                    stdout=json.dumps(record)))
            self.assertNotEqual(value["state"],"NO_HOST_AUTHORITY_JOB")
            self.assertFalse(value["authorization_verified"])

    def test_wp_probe_fails_closed_without_exposing_stderr(self):
        from types import SimpleNamespace
        result=m.original305_306_host_job_probe(
            run=lambda source:SimpleNamespace(
                returncode=2,stdout="",stderr="PRIVATE_SECRET"))
        self.assertEqual(result["state"],"UNKNOWN")
        self.assertNotIn("PRIVATE_SECRET",json.dumps(result))

    def test_independent_enrollment_proof_absent_not_invented(self):
        root=self.root/"enrollments"
        root.mkdir(mode=0o700)
        evidence=m.first_enrollment_presence(root)
        self.assertEqual(evidence["state"],"NO_INDEPENDENT_ENROLLMENT")
        self.assertFalse(evidence["authorization_verified"])
        entry=root/"all-royal-egypt--staging.json"
        entry.write_text("{}")
        entry.chmod(0o600)
        # Current test user can be non-root; permission requirements fail closed.
        state=m.first_enrollment_presence(root)["state"]
        self.assertIn(state,("UNTRUSTED_FILE","PRESENT_SIGNATURE_NOT_VERIFIED"))
        self.assertFalse(m.first_enrollment_presence(root)["authorization_verified"])

    def test_enrollment_symlink_rejected(self):
        root=self.root/"enrollments"
        root.mkdir(mode=0o700)
        (root/"all-royal-egypt--staging.json").symlink_to(self.index)
        record=m.first_enrollment_presence(root)
        self.assertNotEqual(record["state"],"PRESENT_SIGNATURE_NOT_VERIFIED")
        self.assertFalse(record["authorization_verified"])

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
