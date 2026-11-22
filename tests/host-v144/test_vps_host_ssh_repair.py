from pathlib import Path
import importlib.util
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"ops/host-authority-v144/vps_host_ssh_repair.py"
spec=importlib.util.spec_from_file_location("v144_readonly_remote",SRC)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class VPSRemoteRepairPolicy(unittest.TestCase):
 def probe(self,target,ok=True):
    return {"target_id":target,"ssh_authenticated":ok}
 def test_ssh_transport_does_not_grant_write(self):
    for t in m.TARGETS:
      for p in m.PROBLEMS:
       with self.subTest(target=t,problem=p):
        x=m.scoped_plan(t,p,self.probe(t))
        self.assertFalse(x["write_authorized"])
        self.assertFalse(x["auto_repair_write_executed"])
        self.assertTrue(x["central_signed_exact_grant_required"])
        self.assertFalse(x["host_ssh_key_is_owner_stepup_signature"])
        self.assertFalse(x["owner_workstation_ssh_required"] if "owner_workstation_ssh_required" in x else x["owner_device_ssh_required"])
 def test_unregistered_target_is_denied(self):
    with self.assertRaises(m.RepairDenied):
       m.scoped_plan("hosting-4","wp_runtime",self.probe("hosting-4"))
 def test_unrecognized_problem_is_denied(self):
    with self.assertRaises(m.RepairDenied):
       m.scoped_plan("hosting-2","arbitrary_command",self.probe("hosting-2"))
 def test_no_company_binding_for_hosting1(self):
    x=m.scoped_plan("hosting-1","source_release",self.probe("hosting-1"))
    self.assertEqual(x["decision"],"COMPANY_BINDING_REQUIRED_FOR_ANY_WRITE_GRANT")
    self.assertFalse(x["valid_company_scope_verified"])
 def test_independent_original_signer_not_bypassed(self):
    for problem in ("original313_predecessor","original305_owner",
                    "original312_issuer","original318_readback"):
      x=m.scoped_plan("hosting-2",problem,self.probe("hosting-2"))
      self.assertEqual(x["decision"],"ROOT_FIRSTPARTY_ISSUER_DIAGNOSTIC_ONLY")
 def test_ssh_down_blocks_repair_even_if_requested(self):
    x=m.scoped_plan("hosting-2","wp_runtime",self.probe("hosting-2",False))
    self.assertEqual(x["decision"],"TRANSPORT_REQUIRES_REPAIR_BEFORE_REMOTE_OPERATOR")
 def test_public_original_file_pins_root_owned(self):
    self.assertEqual(len(m.ADAPTER_SHA256),64)
    self.assertEqual(len(m.INVENTORY_SHA256),64)
    self.assertEqual(len(m.HOSTING2["known_sha256"]),64)
 def test_ssh_never_autopins_or_runs_arbitrary_commands(self):
    s=SRC.read_text()
    for bad in ("StrictHostKeyChecking=no","StrictHostKeyChecking=accept-new",
                "ssh-keyscan","shell=True","eval(","exec(","os.system(",
                "sodium_crypto_sign","Ed25519PrivateKey.generate",
                "register_rest_route(","update_option("):
      self.assertNotIn(bad,s)
    self.assertIn('"-o","BatchMode=yes"',s)
    self.assertIn('"-o","StrictHostKeyChecking=yes"',s)
    self.assertIn('"printf "+MARKER',s)

if __name__=="__main__":unittest.main()
