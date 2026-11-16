"""Pinned Broker V8 + Supervisor V7 cannot latch HOLD on definitive prewrite denials."""
from pathlib import Path
import ast
import unittest
BASE=Path(__file__).resolve().parents[1]/'ops/native-source-publisher-v1'
class V7PhaseSource(unittest.TestCase):
 def test_exact_v8_registered_source_and_phase_coded_policy(self):
  code=(BASE/'original305_readiness_supervisor_v7.py').read_text();ast.parse(code)
  self.assertIn("ISSUER='native-source-publisher-v1/original_broker_host_readiness_refresh_v8.py'",code)
  self.assertIn("MANIFEST='dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.json'",code)
  self.assertIn("require(manifest.get('version')==8",code)
  self.assertIn("p.returncode==3 and message.get('state')=='ORIGINAL_BROKER_READINESS_DENIED'",code)
  self.assertIn("return verdict('DEFINITIVE_PREWRITE_DENIED'",code)
  self.assertIn("return verdict('RETRYABLE_PREWRITE_HOLD'",code)
  self.assertIn("sticky_hold('ISSUER_SIGNED_RESULT_UNCONFIRMED')",code)
  self.assertNotIn('HOLD.unlink(',code)
 def test_v6_to_v7_original_cas_preimages_and_no_timer_enable(self):
  code=(BASE/'firstparty_upgrade_original305_readiness_supervisor_v7.py').read_text();ast.parse(code)
  for tok in ("SHA_REG='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'", "SHA_OLD='be76afdba64e19794e5deac7049d3427be73c4c601ef2ab1bf88a33749dd2b32'", "SHA_SERV='94450c714cab69f3651148fc706cc4194bd61d977fcd4133218776cf38443f6b'", "code=blobs['native-source-publisher-v1/original305_readiness_supervisor_v7.py']", "unit=blobs['native-source-publisher-v1/systemd/mad4b-original-broker-host-readiness.v7.DYNAMIC.service']", 'SOURCE_NOT_EXACT_GIT_BLOB', 'CAS_CHANGED_BEFORE_SERVICE_SWAP', 'timer_disabled()'):
   self.assertIn(tok,code)
  self.assertIn("SHA_TIMER='d36eb23a554185e9158118d1430e93d324fe2f4eff26e28cbeca024a9ff45383'",code)
  for bad in ('HOLD.unlink(',"'enable','--now'",'original_signer('):
   self.assertNotIn(bad,code)
 def test_existing_single_service_and_no_second_timer(self):
  unit=(BASE/'systemd/mad4b-original-broker-host-readiness.v7.DYNAMIC.service').read_text()
  self.assertIn('original305_readiness_supervisor_v7.py',unit)
  self.assertIn('NoNewPrivileges=true',unit)
  self.assertNotIn('worktrees/',unit)
  self.assertNotIn('original_broker_host_readiness_refresh_v6.py',unit)
if __name__=='__main__':unittest.main()
