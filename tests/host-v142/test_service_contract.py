from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
DIR=ROOT/"ops/host-authority-v142"
class ServiceContract(unittest.TestCase):
 def test_unit_has_no_signer_and_confines_writes(self):
    source=(DIR/"mad4b-host-v142-single-owner-readiness.service").read_text()
    self.assertIn("ExecStart=/usr/bin/python3 -B",source)
    self.assertIn("--publish",source)
    self.assertIn("ProtectSystem=strict",source)
    self.assertIn("NoNewPrivileges=yes",source)
    self.assertIn("ReadWritePaths=/opt/mad4b-company-transport/host-authority-v142-single-owner-readiness-readonly",source)
    for forbidden in ("sudo ", "chattr ", "wp option update", "--grant",
      "original305_v3_owner_provider.py", "original312_v3_issuer.py"):
      self.assertNotIn(forbidden,source)
 def test_timer_never_replays_owner_issuer(self):
    s=(DIR/"mad4b-host-v142-single-owner-readiness.timer").read_text()
    self.assertIn("OnCalendar=*-*-* *:00/30:00",s)
    self.assertIn("Persistent=true",s)
    self.assertIn("RandomizedDelaySec=45",s)
    self.assertIn("Unit=mad4b-host-v142-single-owner-readiness.service",s)
if __name__=="__main__":unittest.main()
