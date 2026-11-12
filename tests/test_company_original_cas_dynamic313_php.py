"""Isolated PHP 313 dynamic-routing tests. No real signer, DB or tenant writes."""
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'wp-content/plugins/mad4b-platform/modules'
DYNAMIC=ROOT/'ops/dynamic-operation-registry-v1'
PUB=base64.b64encode(bytes(range(32))).decode()
SYNTHETIC_LEGACY={
  'registry_contract':'mad4b.dynamic-operation-index.v1',
  'first_party_enrolled':True,
  'independent_verifier_public_key_b64':PUB,
  'provider':{
    'operation_id':'company.code.release','version':3,
    'evidence_schema':'code_release.v2',
    'command':{
      'bound_operation_id':'company.code.release',
      'format':'wpcli.argv.json.v1',
      'mode':'plan_sha256',
      'prefix':['mad4b','company-code-release','apply'],
      'flag':'--plan_sha256='
    }
  },
  'evidence_schema':{
    'contract':'mad4b.dynamic-evidence-schema.v1',
    'schema_id':'code_release.v2',
    'success_state':'HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313',
    'fields':{
      'grant_id':'string','transport_id':'string',
      'result_status':'string',
      'output_sha256':'sha256','expected_readback_sha256':'sha256',
      'readback_sha256':'sha256','production_mutation':'boolean'
    }
  }
}


