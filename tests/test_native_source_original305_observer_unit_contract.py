"""No second signer; observation and diagnostic are segregated from release."""
from pathlib import Path
import ast,unittest
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'ops/native-source-publisher-v1'
class ObserverPolicy(unittest.TestCase):
 def test_static_non_authoritative_contract(self):
  s=(BASE/'original305_runtime_observer_v1.py').read_text();ast.parse(s)
  for part in ("OPTION='mad4b_original305_runtime_observation_v1'",'TRIAGE_SHA=',
      'REGISTRY_SHA=',"'host_release_authorized':False", "'approval_created':False",
      "'incident_closed':False", "sha(HOLD)", 'ORIGINAL305_HOLD_CHANGED_DURING_OBSERVATION',
      'READONLY_TRIAGE_SAFETY_CONTRACT_INVALID'):
   self.assertIn(part,s)
  for forbidden in ('original_signer(', "'--refresh'",'HOLD.unlink(', 'HOLD.rename(',
       'systemctl enable', 'wp_set_current_user(', 'mad4b_original_host_source_readiness_v1'):
   self.assertNotIn(forbidden,s)
 def test_only_periodic_diagnostics_timer(self):
  service=(BASE/'systemd/mad4b-original305-observer.service').read_text()
  timer=(BASE/'systemd/mad4b-original305-observer.timer').read_text()
  self.assertIn('original305_runtime_observer_v1.py --publish',service)
  self.assertIn('NoNewPrivileges=true',service)
  self.assertIn('OnUnitInactiveSec=10min',timer)
  self.assertNotIn('original305_readiness_supervisor',service+timer)
  self.assertNotIn('original_broker_host_readiness_refresh',service+timer)
if __name__=='__main__':unittest.main()
