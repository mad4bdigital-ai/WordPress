#!/usr/bin/env python3
"""No network, WordPress identity impersonation, or live filesystem mutations."""
from __future__ import annotations
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest

SRC=Path(__file__).resolve().parents[2]
file=SRC/"ops/host-anchor-v109/owner_admin_asset_permissions.py"
spec=importlib.util.spec_from_file_location("host_v109_permissions",file)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class ExactOwnerAdminPermissions(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.root=Path(self.tmp.name)/"wordpress"
        p=self.root/"wp-content/mu-plugins/mad4b-host-authority-owner-ui"
        p.mkdir(parents=True,exist_ok=True)
        for rel in (m.PHP_REL,m.JS_REL):
            src=SRC/rel
            dest=self.root/rel
            dest.write_bytes(src.read_bytes())
            dest.chmod(0o644)
        self.mu=self.root/m.MU_REL
        # Simulate trusted group from installed mu-plugins parent:
        os.chown(self.mu,0,33)
        self.mu.chmod(0o770)
        os.chown(p,0,0)
        p.chmod(0o700)
    def tearDown(self):
        self.tmp.cleanup()
    def test_detect_missing_php_worker_traversal(self):
        self.assertEqual(m.inspect(self.root)["state"],"REPAIR_REQUIRED")
        self.assertEqual(m.inspect(self.root)["directory_mode"],"0700")
    def test_repair_and_exact_readback(self):
        a=m.apply(self.root)
        self.assertEqual(a["state"],"READABLE_BY_PHP_GROUP")
        self.assertEqual(a["directory_mode"],"0750")
        self.assertEqual(a["target_group_gid"],33)
        p=self.root/m.DIR_REL
        self.assertEqual((p.stat().st_uid,p.stat().st_gid,
                          stat.S_IMODE(p.stat().st_mode)),(0,33,0o750))
        self.assertFalse(a["host_authority_issued"])
    def test_idempotent_repair(self):
        before=m.apply(self.root)
        after=m.apply(self.root)
        self.assertEqual(before,after)
    def test_reject_tampered_js(self):
        f=self.root/m.JS_REL
        f.write_text("bad")
        with self.assertRaises(m.AdmissionError):m.apply(self.root)
        self.assertEqual(stat.S_IMODE((self.root/m.DIR_REL).stat().st_mode),0o700)
    def test_reject_symlinked_asset(self):
        path=self.root/m.JS_REL
        path.unlink()
        path.symlink_to(SRC/m.JS_REL)
        with self.assertRaises(m.AdmissionError):m.inspect(self.root)
    def test_reject_symlinked_directory(self):
        path=self.root/m.DIR_REL
        path2=path.with_name("other-dir")
        path.rename(path2)
        path.symlink_to(path2)
        with self.assertRaises(m.AdmissionError):m.inspect(self.root)
    def test_reject_different_admin_php(self):
        f=self.root/m.PHP_REL
        f.write_text("<?php wrong")
        with self.assertRaises(m.AdmissionError):m.inspect(self.root)

if __name__=="__main__":
    unittest.main()
