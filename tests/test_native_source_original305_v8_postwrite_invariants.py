"""Portable V8 original readback and additive first-party upgrade contract."""
from pathlib import Path
import ast,json,hashlib,unittest
BASE=Path(__file__).resolve().parents[1]/'ops'
P=BASE/'native-source-publisher-v1';M=BASE/'dynamic-operation-registry-v1/providers/host.source.release'
class Original305V8Invariant(unittest.TestCase):
 def test_additive_manifest_and_exact_pin(self):
  v7=json.loads((M/'manifest.v7.CANDIDATE.json').read_text());v8=json.loads((M/'manifest.v8.CANDIDATE.json').read_text())
  self.assertEqual(v7['dependencies'],v8['dependencies'][:-1])
  self.assertEqual((v7['version'],v8['version']),(7,8))
  self.assertEqual(v8['dependencies'][-1]['path'],'native-source-publisher-v1/original_broker_host_readiness_refresh_v8.py')
  self.assertEqual(v8['dependencies'][-1]['sha256'],hashlib.sha256((P/'original_broker_host_readiness_refresh_v8.py').read_bytes()).hexdigest())
  self.assertEqual({k:v for k,v in v7.items() if k not in ('version','dependencies')},{k:v for k,v in v8.items() if k not in ('version','dependencies')})
 def test_postwrite_is_ambiguous_and_keeps_original_signer(self):
  s=(P/'original_broker_host_readiness_refresh_v8.py').read_text();ast.parse(s)
  self.assertIn('BrokerWriteResultUnknown(',s)
  self.assertIn('ORIGINAL305_SIGNED_POSTWRITE_ROOT_READBACK_UNCONFIRMED',s)
  self.assertIn('broker.original_signer()',s)
  self.assertIn('broker.check_original_wordpress_signer(key)',s)
  self.assertNotIn('shell=True',s)
 def test_registry_install_is_v7_to_v8_with_verified_rollback(self):
  s=(BASE/'dynamic-operation-registry-v1/upgrade_original_host_source_provider_v8.py').read_text();ast.parse(s)
  self.assertIn("BASE_SHA='f37b01faf36381a4e01b1220c006545e65cd7cebda85038b63c757bc9811dc08'",s)
  self.assertIn("require(old['version']==7",s)
  self.assertIn("require(enrolled.resolve(OP)['version']==8 and",s)
  self.assertIn('if changed and INDEX.read_bytes()==after:atomic(INDEX,before)',s)
 def test_v6_supervisor_never_retries_signer_for_uncertain_write(self):
  s=(P/'original305_readiness_supervisor_v6.py').read_text();ast.parse(s)
  self.assertIn("ISSUER='native-source-publisher-v1/original_broker_host_readiness_refresh_v8.py'",s)
  self.assertIn("require(manifest.get('version')==8",s)
  self.assertIn('independently_confirm_ambiguous_delivery',s)
  self.assertIn('sleeper(2)',s)
  self.assertNotIn('HOLD.unlink(',s)
  u=(P/'systemd/mad4b-original-broker-host-readiness.v6.CANDIDATE.service').read_text()
  self.assertIn('original305_readiness_supervisor_v6.py',u)
  self.assertIn('TimeoutStartSec=360',u)
  self.assertNotIn('worktrees/',u)
if __name__=='__main__':unittest.main()
