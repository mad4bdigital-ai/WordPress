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
 def test_preexisting_69_preserved_except_twenty_two_exact_reviewed_changes(self):
  import subprocess,hashlib
  self.assertEqual(M['base_git_commit'],'cea270f57bb8152989c6089f76405b165652f9df')
  safe=json.loads((ROOT/'docs/release-coverage/semantic-additive-safe-reconcile-20261111.json').read_text())
  country=json.loads((ROOT/'docs/release-coverage/country-switcher-privacy-and-entitlement-reconcile-20261111.json').read_text())
  allowed={row['path']:row['verified_current_live_sha256'] for row in safe['files']}
  allowed[country['php_path']]=country['reconciled_php_sha256']
  allowed[country['js_path']]=country['live_js_sha256']
  pricing=json.loads((ROOT/'docs/release-coverage/pricing-and-media-semantic-review-20261111.json').read_text())
  for entry in pricing['files']:
   if entry['path'].startswith('wp-content/plugins/mad4b-pricing-registry/'):
    allowed[entry['path']]=entry.get('candidate_sha256',entry.get('reviewed_live_sha256'))
  ages=json.loads((ROOT/'docs/release-coverage/age-occupancy-live-semantic-reconcile-20261111.json').read_text())
  for item in ages['files']:
   allowed[item['path']]=item['candidate_sha256']
  historical=json.loads((ROOT/'docs/release-coverage/portfolio-historical-install-intent-provenance-20261111.json').read_text())
  for item in historical['files']:
   allowed[item['path']]=item['candidate_sha256']
  wpml=json.loads((ROOT/'docs/release-coverage/scp-wpml-origin-and-format-source-20261111.json').read_text())
  for item in wpml['files']:
   allowed[item['path']]=item['candidate_sha256']
  self.assertEqual(len(allowed),22)
  reviewed=set()
  for group in M['preexisting_semantic_conflicts'].values():
   for path in group:
    before=subprocess.run(['git','-C',str(ROOT),'show',
      M['base_git_commit']+':'+path],capture_output=True,timeout=20)
    self.assertEqual(before.returncode,0,path)
    current=(ROOT/path).read_bytes()
    if path in allowed:
     self.assertEqual(hashlib.sha256(current).hexdigest(),allowed[path],path)
     reviewed.add(path)
    else:
     self.assertEqual(before.stdout,current,path)
  self.assertEqual(reviewed,set(allowed))
  self.assertEqual(len(reviewed),22)
 def test_exact_preexisting_conflicts_not_copied(self):
  c={item for values in M['preexisting_semantic_conflicts'].values() for item in values}
  self.assertEqual(len(c),69)
  self.assertFalse(c&{r['path'] for r in M['files']})
if __name__=='__main__':unittest.main()
