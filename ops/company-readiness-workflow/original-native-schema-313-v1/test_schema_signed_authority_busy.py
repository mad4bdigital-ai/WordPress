#!/usr/bin/env python3
"""Read-only regressions: busy WP CLI must never become signed authority."""
import importlib.util,base64,json,tempfile,types,unittest
from pathlib import Path
from unittest.mock import patch
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
ROOT=Path('/opt/mad4b-company-transport/broker-v1')
def load(name,file):
 spec=importlib.util.spec_from_file_location(name,ROOT/file)
 result=importlib.util.module_from_spec(spec);spec.loader.exec_module(result);return result
class SignedBrokerBusyTests(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.signer=load('test_original_signer_live_scope','native-vps-client-principal.py')
  cls.authority=load('test_original_authority_live_scope','vps-broker-exact-authority.py')
 @staticmethod
 def row(rc,stdout='',stderr=''):
  return types.SimpleNamespace(returncode=rc,stdout=stdout,stderr=stderr)
 def test_signer_busy_then_exact_pinned_key_success(self):
  key=Ed25519PrivateKey.generate()
  b=key.public_key().public_bytes_raw()
  cases=[self.row(1,stderr='STAGING_WPCLI_BUSY'),self.row(75,stderr='STAGING_WPCLI_BUSY'),self.row(0,stdout=base64.b64encode(b).decode())]
  with patch.object(self.signer,'original_central_wp',return_value=['wp']),patch.object(self.signer.subprocess,'run',side_effect=cases) as run,patch.object(self.signer.time,'sleep',return_value=None):
   self.signer.check_original_wordpress_signer(key)
   self.assertEqual(run.call_count,3)
 def test_signer_busy_exhaustion_does_not_authorize(self):
  key=Ed25519PrivateKey.generate()
  with patch.object(self.signer,'original_central_wp',return_value=['wp']),patch.object(self.signer.subprocess,'run',side_effect=[self.row(1,stderr='STAGING_WPCLI_BUSY')]*3),patch.object(self.signer.time,'sleep',return_value=None):
   with self.assertRaisesRegex(RuntimeError,'CENTRAL_WPCLI_SLOT_BUSY_DEFERRED'):
    self.signer.check_original_wordpress_signer(key)
 def test_signer_real_denial_not_classified_busy(self):
  key=Ed25519PrivateKey.generate()
  with patch.object(self.signer,'original_central_wp',return_value=['wp']),patch.object(self.signer.subprocess,'run',return_value=self.row(1,stderr='OWNER_PERMISSION_DENIED')):
   with self.assertRaisesRegex(RuntimeError,'ORIGINAL_SIGNER_WORDPRESS_READ_REJECTED'):
    self.signer.check_original_wordpress_signer(key)
 def authority_case(self,rows):
  key=Ed25519PrivateKey.generate()
  with tempfile.TemporaryDirectory() as d:
   k=Path(d)/'test-key.pem';k.write_bytes(b'not-a-production-key')
   with patch.object(self.authority,'KEY',k),patch.object(self.authority.serialization,'load_pem_private_key',return_value=key),patch.object(self.authority,'original_wp',return_value=['wp']),patch.object(self.authority.subprocess,'run',side_effect=rows),patch.object(self.authority.time,'sleep',return_value=None):
    return self.authority.verify('a'*32,'srv2030908','b'*64)
 def test_exact_312_busy_then_read_only_verified(self):
  valid={'contract':self.authority.CONTRACT,'eligible':False,'verified':False,'shell_executed':False}
  r=self.authority_case([self.row(1,stderr='STAGING_WPCLI_BUSY'),self.row(0,stdout=json.dumps(valid))])
  self.assertIs(r['eligible'],False)
 def test_exact_312_busy_exhaustion_denied(self):
  with self.assertRaisesRegex(RuntimeError,'CENTRAL_WPCLI_SLOT_BUSY_DEFERRED'):
   self.authority_case([self.row(1,stderr='STAGING_WPCLI_BUSY')]*3)
 def test_exact_312_true_denial_is_not_busy(self):
  with self.assertRaisesRegex(RuntimeError,'CENTRAL_SIGNED_AUTHORITY_PREFLIGHT_REJECTED'):
   self.authority_case([self.row(1,stderr='OWNER_PERMISSION_DENIED')])
if __name__=='__main__':unittest.main()
