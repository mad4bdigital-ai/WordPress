#!/usr/bin/env python3
"""v125 FIRST ANCHOR runtime module *source* closure; no PHP module execution.

No WordPress calls, no Root credentials, no Owner approval, no activation.
This is a strict fail-closed review gate, not an executable site release.
"""
import copy
import hashlib
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
MANIFEST=ROOT/"governance/host-authority/runtime-closure-v125.json"
MASTER="1ed359a52ecd1c94574ddfd31c9327de5779b856"
SOURCE="ba644d24aa5c0a0750e35cc61b5e558a5a6a6401"
DECL=re.compile(r"\b(?:(?:final|abstract|readonly)\s+)?(?:class|trait|interface)\s+(MAD4B_[A-Za-z0-9_]+)\b")
REF=re.compile(r"\b(MAD4B_[A-Za-z0-9_]+)\s*::")
PREFIX="deploy/host-authority/reconciliation/v125/wordpress-platform/"
ROOT_CLASSES={
"Original305":"MAD4B_Central_SSH_Command_Intake_V1",
"Original306":"MAD4B_Central_VPS_Broker_V1",
"Original313":"MAD4B_Company_Native_WPCLI_Enrollment_V1",
"Original318":"MAD4B_Native_Host_Exact_Grant_V1",
"Original328":"MAD4B_Original_Remote_Hostinger_328_V1"}

def validate(m):
    assert m["contract"]=="mad4b.host-authority.runtime-closure-review.v125"
    assert m["base_master_sha"]==MASTER
    assert m["source_sha"]==SOURCE
    assert m["mode"]=="INERT_RUNTIME_SOURCE_NOT_DEPLOYABLE"
    assert m["activation"]=={
      "wordpress_plugin_installed":False,"mu_bootstrap":False,
      "original306_worker_replaced":False,"original318_write_allowed":False,
      "production_deployment":False,"new_signed_owner_approval_needed_for_live_release":True}
    assert m["roots"]==ROOT_CLASSES
    assert m["scope"]=={"company":"all-royal-egypt","environment":"staging","host":"hosting-2"}
    assert m["eligibility"]=="HOLD_RUNTIME_ACTIVATION"
    assert m["selected_file_count"]==len(m["files"])==32
    assert m["source_platform_php_count"]==360
    assert m["referenced_class_count"]==46
    assert len(m["outside_plugin_classes"])==13
    assert all(row["resolved"] is False for row in m["outside_plugin_classes"])
    assert m["verified_master_class_definitions"]==2
    assert m["unverified_external_runtime_classes"]==13
    assert len(m["collision_definitions"])==1
    collision=m["collision_definitions"][0]
    assert collision["class"]=="MAD4B_Original_WPCLI_Sealed_Command_V2"
    assert collision["mapped"]=="modules/compat/305-mad4b-original-sealed-rebind.php"
    assert collision["not_mapped"]==["modules/compat/305-mad4b-original-wpcli-seal-v2.php"]
    seen={}
    for item in m["files"]:
        assert item["target"].startswith(PREFIX)
        assert item["source"]=="wp-content/plugins/mad4b-platform/"+item["target"][len(PREFIX):]
        assert item["autoloaded"] is False
        path=ROOT/item["target"]
        assert path.is_file() and not path.is_symlink()
        raw=path.read_bytes()
        assert hashlib.sha256(raw).hexdigest()==item["sha256"]
        assert hashlib.sha1(("blob "+str(len(raw))+"\0").encode()+raw).hexdigest()==item["git_blob"]
        declared=set(DECL.findall(raw.decode("utf-8","replace")))
        assert declared==set(item["declares"])
        for cl in declared:
            assert cl not in seen,("DUPLICATE_SHIPPING_CLASS",cl,seen[cl],item["target"])
            seen[cl]=item["target"]
        content=raw.decode("utf-8","replace")
        for fn,count in item["hooks"].items():
            assert len(re.findall(r"\b"+fn+r"\s*\(",content))==count
    assert set(ROOT_CLASSES.values()).issubset(seen)
    assert len(seen)==33
    assert sum(i["source_load_order_index"] is None for i in m["files"])==5
    assert sum(i["source_load_order_index"] is not None for i in m["files"])==27
    assert sum(i["hooks"]["add_action"] for i in m["files"])==27
    assert sum(i["hooks"]["register_rest_route"] for i in m["files"])==34
    graph=m["class_graph"]
    assert set(graph).issubset(set(seen))
    assert set(graph).union({x["name"] for x in m["outside_plugin_classes"]})==set(
        graph).union({x for vs in graph.values() for x in vs}).union(set(ROOT_CLASSES.values()))
    for cl,deps in graph.items():
        raw=(ROOT/seen[cl]).read_text(errors="replace")
        # A file may own multiple classes, so its raw refs are a superset.
        assert set(deps).issubset(set(REF.findall(raw))-{cl})
    assert all(not x["target"].startswith("wp-content/") for x in m["files"])
    return True

