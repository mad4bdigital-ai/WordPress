import importlib.util
import json
import unittest
from pathlib import Path
from unittest import mock

SOURCE=Path(__file__).with_name("company-native-host-proof-cross-ssh.py")
spec=importlib.util.spec_from_file_location("cross_host_proof",SOURCE)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
INVENTORY={
 "contract":"mad4b.external-company-ssh-inventory.v1",
 "targets":{"hosting-2":{
 "hostname":"92.113.28.241","username":"u948953904","port":65002,
 "company":"all-royal-egypt","environment":"staging",
 "origin":"https://staging.allroyalegypt.com",
 "wp_root":"/home/u948953904/domains/allroyalegypt.com/public_html/staging"}}
}
SCOPE={"registry_binding":"hosting-2:staging",
       "canonical_origin":"https://staging.allroyalegypt.com",
       "expected_root":INVENTORY["targets"]["hosting-2"]["wp_root"]}
class Result:
 returncode=0
 stdout='{"site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb"}\n'
 stderr=''
class Tests(unittest.TestCase):
 def read_file(self,path,maxbytes):
  if path==m.INVENTORY:return json.dumps(INVENTORY).encode()
  return b"root-only-reference"
 def test_pinned_read_only_site(self):
  with mock.patch.object(m,"root_file",side_effect=self.read_file),mock.patch.object(m.subprocess,"run",return_value=Result()) as run:
   response,runtime=m.remote_wordpress_read(SCOPE,"all-royal-egypt","staging",'echo wp_json_encode(["site_uuid"=>"x"]);')
  self.assertEqual(response["site_uuid"],"49c562d1-8f2f-456f-b454-26816c6ba4cb")
  self.assertEqual(runtime,"hosting-2-staging-external-wpcli")
  argv=run.call_args.args[0]
  self.assertEqual(argv[0],"/usr/bin/ssh")
  self.assertIn("StrictHostKeyChecking=yes",argv)
  self.assertIn("BatchMode=yes",argv)
  self.assertIn("--skip-themes",argv[-1])
  self.assertNotIn("sudo",argv[-1])
 def test_environment_production_refused_without_ssh(self):
  with mock.patch.object(m.subprocess,"run") as runner:
   with self.assertRaisesRegex(RuntimeError,"EXTERNAL_SCOPE_ENVIRONMENT_NOT_AUTHORIZED"):
    m.remote_wordpress_read(SCOPE,"all-royal-egypt","production","echo 1")
   runner.assert_not_called()
 def test_company_mismatch_refused_without_ssh(self):
  with mock.patch.object(m,"root_file",side_effect=self.read_file),mock.patch.object(m.subprocess,"run") as runner:
   with self.assertRaisesRegex(RuntimeError,"EXACT_REGISTRY_COMPANY_SITE_SCOPE_MISMATCH"):
    m.remote_wordpress_read(SCOPE,"wrong-company","staging","echo 1")
   runner.assert_not_called()
 def test_root_scope_mismatch_refused(self):
  scope={**SCOPE,"expected_root":"/home/other/public_html/staging"}
  with mock.patch.object(m,"root_file",side_effect=self.read_file):
   with self.assertRaisesRegex(RuntimeError,"EXACT_REGISTRY_COMPANY_SITE_SCOPE_MISMATCH"):
    m.remote_wordpress_read(scope,"all-royal-egypt","staging","echo 1")
 def test_original_denial_with_no_registry_binding(self):
  with self.assertRaisesRegex(RuntimeError,"EXTERNAL_REGISTERED_HOST_SCOPE_REQUIRED"):
   m.remote_wordpress_read({},"all-royal-egypt","staging","echo 1")
 def test_failed_ssh_not_claimed(self):
  class Bad(Result):
   returncode=255
  with mock.patch.object(m,"root_file",side_effect=self.read_file),mock.patch.object(m.subprocess,"run",return_value=Bad()):
   with self.assertRaisesRegex(RuntimeError,"EXACT_CROSS_SSH_WORDPRESS_READ_FAILED"):
    m.remote_wordpress_read(SCOPE,"all-royal-egypt","staging","echo 1")
if __name__=="__main__":unittest.main()
