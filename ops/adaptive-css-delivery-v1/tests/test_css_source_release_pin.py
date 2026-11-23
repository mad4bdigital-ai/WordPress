"""Hermetic site-owned CSS renderer source release admission tests."""
import ast,hashlib,pathlib,tempfile,shutil,sys,unittest
BASE=pathlib.Path(__file__).resolve().parents[1]
REPO=pathlib.Path(sys.argv[1]) if len(sys.argv)>1 else BASE.parents[1]
SRC=REPO/'wp-content/mu-plugins'
SCRIPT=REPO/'ops/dynamic-critical-css-20261116/run-public-route.py'
CURRENT='13e98efebaef87918d38c1d5be30bd6cf45c9f40c0e3264a31beca79b4663a36'
CONSUMER='249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1'
COVERAGE='ae7936c7ad09b059375e1960d4c59144af0b4325933db34bcfa63c26e49233bb'
NAMES=['421-mad4b-public-critical-css-coverage.php','422-mad4b-public-critical-css-runtime.php',
       '422-mad4b-public-critical-css-v2-consumer.php']
def H(b):return hashlib.sha256(b).hexdigest()
def load():
 t=ast.parse(SCRIPT.read_text())
 functions=[n for n in t.body if isinstance(n,ast.FunctionDef) and n.name in
     ('approved_native_runtime_sha','approved_v2_consumer_sha')]
 assert len(functions)==2
 code=compile(ast.Module(body=functions,type_ignores=[]),str(SCRIPT),'exec')
 scope={'hashlib':hashlib,'Path':pathlib.Path}
 exec(code,scope)
 return scope
class TestReleasePin(unittest.TestCase):
 def setUp(self):
  self.m=tempfile.TemporaryDirectory();self.addCleanup(self.m.cleanup)
  self.root=pathlib.Path(self.m.name)
  (self.root/'mu-plugins').mkdir()
  for n in NAMES:
   shutil.copy2(SRC/n,self.root/'mu-plugins'/n)
  self.scope=load()
  for name,pin in zip(NAMES,(COVERAGE,CURRENT,CONSUMER)):
   self.assertEqual(H((SRC/name).read_bytes()),pin,name)
  self.scope['CONFIG']={
   'source':('tours.dev.mad4b.com','php-source',self.root,'staging'),
   'wayfa':('wayfatours.com','php-wayfa',self.root,'production')}
 def test_current_release_both_sites(self):
  for site in ('source','wayfa'):
   with self.subTest(site=site):
    runtime=self.scope['approved_native_runtime_sha'](site,self.root)
    self.assertEqual(runtime,CURRENT)
    self.assertEqual(self.scope['approved_v2_consumer_sha'](site,self.root,runtime),CONSUMER)
 def test_bad_companion_blocks_release(self):
  p=self.root/'mu-plugins'/NAMES[2];p.write_bytes(b'unreleased-companion')
  for site in ('source','wayfa'):
   with self.subTest(site=site):
    with self.assertRaisesRegex(ValueError,'PAIR_DRIFT'):
     self.scope['approved_native_runtime_sha'](site,self.root)
 def test_unknown_runtime_fails_closed(self):
  p=self.root/'mu-plugins'/NAMES[1];p.write_bytes(b'drifted-runtime')
  for site in ('source','wayfa'):
   with self.subTest(site=site):
    with self.assertRaisesRegex(ValueError,'SOURCE_LIVE_NATIVE_COMPONENT_DRIFT'):
     self.scope['approved_native_runtime_sha'](site,self.root)
 def test_coverage_must_match(self):
  p=self.root/'mu-plugins'/NAMES[0];p.write_bytes(b'drifted-coverage')
  for site in ('source','wayfa'):
   with self.subTest(site=site):
    with self.assertRaisesRegex(ValueError,'SOURCE_LIVE_NATIVE_COMPONENT_DRIFT'):
     self.scope['approved_native_runtime_sha'](site,self.root)
 def test_companion_sha_cannot_self_attest(self):
  self.assertIsNone(self.scope['approved_v2_consumer_sha']('source',self.root,'c'*64))
  p=self.root/'mu-plugins'/NAMES[2];p.write_bytes(b'tampered')
  with self.assertRaisesRegex(ValueError,'MULTI_CSS_V2_COMPANION_RELEASE_SHA_UNAPPROVED'):
   self.scope['approved_v2_consumer_sha']('wayfa',self.root,CURRENT)
 def test_wrong_site_root_rejected(self):
  with self.assertRaisesRegex(ValueError,'COMPANY_ROOT_MISMATCH'):
   self.scope['approved_native_runtime_sha']('source',pathlib.Path('/tmp'))
 def test_legacy_wayfa_consumer_maintained(self):
  self.assertEqual(self.scope['approved_v2_consumer_sha']('wayfa',self.root,
    '597a31f0ea962d2700ead68f9856596727a4e07055650705f52ba57bbaf11119'),CONSUMER)
if __name__=='__main__':
 unittest.main(argv=[sys.argv[0]],verbosity=2)
