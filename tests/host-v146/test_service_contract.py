from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
D=ROOT/'ops/host-authority-v146'
class UnitTests(unittest.TestCase):
 def test_readonly_root_runner_sandbox(self):
  s=(D/'mad4b-host-v146-external-sites-readback.service').read_text()
  self.assertIn('ProtectSystem=strict',s)
  self.assertIn('NoNewPrivileges=yes',s)
  self.assertIn('ReadWritePaths=/opt/mad4b-company-transport/host-authority-v146-external-sites-readonly',s)
  self.assertIn('ExecStart=/usr/bin/python3 -B',s)
  self.assertNotIn('wp option update',s)
  self.assertNotIn('ssh -o StrictHostKeyChecking=no',s)
 def test_timer_hourly(self):
  s=(D/'mad4b-host-v146-external-sites-readback.timer').read_text()
  self.assertIn('OnCalendar=*-*-* *:25:00',s)
  self.assertIn('Persistent=true',s)
if __name__=='__main__':unittest.main()
