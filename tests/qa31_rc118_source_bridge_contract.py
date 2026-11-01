#!/usr/bin/env python3
import hashlib
import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from qa31_rc118_source_bridge import audit, INCLUDES, MAIN, digest

HEADER = b"<?php\n * Version: 0.4.0-rc.118\n" + b"define( 'MAD4B_SCP_VERSION', '0.4.0-rc.118' );\n"
GUARD = b"// REV121: explicitly Staging-only Company Operations modules.\nif ( 'staging' === wp_get_environment_type() ) {\n"
TAIL = b"\trequire_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-owner-activation.php';\n"
REQUIRES = [b"\trequire_once MAD4B_SCP_DIR . '" + x.encode() + b"';\n" for x in INCLUDES]

class QA31RC118BridgeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        for path in INCLUDES:
            p = self.root / path
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(b"<?php // tested source\n")
        self.baseline = HEADER + GUARD + TAIL + b"}\n"
        (self.root / MAIN).write_bytes(HEADER + GUARD + TAIL + b"".join(REQUIRES) + b"}\n")
        self.manifest = {"contract":"mad4b.build-provenance.v1","package_files":[{"path":MAIN,"sha256":digest(self.baseline)}]}

    def check(self):
        return audit(self.root, self.manifest)

    def test_exact_three_line_drift_is_explained_never_authorizing(self):
        r = self.check()
        self.assertEqual(r["status"], "EXPLAINED_UNCERTIFIED_SOURCE_DRIFT")
        self.assertEqual(r["main_added_bytes"], 266)
        self.assertEqual(len(r["unmanifested_include_files"]), 3)
        self.assertFalse(r["staging_rollout_authorized"])
        self.assertFalse(r["source_authoritative"])
        self.assertFalse(r["production_rollout_authorized"])

    def test_unexpected_extra_main_change_is_blocked(self):
        p = self.root / MAIN
        p.write_bytes(p.read_bytes()+b"// unreviewed\n")
        self.assertEqual(self.check()["status"], "BLOCKED")

    def test_duplicate_include_is_blocked(self):
        p=self.root/MAIN
        p.write_bytes(p.read_bytes()+REQUIRES[0])
        self.assertEqual(self.check()["status"], "BLOCKED")

    def test_include_outside_staging_guard_is_blocked(self):
        p=self.root/MAIN
        main=p.read_bytes()
        main=main.replace(REQUIRES[0], b"", 1) + REQUIRES[0]
        p.write_bytes(main)
        self.assertEqual(self.check()["status"], "BLOCKED")

    def test_missing_include_file_is_blocked(self):
        (self.root/INCLUDES[0]).unlink()
        r=self.check()
        self.assertEqual(r["status"], "BLOCKED")
        self.assertTrue(any("missing" in x for x in r["blockers"]))

    def test_symlink_include_is_blocked(self):
        p=self.root/INCLUDES[0]
        dest=self.root/"alternate.txt"
        dest.write_bytes(b"data")
        p.unlink()
        p.symlink_to(dest)
        self.assertEqual(self.check()["status"], "BLOCKED")

    def test_version_mismatch_is_blocked(self):
        p=self.root/MAIN
        p.write_bytes(p.read_bytes().replace(b"0.4.0-rc.118",b"0.4.0-rc.119"))
        self.assertEqual(self.check()["status"], "BLOCKED")

    def test_valid_original_manifest_no_promotion(self):
        (self.root/MAIN).write_bytes(self.baseline)
        r=self.check()
        self.assertEqual(r["status"], "EXACT_BYTES_NOT_CERTIFIED")
        self.assertFalse(r["provider_certified"])
        self.assertFalse(r["new_signed_package_produced"])

    def test_other_manifest_file_drift_is_blocked(self):
        f=self.root/"includes"/"other.php"
        f.write_bytes(b"<?php old")
        self.manifest["package_files"].append({"path":"includes/other.php","sha256":digest(b"<?php previous")})
        self.assertEqual(self.check()["status"], "BLOCKED")

    def test_duplicate_manifest_entry_is_blocked(self):
        self.manifest["package_files"].append(self.manifest["package_files"][0].copy())
        self.assertEqual(self.check()["status"], "BLOCKED")

    def test_unsafe_manifest_path_is_blocked(self):
        self.manifest["package_files"].append({"path":"../outside.txt","sha256":digest(b"0")})
        self.assertEqual(self.check()["status"], "BLOCKED")

if __name__=="__main__":
    unittest.main()