class Dynamic313SourcePHP(unittest.TestCase):
 def check(self,mode):
  with tempfile.TemporaryDirectory(prefix='mad4b-313-routing-') as root:
   path=Path(root)
   auth=path/'modules/authority/dynamic313'
   service=path/'modules/services'
   auth.mkdir(parents=True)
   service.mkdir(parents=True)
   for name in (
     'class-mad4b-dynamic-313-wordpress.php',
     'class-mad4b-dynamic-313-verifier.php'):
    (auth/name).write_bytes((PHP/'authority/dynamic313'/name).read_bytes())
   (service/'313b-mad4b-native-independent-verifier.php').write_bytes(
     (PHP/'services/313b-mad4b-native-independent-verifier.php').read_bytes())
   manifest=json.loads((DYNAMIC/
     'providers/company.resource.cas.apply/manifest.json').read_text())
   schema=json.loads((DYNAMIC/
     'providers/company.resource.cas.apply/evidence-schema.json').read_text())
   cas={
     'registry_contract':'mad4b.dynamic-operation-index.v1',
     'first_party_enrolled':True,
     'independent_verifier_public_key_b64':PUB,
     'provider':manifest,
     'evidence_schema':schema,
   }
   config={
     'contract':'mad4b.dynamic-313-first-party-config.v1',
     'original_313_finalizer_connected':True,
     'trusted':SYNTHETIC_LEGACY,
   }
   if mode!='no_provider':
    config['providers']=[cas]
   if mode=='finalizer_enrolled':
    config['finalizer_operations']=[
      'company.code.release','company.resource.cas.apply']
   serialized=json.dumps(config,separators=(',',':'))
   encoded=base64.b64encode(serialized.encode()).decode()
   (auth/'config.php').write_text(
      "<?php return json_decode(base64_decode('"+encoded+"'),true);\n")
   (auth/'config.php').chmod(0o644)
   (path/'verify.php').write_text(r'''<?php
define('ABSPATH','/');
define('WP_CLI',false);
class MAD4B_Central_VPS_Broker_V1 {
 const PUBKEY='test_broker';
 const JOB='mad4b-broker-job-';
}
class MAD4B_Broker_Exact_SSH_Authority_V1 {}
class MAD4B_Broker_Remote_SSH_Receipt_V1 {
 const WPCLI_PREFIX='mad4b-pending-';
}
function get_option($key,$fallback=false) {
 return $key==='test_broker'?base64_encode(implode('',array_map('chr',range(0,31)))):$fallback;
}
require __DIR__.'/modules/services/313b-mad4b-native-independent-verifier.php';
$d='MAD4B_Dynamic_WP_313_Readback_V1';
$c='MAD4B_Native_WPCLI_Receipt_Verifier_V1';
$status=$d::status();
$fields=[
 'contract'=>'mad4b.native-313-independent-host-proof.v4',
 'job_id'=>str_repeat('a',32),
 'company'=>'wayfatours','environment'=>'production',
 'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
 'origin'=>'https://wayfatours.com','target_id'=>'srv2030908',
 'grant_id'=>str_repeat('1',32),'transport_id'=>str_repeat('2',32),
 'command_sha256'=>str_repeat('3',64),
 'native_receipt_sha256'=>str_repeat('4',64),
 'output_sha256'=>str_repeat('5',64),
 'expected_readback_sha256'=>str_repeat('5',64),
 'readback_sha256'=>str_repeat('5',64),
 'issued'=>time(),'expires'=>time()+50,
 'nonce'=>str_repeat('6',32),
 'observed_operation'=>'company.resource.cas.apply',
 'production_mutation'=>'true',
 'postcondition_type'=>'enrolled_dynamic_evidence',
 'postcondition_key'=>'resource_cas.v1',
 'sealed_argv_sha256'=>str_repeat('3',64),
 'dynamic_plan_sha256'=>str_repeat('7',64),
 'dynamic_evidence_sha256'=>str_repeat('8',64),
 'signature'=>base64_encode(str_repeat('x',64)),
];
$method=new ReflectionMethod($c,'check');
$result=$method->invoke(null,$fields,false);
echo json_encode([
 'legacy_operational'=>$status['operational_ready'],
 'legacy_operation_ready'=>$d::operation_operational_ready('company.code.release'),
 'legacy_schema'=>$d::enrolled_schema_id('company.code.release'),
 'cas_enrolled'=>$d::operation_enrolled('company.resource.cas.apply'),
 'cas_finalizer_ready'=>$d::operation_operational_ready('company.resource.cas.apply'),
 'cas_schema'=>$d::enrolled_schema_id('company.resource.cas.apply'),
 'unknown_operation_denied'=>!$d::operation_enrolled('custom.write.any'),
 'unsigned_cas_v4_reason'=>$result['reason']??null,
 'fake_receipt_certified'=>$result['verified']??false,
 'host_mutation_executed'=>false
 ],JSON_UNESCAPED_SLASHES);
''')
   p=subprocess.run([
      '/usr/bin/docker','run','--rm','--network','none','--read-only',
      '-v',str(path)+':/fixture:ro','--entrypoint','php',
      'mad4b-wordpress-php:8.5-redis6.3-source','/fixture/verify.php'],
      capture_output=True,text=True,timeout=25,check=False)
   self.assertEqual(p.returncode,0,msg=p.stderr[-700:])
   return json.loads(p.stdout.strip().splitlines()[-1])

 def test_legacy_provider_preserved_without_cas(self):
  data=self.check('no_provider')
  self.assertTrue(data['legacy_operational'])
  self.assertTrue(data['legacy_operation_ready'])
  self.assertEqual(data['legacy_schema'],'code_release.v2')
  self.assertFalse(data['cas_enrolled'])
  self.assertFalse(data['cas_finalizer_ready'])
  self.assertEqual(data['unsigned_cas_v4_reason'],
                   'DYNAMIC_SIGNED_POSTCONDITION_SCOPE_INVALID')

 def test_cas_registry_only_is_not_original313_admission(self):
  data=self.check('provider_only')
  self.assertTrue(data['legacy_operational'])
  self.assertTrue(data['cas_enrolled'])
  self.assertFalse(data['cas_finalizer_ready'])
  self.assertEqual(data['cas_schema'],'resource_cas.v1')
  self.assertEqual(data['unsigned_cas_v4_reason'],
                   'DYNAMIC_SIGNED_POSTCONDITION_SCOPE_INVALID')

 def test_first_party_finalizer_enrollment_still_requires_real_signature(self):
  data=self.check('finalizer_enrolled')
  self.assertTrue(data['legacy_operational'])
  self.assertTrue(data['cas_finalizer_ready'])
  self.assertEqual(data['unsigned_cas_v4_reason'],
                   'INDEPENDENT_VERIFIER_SIGNATURE_INVALID')
  self.assertFalse(data['fake_receipt_certified'])
  self.assertTrue(data['unknown_operation_denied'])
  self.assertFalse(data['host_mutation_executed'])

if __name__=='__main__':
 unittest.main()
