"""Read-only successor-manifest audit; NEVER an enrolled or signed release."""
from pathlib import Path
from hashlib import sha256
import json, unittest
ROOT=Path(__file__).resolve().parents[1]
DIR=ROOT/'ops/dynamic-operation-registry-v1/providers/host.source.release'
V8=DIR/'manifest.v8.CANDIDATE.json'
V9=DIR/'manifest.v9.CANDIDATE.json'
def checksum(p): return sha256(p.read_bytes()).hexdigest()
class V9SuccessorCandidate(unittest.TestCase):
 def test_v8_signed_source_identity_unchanged(self):
  self.assertEqual(checksum(V8),'be9457e6ea2a93f7b6ccc1083e9c68aa2cc9cd29832a7754ea23503570c248bc')
 def test_v9_only_replaces_reviewed_company_loader_pin(self):
  before=json.loads(V8.read_text());after=json.loads(V9.read_text())
  self.assertEqual((before['version'],after['version']),(8,9))
  base={v['path']:v['sha256'] for v in before['dependencies']}
  changed={v['path']:v['sha256'] for v in after['dependencies']}
  self.assertEqual(set(base),set(changed))
  self.assertEqual([p for p in base if base[p]!=changed[p]],['company-code-release-v1/company_code_release.py'])
  for field in set(before)-{'version','dependencies'}:
   self.assertEqual(before[field],after[field],field)
  pins={**changed}
  for item in ('handler','verifier','finalizer','evidence'):
   rec=after[item]
   if rec['path'] in pins:self.assertEqual(pins[rec['path']],rec['sha256'])
   pins[rec['path']]=rec['sha256']
  self.assertEqual(len(pins),27)
  for path,digest in pins.items():
   file=ROOT/'ops'/path
   with self.subTest(path=path):
    self.assertTrue(file.is_file());self.assertEqual(checksum(file),digest)
 def test_successor_is_not_runtime_authority(self):
  p=DIR/'manifest.json'
  self.assertFalse(p.exists())
  v9=json.loads(V9.read_text());self.assertEqual(v9['operation_id'],'host.source.release')
  self.assertEqual(v9['command']['format'],'host.source.ref.v1')
  source=(ROOT/'ops/company-code-release-v1/company_code_release.py').read_text()
  self.assertIn('def native_mad4b_mu_root_loader',source)
  self.assertIn('dynamic_scope_plan_matched',source)
if __name__=='__main__':unittest.main()
