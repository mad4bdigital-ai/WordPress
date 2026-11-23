import importlib.util
from pathlib import Path
import hashlib
import json
import subprocess
import tempfile
import unittest
SCRIPT=Path(__file__).with_name('build_local_candidate_manifest.py')
spec=importlib.util.spec_from_file_location('local_manifest',SCRIPT)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class ReadonlyManifest(unittest.TestCase):
    def test_exact_git_and_manifest(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            subprocess.run(['git','init','-q',str(root)],check=True)
            d=root/'wp-content/plugins/mad4b-site-control-plane';d.mkdir(parents=True)
            (d/'mad4b-site-control-plane.php').write_text('<?php // fixture\n')
            (d/'includes').mkdir();(d/'includes/file.php').write_text('<?php class Example {}\n')
            (d/'tests').mkdir();(d/'tests/skip.php').write_text('<?php excluded\n')
            subprocess.run(['git','-C',str(root),'add','.'],check=True)
            subprocess.run(['git','-C',str(root),'-c','user.email=unit@example.invalid','-c','user.name=Unit','commit','-q','-m','test'],check=True)
            sha=subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD'],text=True).strip()
            out=m.build(root,sha)
            self.assertEqual(len(out['package_files']),2)
            self.assertTrue(out['requires_original_signed_post_build_receipt'])
            self.assertFalse(out['authorizing'])
            self.assertFalse(out['certified_release'])
            canonical=''.join(f"{e['path']}\0{e['bytes']}\0{e['sha256']}\n" for e in out['package_files']).encode()
            self.assertEqual(hashlib.sha256(canonical).hexdigest(),out['package_manifest_digest'])
            (d/'includes/file.php').write_text('<?php locally changed\n')
            self.assertEqual(out,m.build(root,sha),'Uncommitted working tree changes must not taint pinned source')
    def test_reject_moving_ref_and_invalid_sha(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError,'exact_sha40_required'):m.build(Path(tmp),'main')
            with self.assertRaisesRegex(ValueError,'exact_sha40_required'):m.build(Path(tmp),'a'*39)
if __name__=='__main__':unittest.main()
