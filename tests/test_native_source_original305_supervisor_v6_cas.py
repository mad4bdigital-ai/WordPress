"""Original V5 to V6 pinned CAS / bounded independent readback guard."""
from pathlib import Path
import ast,unittest
P=Path(__file__).resolve().parents[1]/'ops/native-source-publisher-v1'
class V6(unittest.TestCase):
 def test_cas_exact_v8_registry_and_v5_preimages(self):
  s=(P/'firstparty_upgrade_original305_readiness_supervisor_v6.py').read_text();ast.parse(s)
  for phrase in ("SHA_REG='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'", "SHA_OLD='7d6c2b6a2c3e84c04a4856dec52efbf9ee1830cb50311c24837d82cfe306dcdb'", "SHA_SERV='cc9cefac12d9ed590b97ca628fbcd931d17558ea36539bb4c006bc5a6f0ca154'", "code=blobs['native-source-publisher-v1/original305_readiness_supervisor_v6.py']", "unit=blobs['native-source-publisher-v1/systemd/mad4b-original-broker-host-readiness.v6.CANDIDATE.service']", "OLD=HOST/'original305_readiness_supervisor_v5.py'", 'timer_disabled()', 'CAS_CHANGED_BEFORE_SERVICE_SWAP', 'if updated and sha(SERV.read_bytes())==sha(unit):atomic(SERV,backup.read_bytes(),0o644)'):
   self.assertIn(phrase,s)
 def test_no_second_timer_no_hold_delete_and_original_broker_source_pin(self):
  s=(P/'original305_readiness_supervisor_v6.py').read_text();ast.parse(s)
  self.assertIn("require(manifest.get('version')==8",s)
  self.assertIn("ISSUER='native-source-publisher-v1/original_broker_host_readiness_refresh_v8.py'",s)
  self.assertIn("MANIFEST='dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.json'",s)
  self.assertIn('independently_confirm_ambiguous_delivery',s)
  self.assertNotIn('HOLD.unlink(',s)
  self.assertNotIn('original_signer()',s)
  unit=(P/'systemd/mad4b-original-broker-host-readiness.v6.CANDIDATE.service').read_text()
  self.assertIn('TimeoutStartSec=360',unit)
  self.assertNotIn('worktrees/',unit)
if __name__=='__main__':unittest.main()
