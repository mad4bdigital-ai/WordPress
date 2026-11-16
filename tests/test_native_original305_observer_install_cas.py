"""First-party observer is independent of original signer and approval rules."""
from pathlib import Path
import ast,unittest
ROOT=Path(__file__).resolve().parents[1]
FILE=ROOT/'ops/native-source-publisher-v1/firstparty_install_original305_observer_v1.py'
class InstallContract(unittest.TestCase):
 def test_exact_git_source_registry_hold_pins(self):
  s=FILE.read_text();ast.parse(s)
  for token in ("PIN_REG='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'",
    "PIN_HOLD='21f299380f89b803e70e463541ff953fa5e67f6c3eef963fe6925210aa8c1695'",
    'OBSERVER_SOURCE_NOT_COMMITTED','ORIGINAL_SIGNER_TIMER_MUST_STAY_DISABLED',
    'OBSERVER_DESTINATION_ALREADY_USED','HISTORICAL_HOLD_CHANGED',
    'WORDPRESS_OBSERVER_NOT_VERIFIED','ORIGINAL_SECURITY_BOUNDARY_CHANGED'):
   self.assertIn(token,s)
 def test_additive_timer_without_signer_operation(self):
  s=FILE.read_text()
  self.assertIn("run('systemctl','enable','--now','mad4b-original305-observer.timer'",s)
  self.assertNotIn('mad4b-original-broker-host-readiness.timer\',timeout=',s)
  self.assertNotIn('HOLD.unlink(',s)
  self.assertNotIn('original_signer(',s)
  self.assertIn('if enabled:subprocess.run',s)
if __name__=='__main__':unittest.main()