class RuntimeClosureReadOnly(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest=json.loads(MANIFEST.read_text())
    def test_01_source_integrity_and_hard_fail_closed_contract(self):
        self.assertTrue(validate(self.manifest))
    def test_02_unverified_plugin_dependency_denies_runtime(self):
        self.assertEqual(self.manifest["eligibility"],"HOLD_RUNTIME_ACTIVATION")
        self.assertEqual(len(self.manifest["outside_plugin_classes"]),13)
        self.assertTrue(all(not x["resolved"] for x in self.manifest["outside_plugin_classes"]))
    def test_03_original_305_duplicate_class_is_not_packaged_twice(self):
        c=self.manifest["collision_definitions"][0]
        p="modules/compat/305-mad4b-original-wpcli-seal-v2.php"
        self.assertNotIn(PREFIX+p,{x["target"] for x in self.manifest["files"]})
        self.assertIn(p,c["alternate_sha256"])
        self.assertNotEqual(c["alternate_sha256"][p],
            next(x["sha256"] for x in self.manifest["files"]
                if x["target"].endswith(c["mapped"])))
    def test_04_root_and_host_operations_stay_inert(self):
        a=self.manifest["activation"]
        self.assertFalse(a["wordpress_plugin_installed"])
        self.assertFalse(a["mu_bootstrap"])
        self.assertFalse(a["original306_worker_replaced"])
        self.assertFalse(a["original318_write_allowed"])
        self.assertFalse(a["production_deployment"])
    def test_05_32_php_files_parse_as_php_without_execution(self):
        # CI / isolated shell: lint only, do not include/require PHP modules.
        # php binary may be unavailable on Root; Docker PHP 8.5 lint is
        # required separately and recorded in release review.
        try:
            subprocess.run(["php","-v"],stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,check=True,timeout=5)
        except (OSError,subprocess.CalledProcessError):
            self.skipTest("PHP CLI unavailable; run all 32 in PHP 8.5 Docker")
        for item in self.manifest["files"]:
            with self.subTest(item=item["target"]):
                r=subprocess.run(["php","-l",str(ROOT/item["target"])],
                    text=True,capture_output=True,timeout=10)
                self.assertEqual(r.returncode,0,r.stdout+r.stderr)
    def test_06_missing_optional_loader_modules_are_explicit(self):
        unmapped=[x for x in self.manifest["files"] if x["source_load_order_index"] is None]
        self.assertEqual(len(unmapped),5)
        self.assertTrue(all(x["source"].startswith(
            "wp-content/plugins/mad4b-platform/") for x in unmapped))
    def test_07_activation_tamper_fails_gate(self):
        bad=copy.deepcopy(self.manifest)
        bad["activation"]["original318_write_allowed"]=True
        with self.assertRaises(AssertionError):validate(bad)
    def test_08_digest_tamper_fails_gate(self):
        bad=copy.deepcopy(self.manifest)
        bad["files"][0]["sha256"]="0"*64
        with self.assertRaises(AssertionError):validate(bad)
    def test_09_fake_resolution_fails_gate(self):
        bad=copy.deepcopy(self.manifest)
        bad["outside_plugin_classes"][0]["resolved"]=True
        with self.assertRaises(AssertionError):validate(bad)
    def test_10_disabling_original_313_nonce_binding_fails_gate(self):
        bad=copy.deepcopy(self.manifest)
        bad["roots"]["Original313"]="MAD4B_Invalid"
        with self.assertRaises(AssertionError):validate(bad)
    def test_11_original_316_and_318_policy_do_not_autoload_from_candidate(self):
        self.assertFalse((ROOT/"wp-content/plugins/mad4b-platform").exists())
        self.assertFalse((ROOT/"wp-content/mu-plugins/393-mad4b-host-authority-owner-console.php").exists())

if __name__=="__main__":
    unittest.main(verbosity=2)
