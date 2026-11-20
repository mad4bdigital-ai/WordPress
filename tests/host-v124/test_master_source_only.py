#!/usr/bin/env python3
"""Host Authority v124 master-based, NON-EXECUTABLE source preservation gate.

Only inspect and compile files. Never import the Root scripts, run WP-CLI,
create Owner Requests, install workers, or write WordPress options.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
MANIFEST=ROOT/"governance/host-authority/master-source-only-v124.json"
BASE="c34b93fb485c33608a0d1cd3681ac97dd25e18cf"
SOURCE="ba644d24aa5c0a0750e35cc61b5e558a5a6a6401"

class MasterSourceOnlyV124(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data=json.loads(MANIFEST.read_text())
        cls.items=cls.data["items"]
    def test_base_and_source_are_explicitly_pinned(self):
        d=self.data
        self.assertEqual(d["contract"],
            "mad4b.host-authority.master-scope-source-only.v124")
        self.assertEqual(d["candidate_kind"],"preserved-source-not-commissioned")
        self.assertEqual(d["base_master_commit"],BASE)
        self.assertEqual(d["source_commit"],SOURCE)
        self.assertEqual(d["scope"],["all-royal-egypt","staging","hosting-2"])
        self.assertEqual(len(self.items),29)
        self.assertEqual(len(set(i["candidate_path"] for i in self.items)),29)
    def test_every_source_hash_matches_manifest_with_no_symlinks(self):
        for item in self.items:
            with self.subTest(item=item["candidate_path"]):
                p=ROOT/item["candidate_path"]
                self.assertTrue(p.is_file())
                self.assertFalse(p.is_symlink())
                self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),
                    item["sha256"])
                self.assertFalse(item["runtime_activation"])
                self.assertFalse(p.read_bytes().startswith(b"-----BEGIN PRIVATE KEY"))
    def test_wordpress_assets_remain_in_quarantined_review_directory(self):
        review=[i for i in self.items if i["kind"]=="quarantined_review_asset"]
        self.assertEqual(len(review),3)
        self.assertTrue(all(i["candidate_path"].startswith(
            "deploy/host-authority/review/v124/") for i in review))
        self.assertFalse(any(i["candidate_path"].startswith(
            "wp-content/") for i in self.items))
        self.assertFalse((ROOT/"wp-content/mu-plugins/393-mad4b-host-authority-owner-console.php").exists())
    def test_runtime_activation_flags_all_closed(self):
        flags=self.data["activation"]
        for k in ("wordpress_plugin_installed","owner_endpoint_registered",
                  "root_worker_installed","systemd_timer_installed",
                  "original318_write_allowed","production_deployment_allowed"):
            self.assertIs(flags[k],False,k)
        for k in ("requires_separate_runtime_parity","requires_current_master_ci",
                  "requires_exact_owner_attestation_for_future_release_head"):
            self.assertIs(flags[k],True,k)
        self.assertFalse(any("wp-content" in i for i in
            ["script_auto_loaded" if flags["wordpress_plugin_installed"] else ""]))
    def test_master_lacks_explicit_original_runtime_dependencies(self):
        deps=self.data["missing_original_runtime_deps"]
        self.assertEqual(len(deps),5)
        self.assertEqual({x["symbol"] for x in deps},{
          "MAD4B_Central_SSH_Command_Intake_V1",
          "MAD4B_Central_VPS_Broker_V1",
          "MAD4B_Native_Host_Exact_Grant_V1",
          "MAD4B_Company_Native_WPCLI_Enrollment_V1",
          "MAD4B_Original_Remote_Hostinger_328_V1"})
        for dep in deps:
            self.assertTrue(dep["source_file_exists"])
            self.assertFalse(dep["master_file_exists"])
    def test_python_scripts_syntax_without_import_or_execution(self):
        n=0
        for item in self.items:
            if not item["candidate_path"].endswith(".py"): continue
            file=ROOT/item["candidate_path"]
            compile(file.read_bytes(),str(file),"exec",dont_inherit=True)
            n+=1
        self.assertGreaterEqual(n,18)
    def test_review_php_and_javascript_parse_only(self):
        for item in self.items:
            path=ROOT/item["candidate_path"]
            if path.suffix==".php":
                if not shutil.which("php"): self.skipTest("php not installed")
                r=subprocess.run(["php","-l",str(path)],capture_output=True,
                    text=True,timeout=10)
                self.assertEqual(r.returncode,0,r.stderr+r.stdout)
            elif path.suffix==".js":
                if not shutil.which("node"): self.skipTest("node not installed")
                r=subprocess.run(["node","--check",str(path)],capture_output=True,
                    text=True,timeout=10)
                self.assertEqual(r.returncode,0,r.stderr+r.stdout)
    def test_candidate_diff_contains_only_source_review_and_gate(self):
        if not (ROOT/".git").exists(): self.skipTest("Git metadata unavailable")
        current=subprocess.run(["git","-C",str(ROOT),"rev-parse","HEAD"],
            capture_output=True,text=True,timeout=10,check=True).stdout.strip()
        if current==BASE: self.skipTest("Run after committing candidate")
        r=subprocess.run(["git","-C",str(ROOT),"diff","--name-only",BASE+"..HEAD"],
            capture_output=True,text=True,timeout=15,check=True)
        changed=set(r.stdout.splitlines())
        allowed={i["candidate_path"] for i in self.items}|{
          "governance/host-authority/master-source-only-v124.json",
          "tests/host-v124/test_master_source_only.py",
          "docs/host-v124/master-first-anchor-release-gates.md"}
        self.assertEqual(changed,allowed)
        self.assertFalse(any(x.startswith("wp-content/") for x in changed))
        self.assertFalse(any(x.startswith(".github/") for x in changed))

if __name__=="__main__":
    unittest.main(verbosity=2)
