#!/usr/bin/env python3
"""Exact regression test for the 2026-11-06 MAD4B Experience Footer coexistence source patch."""
from __future__ import annotations
import hashlib
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/'live-only-module-snapshots/mad4b-experience/src/presentation/bootstrap.php'
PATCH=ROOT/'patches/mad4b-experience-footer-coexistence-20261106.patch'
BEFORE='544378e52d6de4aa6ef374e7aff103d9a9fe69a734893fe5848245c4829f2bff'
AFTER='470b00846323ee5b22f1634991b752196d92f38942983b3c4210b556452a0061'

def sha(p:Path)->str:
    return hashlib.sha256(p.read_bytes()).hexdigest()

class FooterCoexistencePatchTests(unittest.TestCase):
    def test_snapshot_is_exact_live_reconciled_source(self):
        self.assertEqual(sha(SOURCE),AFTER)
        self.assertIn("str_starts_with($plugin,'mad4b-footer-bridge/')",SOURCE.read_text())
        self.assertIn("class_exists('MAD4B_Footer_LookFeel',false)",SOURCE.read_text())
        self.assertIn("get_site_option('active_sitewide_plugins',[])",SOURCE.read_text())

    def test_patch_is_exact_and_reversible(self):
        self.assertTrue(shutil.which('patch'),'patch executable is required')
        with tempfile.TemporaryDirectory(prefix='mad4b-footer-proof-') as folder:
            cwd=Path(folder)
            target=cwd/'src/presentation/bootstrap.php'
            target.parent.mkdir(parents=True)
            shutil.copy2(SOURCE,target)
            undo=subprocess.run(['patch','--batch','--silent','-R','-p1','-i',str(PATCH)],cwd=cwd,capture_output=True,text=True)
            self.assertEqual(undo.returncode,0,undo.stdout+undo.stderr)
            self.assertEqual(sha(target),BEFORE)
            apply=subprocess.run(['patch','--batch','--silent','-p1','-i',str(PATCH)],cwd=cwd,capture_output=True,text=True)
            self.assertEqual(apply.returncode,0,apply.stdout+apply.stderr)
            self.assertEqual(sha(target),AFTER)

if __name__=='__main__':
    unittest.main(verbosity=2)
