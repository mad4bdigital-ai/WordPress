import copy,importlib.util,json,unittest,subprocess,tarfile,tempfile,hashlib
from pathlib import Path, PurePosixPath
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('additive_plugin_guard',
 ROOT/'tools/verify_additive_mad4b_plugin_sources.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
M=json.loads(m.MANIFEST.read_text())
# A 2026-11-11 historical source-only contract, not the current deployable tree.
# The source manifest was captured before the later Cache Lifecycle private
# date-selection fix; never roll that newer production behavior back merely to
# make the archived source fingerprint match the active branch.
SOURCE_SNAPSHOT='a3164cb61d46d7e8347326dd5e3279de15d794fd'
REVIEW_SNAPSHOT='31badd1e350c033dcd6f274d031c80d295bbbdf9'

class AdditivePlugins(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.archive=tempfile.TemporaryDirectory(prefix='mad4b-archived-238-')
  cls.archived_root=Path(cls.archive.name)
  names=[r['path'] for r in M['files']]
  self_executable=['git','-C',str(ROOT),'archive','--format=tar',SOURCE_SNAPSHOT,'--',*names]
  with subprocess.Popen(self_executable,stdout=subprocess.PIPE,stderr=subprocess.PIPE) as proc:
   with tarfile.open(fileobj=proc.stdout,mode='r|') as archived:
    for member in archived:
     parts=PurePosixPath(member.name).parts
     if '..' in parts or member.name.startswith('/'):
      raise AssertionError('UNTRUSTED_HISTORICAL_ARCHIVE_PATH')
     if member.isdir():
      continue
     if not member.isfile() or member.size>1000000 or not member.name.startswith('wp-content/plugins/mad4b-'):
      raise AssertionError('UNTRUSTED_HISTORICAL_ARCHIVE_ENTRY')
     dest=cls.archived_root.joinpath(*parts)
     dest.parent.mkdir(parents=True,exist_ok=True)
     with archived.extractfile(member) as source:
      dest.write_bytes(source.read())
   assert proc.wait(timeout=25)==0,'HISTORICAL_ARCHIVE_INCOMPLETE'
  assert len(list(cls.archived_root.rglob('*.*')))==len(names),'HISTORICAL_ARCHIVE_FILE_COUNT_CHANGED'

 @classmethod
 def tearDownClass(cls):
  cls.archive.cleanup()

 def test_source_exact_and_not_activated(self):
  r=m.verify(M,root=self.archived_root)
  self.assertEqual(r['file_count'],238)
  self.assertEqual(r['preexisting_semantic_conflicts_kept'],69)
  self.assertFalse(r['release_authorized'])
 def test_no_automatic_activation_claim(self):
  x=copy.deepcopy(M);x['plugin_activation_performed']=True
  with self.assertRaises(m.Blocked):m.verify(x,root=self.archived_root)
 def test_source_hash_drift_fails(self):
  x=copy.deepcopy(M);x['files'][0]['sha256']='0'*64
  with self.assertRaises(m.Blocked):m.verify(x,root=self.archived_root)
 def test_path_escape_fails(self):
  x=copy.deepcopy(M);x['files'][0]['path']='wp-content/plugins/mad4b-x/../../../etc/passwd'
  with self.assertRaises(m.Blocked):m.verify(x,root=self.archived_root)
 def test_preexisting_69_preserved_except_twenty_two_exact_reviewed_changes(self):
  import subprocess,hashlib
  self.assertEqual(M['base_git_commit'],'cea270f57bb8152989c6089f76405b165652f9df')
  oldfile=lambda name: json.loads(subprocess.check_output(['git','-C',str(ROOT),'show',REVIEW_SNAPSHOT+':docs/release-coverage/'+name]))
  safe=oldfile('semantic-additive-safe-reconcile-20261111.json')
  country=oldfile('country-switcher-privacy-and-entitlement-reconcile-20261111.json')
  allowed={row['path']:row['verified_current_live_sha256'] for row in safe['files']}
  allowed[country['php_path']]=country['reconciled_php_sha256']
  allowed[country['js_path']]=country['live_js_sha256']
  pricing=oldfile('pricing-and-media-semantic-review-20261111.json')
  for entry in pricing['files']:
   if entry['path'].startswith('wp-content/plugins/mad4b-pricing-registry/'):
    allowed[entry['path']]=entry.get('candidate_sha256',entry.get('reviewed_live_sha256'))
  ages=oldfile('age-occupancy-live-semantic-reconcile-20261111.json')
  for item in ages['files']:
   allowed[item['path']]=item['candidate_sha256']
  historical=oldfile('portfolio-historical-install-intent-provenance-20261111.json')
  for item in historical['files']:
   allowed[item['path']]=item['candidate_sha256']
  wpml=oldfile('scp-wpml-origin-and-format-source-20261111.json')
  for item in wpml['files']:
   allowed[item['path']]=item['candidate_sha256']
  self.assertEqual(len(allowed),22)
  reviewed=set()
  for group in M['preexisting_semantic_conflicts'].values():
   for path in group:
    before=subprocess.run(['git','-C',str(ROOT),'show',
      M['base_git_commit']+':'+path],capture_output=True,timeout=20)
    self.assertEqual(before.returncode,0,path)
    current=subprocess.check_output(['git','-C',str(ROOT),'show',REVIEW_SNAPSHOT+':'+path])
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
