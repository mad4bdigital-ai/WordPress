"""Local Git source-only acceptance of six non-authorizing CSP merges."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
P=ROOT/"docs/release-coverage/control-plane-rc128-three-way-20261111.json"
D=json.loads(P.read_text())
LATER=json.loads((ROOT/"docs/release-coverage/control-plane-semantic-reconcile-batch-20261111.json").read_text())
LATER_BY_PATH={row["path"]:row for row in LATER["changed_files"]}


def old_blob(ref,path):
    result=subprocess.run(["git","-C",str(ROOT),"cat-file","blob",ref+":"+path],
                          capture_output=True,timeout=20)
    if result.returncode:raise ValueError("UNVERIFIED_PREIMAGE")
    return result.stdout

def signatures(raw):
    text=raw.decode("utf-8",errors="replace")
    classes=set(re.findall(r"(?m)^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([a-zA-Z_]\w*)",text))
    funcs=set(re.findall(r"\bfunction\s+&?\s*([a-zA-Z_]\w*)\s*\(",text))
    return classes,funcs

class ThreeWaySourceTests(unittest.TestCase):
    def test_pinned_source_hashes_and_unchanged_declarations(self):
        self.assertEqual(D["contract"],"mad4b.control-plane-three-way-source-reconcile.v1")
        self.assertEqual(len(D["files"]),6)
        for item in D["files"]:
            with self.subTest(path=item["path"]):
                rel=item["path"]
                self.assertTrue(rel.startswith("wp-content/plugins/mad4b-site-control-plane/includes/"))
                self.assertTrue(rel.endswith(".php"))
                self.assertTrue(item["source_candidate_only"])
                current=(ROOT/rel).read_bytes()
                # Preserve the original rc128 commit proof, but admit exactly documented
                # later semantic reconciles without freezing the source forever.
                pinned=old_blob("ba5484b1d5d1120b648f3079b4ac47b6e087e45b",rel)
                self.assertEqual(hashlib.sha256(pinned).hexdigest(),item["merged_sha256"])
                later=LATER_BY_PATH.get(rel)
                expected=later.get("candidate_sha256",later.get("live_sha256")) if later else item["merged_sha256"]
                self.assertEqual(hashlib.sha256(current).hexdigest(),expected)
                old=old_blob(D["preimage_commit"],rel)
                self.assertEqual(hashlib.sha256(old).hexdigest(),item["old_candidate_sha256"])
                c1,f1=signatures(old);c2,f2=signatures(current)
                self.assertTrue(c1.issubset(c2))
                self.assertTrue(f1.issubset(f2))
                self.assertNotIn(b"<<<<<<<",current)
    def test_runtime_version_neutral_but_integrity_required(self):
        text=(ROOT/"wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-reconnect-hardening.php").read_text()
        self.assertIn("stateful_runtime_version()",text)
        self.assertNotIn("CERTIFIED_STATEFUL_ADAPTER_VERSION =",text)
        self.assertIn("certified_adapter_runtime_integrity_ok()",text)
        self.assertIn("runtime_contract_ok",text)
        self.assertIn("manifest_present",text)
        self.assertIn("runtime_version_must_match_certified_and_installed",text)
    def test_non_authorizing_lifecycle_scope(self):
        text=(ROOT/"wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-lifecycle-rest.php").read_text()
        self.assertIn("function operation_scope()",text)
        self.assertIn("'reenrollment_automatically_grants_write_or_oauth'=>false",text)
        self.assertIn("'production_reenrollment_available'=>false",text)
        self.assertIn("function reenroll_staging(",text)
    def test_sender_provider_and_google_context_remain_deferred(self):
        for name in D["deferred_conflicted_or_high_risk"]:
            path="wp-content/plugins/mad4b-site-control-plane/"+name
            current=(ROOT/path).read_bytes()
            original=old_blob(D["preimage_commit"],path)
            if name=="includes/class-mad4b-scp-company-email-routing.php":
                # Later source repair removed exactly one duplicate Fluent Forms
                # registration, without changing recipient handling or policy.
                redundant=b"        add_filter('fluentform/email_to', [__CLASS__,'fluent_form_recipient'], 100, 4);\n"
                self.assertEqual(original.count(redundant),1)
                self.assertEqual(current,original.replace(redundant,b"",1))
                self.assertEqual(current.count(b"add_filter('fluentform/email_to'"),1)
            else:
                self.assertEqual(current,original)

if __name__=="__main__":unittest.main()
