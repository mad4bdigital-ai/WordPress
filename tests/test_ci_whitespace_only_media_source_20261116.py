"""Source-only, non-authorizing review of PHP service budget normalization."""
import subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='144ba0d5fd590e34be38263393ae2ce8f28284ce'
REVIEWED=(
 ('wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-remote-media-adapter.php',900),
 ('wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-experience-media-rights.php',180),
)
class WhitespaceOnlyProof(unittest.TestCase):
 def test_actual_original_git_objects_and_nonblank_source_equivalent(self):
  for path,budget in REVIEWED:
   with self.subTest(path=path):
    before=subprocess.run(['git','-C',str(ROOT),'show',BASE+':'+path],capture_output=True,check=True,timeout=20).stdout
    after=(ROOT/path).read_bytes()
    self.assertNotEqual(before,after)
    self.assertEqual([x for x in before.splitlines() if x.strip()],
                     [x for x in after.splitlines() if x.strip()])
    self.assertLessEqual(len(after.splitlines()),budget)
    self.assertEqual(before.count(b'<?php'),after.count(b'<?php'))
if __name__=='__main__':unittest.main()
