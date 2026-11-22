from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
D=ROOT/"ops/host-authority-v144"
class ServiceContractTests(unittest.TestCase):
 def test_service_is_readonly_and_ssh_scoped(self):
    s=(D/"mad4b-host-v144-ssh-remote-repair.service").read_text()
    self.assertIn("ExecStart=/usr/bin/python3 -B",s)
    self.assertIn("vps_host_ssh_repair.py publish",s)
    self.assertIn("ProtectSystem=strict",s)
    self.assertIn("NoNewPrivileges=yes",s)
    self.assertIn("RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6",s)
    self.assertIn("ReadWritePaths=/opt/mad4b-company-transport/host-authority-v144-ssh-repair-readonly",s)
    self.assertNotIn("shell_exec",s)
    self.assertNotIn("systemctl restart",s)
 def test_timer_excludes_owner_machine(self):
    s=(D/"mad4b-host-v144-ssh-remote-repair.timer").read_text()
    self.assertIn("OnCalendar=*-*-* *:15,45:00",s)
    self.assertIn("Persistent=true",s)
    self.assertIn("Unit=mad4b-host-v144-ssh-remote-repair.service",s)
    self.assertNotIn("device",s)
if __name__=="__main__":unittest.main()
