import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('four_branch_source',
 ROOT/'tools/verify_four_local_branch_source_overlay.py')
m=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(m)
DATA=json.loads(m.MANIFEST.read_text())
class SourcePins(unittest.TestCase):
 def test_exact_git_blobs(self):
  report=m.verify(DATA)
  self.assertEqual(report['unique_files_verified'],103)
  self.assertFalse(report['release_approved'])
 def test_no_source_promotion(self):
  d=copy.deepcopy(DATA);d['systemd_installed']=True
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_digest_drift(self):
  d=copy.deepcopy(DATA);d['files'][0]['sha256']='0'*64
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_path_escape(self):
  d=copy.deepcopy(DATA);d['files'][0]['path']='../../etc/passwd'
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_branch_pin_drift(self):
  d=copy.deepcopy(DATA);branch=next(iter(d['source_pins']));d['source_pins'][branch]='f'*40
  with self.assertRaises(m.Blocked):m.verify(d)
if __name__=='__main__':unittest.main()
