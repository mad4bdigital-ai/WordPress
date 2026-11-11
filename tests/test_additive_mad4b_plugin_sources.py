import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('additive_plugin_guard',
 ROOT/'tools/verify_additive_mad4b_plugin_sources.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
M=json.loads(m.MANIFEST.read_text())
class AdditivePlugins(unittest.TestCase):
 def test_source_exact_and_not_activated(self):
  r=m.verify(M)
  self.assertEqual(r['file_count'],238)
  self.assertEqual(r['preexisting_semantic_conflicts_kept'],69)
  self.assertFalse(r['release_authorized'])
 def test_no_automatic_activation_claim(self):
  x=copy.deepcopy(M);x['plugin_activation_performed']=True
  with self.assertRaises(m.Blocked):m.verify(x)
 def test_source_hash_drift_fails(self):
  x=copy.deepcopy(M);x['files'][0]['sha256']='0'*64
  with self.assertRaises(m.Blocked):m.verify(x)
 def test_path_escape_fails(self):
  x=copy.deepcopy(M);x['files'][0]['path']='wp-content/plugins/mad4b-x/../../../etc/passwd'
  with self.assertRaises(m.Blocked):m.verify(x)
 def test_preexisting_69_candidate_files_preserved_by_sha(self):
  import subprocess
  self.assertEqual(M['base_git_commit'],'cea270f57bb8152989c6089f76405b165652f9df')
  n=0
  for group in M['preexisting_semantic_conflicts'].values():
   for path in group:
    before=subprocess.run(['git','-C',str(ROOT),'show',
      M['base_git_commit']+':'+path],capture_output=True,timeout=20)
    self.assertEqual(before.returncode,0,path)
    self.assertEqual(before.stdout,(ROOT/path).read_bytes(),path)
    n+=1
  self.assertEqual(n,69)
 def test_exact_preexisting_conflicts_not_copied(self):
  c={item for values in M['preexisting_semantic_conflicts'].values() for item in values}
  self.assertEqual(len(c),69)
  self.assertFalse(c&{r['path'] for r in M['files']})
if __name__=='__main__':unittest.main()
