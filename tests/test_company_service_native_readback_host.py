"""Offline host-side identity/digest fail-closed tests; no Docker or WP writes."""
import importlib.util
import json
import pathlib
import tempfile
import time
import unittest
from unittest import mock

ROOT=pathlib.Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/company_service_native_wpcli_readback_v1.py'
spec=importlib.util.spec_from_file_location('native_wpcli_host',SCRIPT)
host=importlib.util.module_from_spec(spec)
spec.loader.exec_module(host)

CONFIG={
 'contract':host.CONTRACT,'company':'wayfatours',
 'site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
 'origin':'https://wayfatours.com',
 'central_origin':'https://tours.dev.mad4b.com',
 'center_container':'php-wkgtkzta35wkkgouwt6ruljc',
 'tenant_container':'php-guxhp9y6vgh7oouuw8plxrlr'
}

class NativeHostTests(unittest.TestCase):
 def test_exact_scope_succeeds(self):
  self.assertEqual(host.validate_config(dict(CONFIG)),CONFIG)
 def test_extra_company_blocked(self):
  for field,value in [('company','all-royal-egypt'),('origin','https://other.test'),
                      ('site_uuid','invalid-uuid'),
                      ('center_container','php-invalid'),('central_origin','https://bad.test')]:
   row=dict(CONFIG);row[field]=value
   with self.subTest(field=field),self.assertRaises(host.Refused):
    host.validate_config(row)
 def test_config_extra_field_rejected(self):
  row=dict(CONFIG);row['password']='no'
  with self.assertRaises(host.Refused):host.validate_config(row)
 def test_registered_binding_fails_closed(self):
  row={'eligible':True,'center_origin':CONFIG['central_origin'],
       'central_environment':'staging','company':'wayfatours',
       'site_uuid':CONFIG['site_uuid'],'registered_uuid':CONFIG['site_uuid'],
       'registered_origin':CONFIG['origin'],'registered_environment':'production',
       'company_id':'wayfatours','binding_sha256':'a'*64,
       'source_schema_fields':31,'source_readback_module_sha256':'b'*64}
  with mock.patch.object(host,'limited_eval',return_value=json.dumps(row)):
   self.assertEqual(host.central_preflight(CONFIG)['company'],'wayfatours')
  for key,value in [('registered_uuid','0'*36),('registered_origin','https://different.test'),
                    ('binding_sha256','0'),('central_environment','production')]:
   altered=dict(row);altered[key]=value
   with mock.patch.object(host,'limited_eval',return_value=json.dumps(altered)):
    with self.subTest(key=key),self.assertRaises(host.Refused):
     host.central_preflight(CONFIG)
 def test_tenant_wrong_id_blocked(self):
  snap={'contract':host.SITE_CONTRACT,'site_host':'wayfatours.com',
        'families':{x:{'effective_mode':'tour_only'} for x in ('tours','properties','transportations','visas')},
        'schema':{'declared_count':31,'runtime_schema_gaps':[]},
        'public_guard_loaded':True,'unified_fulfillment_loaded':True,
        'stripe_enabled':False,'early_payment_benefits_enabled':False,
        'observed_utc':'2026-11-11T17:00:00+00:00'}
  row={'challenge':'f'*32,'site':CONFIG['origin'],'site_uuid':CONFIG['site_uuid'],
        'site_environment':'production','readback_module_sha256':'b'*64,'snapshot':snap}
  with mock.patch.object(host,'limited_eval',return_value=json.dumps(row)):
   with self.assertRaises(host.Refused):host.tenant_read(CONFIG,'f'*32)
  snap['observed_utc']='2026-11-11T17:00:00+00:00'
  with mock.patch.object(host,'limited_eval',return_value=json.dumps(row)),mock.patch.object(host.time,'time',return_value=1794416400):
   raw,value=host.tenant_read(CONFIG,'f'*32)
   self.assertEqual(value['site_uuid'],CONFIG['site_uuid'])
   altered=dict(row);altered['site_uuid']='9f441634-701a-420f-a81a-efab76ee1dbd'
   with mock.patch.object(host,'limited_eval',return_value=json.dumps(altered)),self.assertRaises(host.Refused):
    host.tenant_read(CONFIG,'f'*32)
 def test_canonical_record_not_signed(self):
  row=host.bounded_host_record(CONFIG,{'binding_sha256':'a'*64},'{"challenge":"x"}',500)
  self.assertEqual(row['expires_epoch'],860)
  self.assertFalse(row['issuer_signed'])
  self.assertEqual(row['transport'],'same_host_scoped_wpcli')
  self.assertEqual(len(row['snapshot_sha256']),64)

if __name__=='__main__':unittest.main()
