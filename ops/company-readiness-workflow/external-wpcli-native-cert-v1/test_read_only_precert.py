import importlib.util,unittest,json
from pathlib import Path
from unittest import mock
p=Path(__file__).with_name("read_only_precert.py")
s=importlib.util.spec_from_file_location("precert",p)
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
C="all-royal-egypt"
UUID="49c562d1-8f2f-456f-b454-26816c6ba4cb"
ORIGIN="https://staging.allroyalegypt.com"
SCOPE={"allow_operator":True,"allow_mutation":True,"allow_production_mutation":False,
 "registry_binding":"hosting-2:staging","canonical_origin":ORIGIN,
 "site_uuid":UUID,"expected_root":"/home/u948953904/domains/allroyalegypt.com/public_html/staging"}
POLICY={"contract":"mad4b.native-vps-operator.v1","revoked":False,"scopes":{C+":staging":SCOPE}}
INV={"contract":"mad4b.external-company-ssh-inventory.v1","targets":{"hosting-2":{
 "company":C,"environment":"staging","origin":ORIGIN,
 "wp_root":SCOPE["expected_root"],"username":"u948953904","hostname":"92.113.28.241","port":65002}}}
def read(cmd,timeout=50):
 if "company-native-wpcli" in cmd:
  return json.dumps({"state":"ORIGINAL_305_PERSISTENT_PAIR_ENROLLED","site_uuid":UUID})
 if "company-native-host-proof" in cmd:
  return json.dumps({"state":"HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING","verified":True})
 raise RuntimeError("TEST_FORBIDS_OTHER_COMMANDS")
def read_ssh(scope,site,argv):
 if argv[0]=="option":return ORIGIN
 if argv[0]=="eval":return json.dumps({"origin":ORIGIN,"environment":"staging","site_uuid":UUID})
 raise RuntimeError("UNEXPECTED_WPCLI_ARGUMENT")
class Test(unittest.TestCase):
 def call(self,policy=POLICY,inventory=INV,reader=read,ssh=read_ssh):
  with mock.patch.object(m,"command",side_effect=reader),mock.patch.object(m,"ssh",side_effect=ssh):
   return m.check(C,policy,inventory)
 def test_exact_site_read_only(self):
  x=self.call()
  self.assertTrue(x["independent_site_profile_readback_verified"])
  self.assertFalse(x["native_executor_certified"])
  self.assertFalse(x["original_313_certified"])
 def test_no_host_scope(self):
  with self.assertRaisesRegex(RuntimeError,"ORIGINAL_STAGING_SCOPE_NOT_ADMITTED"):
   self.call({**POLICY,"scopes":{}})
 def test_production_mutation_scope_denied(self):
  with self.assertRaisesRegex(RuntimeError,"ORIGINAL_STAGING_SCOPE_NOT_ADMITTED"):
   self.call({**POLICY,"scopes":{C+":staging":{**SCOPE,"allow_production_mutation":True}}})
 def test_wrong_registry_company(self):
  inv={**INV,"targets":{"hosting-2":{**INV["targets"]["hosting-2"],"company":"other"}}}
  with self.assertRaisesRegex(RuntimeError,"ROOT_POLICY_INVENTORY_SCOPE_MISMATCH"):
   self.call(inventory=inv)
 def test_rejected_original_grant(self):
  def deny(cmd,timeout=50):
   if "company-native-wpcli" in cmd:return '{"state":"NOT_ENROLLED","site_uuid":"'+UUID+'"}'
   return read(cmd,timeout)
  with self.assertRaisesRegex(RuntimeError,"ORIGINAL_305_OR_HOST_PROOF_NO_LONGER_ELIGIBLE"):
   self.call(reader=deny)
 def test_forged_site_uuid(self):
  def wrong(scope,site,argv):
   if argv[0]=="option":return ORIGIN
   return json.dumps({"origin":ORIGIN,"environment":"staging","site_uuid":"0"*36})
  with self.assertRaisesRegex(RuntimeError,"INDEPENDENT_CROSS_SSH_READBACK_DRIFT"):
   self.call(ssh=wrong)
 def test_origin_drift(self):
  def wrong(scope,site,argv):
   if argv[0]=="option":return "https://evil.invalid"
   return json.dumps({"origin":ORIGIN,"environment":"staging","site_uuid":UUID})
  with self.assertRaisesRegex(RuntimeError,"INDEPENDENT_CROSS_SSH_READBACK_DRIFT"):
   self.call(ssh=wrong)
if __name__=="__main__":unittest.main()
