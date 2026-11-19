"""First-party original305 systemd source CAS, stable provider identity."""
from pathlib import Path
import ast,unittest
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'ops/native-source-publisher-v1'
class DynamicUnits(unittest.TestCase):
 def test_exact_preimages_and_cas_rollback(self):
  s=(BASE/'firstparty_upgrade_original305_dynamic_units_v8.py').read_text();ast.parse(s)
  for text in ("PIN_REG='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'",'1c07d5d24187cafd6ebdec490fbc9248896243ee5896eadd736d23bf3e3e33bf','9c8942ea63661210457209d4d5e086472cae5b81a9acd37d315221cd09f971b0','GIT_BLOB_DIFFERENT','SYSTEMD_PREIMAGE_CAS_DRIFT','TIMER_MUST_BE_DISABLED','TIMER_UNEXPECTEDLY_ENABLED','atomically_replace(target,original[k])'):
   self.assertIn(text,s)
 def test_service_uses_supervisor_enrolled_issuer_no_old_hardcode(self):
  service=(BASE/'systemd/mad4b-original-broker-host-readiness.v8.DYNAMIC.service').read_text()
  timer=(BASE/'systemd/mad4b-original-broker-host-readiness.v8.DYNAMIC.timer').read_text()
  self.assertIn('original305_readiness_supervisor_v6.py',service)
  self.assertNotIn('ConditionPathExists=',service)
  self.assertNotIn('original_broker_host_readiness_refresh_v6.py',service)
  self.assertIn('NoNewPrivileges=true',service)
  self.assertIn('enrolled original Provider Registry',timer)
  self.assertNotIn('issuer V6',timer)
 def test_installer_does_not_change_hold_or_sign_or_enable(self):
  s=(BASE/'firstparty_upgrade_original305_dynamic_units_v8.py').read_text()
  for forbidden in ('HOLD.unlink(', 'original_signer(', "'enable','--now'", 'update_option('):self.assertNotIn(forbidden,s)
if __name__=='__main__':unittest.main()
