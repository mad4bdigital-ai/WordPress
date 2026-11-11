#!/usr/bin/env python3
"""Real ephemeral PHP Ed25519 runtime test for source candidate v82.
Uses in-memory PHP WP stubs only; never boots WP or writes a real option.
"""
import base64
import pathlib
import subprocess
import shutil
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
SOURCE=ROOT/"deploy/host-authority/v82/318-mad4b-native-host-exact-grant.php"
FIXTURE=ROOT/"tests/original318_signed_anchor_runtime_v78.php"
EXTRA='''
$revoked=$anchor;
$revoked['state']='REVOKED_INDEPENDENT_HOST_AUTHORITY';
$revoked['revoked']=true;
$revoked['revision']=2;
unset($revoked['signature']);
$revoked=signed_anchor($revoked,$private,$c);
$GLOBALS['test_options'][$anchorKey]=$revoked;
$r=$c::independent_revoked_anchor_observation_v2('example-tour-company','production');
assert(($r['signed_revocation_record_observed']??false)===true);
assert(($r['anchor_revision']??null)===2);
assert(($r['anchor_nonce']??null)===$revoked['nonce']);
assert(($r['write_authorized']??true)===false);
assert(($r['independent_highwater_verified']??true)===false);
assert(($r['original313_certified']??true)===false);
$bad=$revoked;
$bad['signature']=base64_encode(str_repeat('X',64));
$GLOBALS['test_options'][$anchorKey]=$bad;
assert(($c::independent_revoked_anchor_observation_v2('example-tour-company','production')['signed_revocation_record_observed']??true)===false);
$bad=$revoked;
$bad['company']='wrong-company';
$GLOBALS['test_options'][$anchorKey]=$bad;
assert(($c::independent_revoked_anchor_observation_v2('example-tour-company','production')['signed_revocation_record_observed']??true)===false);
$bad=$revoked;
$bad['revision']=1;
$GLOBALS['test_options'][$anchorKey]=$bad;
assert(($c::independent_revoked_anchor_observation_v2('example-tour-company','production')['signed_revocation_record_observed']??true)===false);
$GLOBALS['test_options'][$anchorKey]=$anchor;
assert(($c::independent_revoked_anchor_observation_v2('example-tour-company','production')['signed_revocation_record_observed']??true)===false);
echo json_encode(['contract'=>'v82.revoked-observation.ephemeral',
 'cases'=>10,'signed_revoke_readback_shape_pass'=>true,'real_wordpress_mutated'=>false])."\n";
'''

class Revocation(unittest.TestCase):
    def test_signed_revocation_readback_in_php(self):
        if shutil.which("docker") is None:
            self.skipTest("requires VPS Docker PHP runtime; never a source-only PASS")
        health=subprocess.run(["docker","inspect","php-wkgtkzta35wkkgouwt6ruljc"],
                              capture_output=True,timeout=10,check=False)
        if health.returncode:
            self.skipTest("dedicated PHP fixture runtime unavailable")
        template=FIXTURE.read_text()
        needle="$source=file_get_contents(__DIR__.'/../deploy/host-authority/v68/318-mad4b-native-host-exact-grant.php');"
        self.assertEqual(template.count(needle),1)
        injected="$source=base64_decode('"+base64.b64encode(SOURCE.read_bytes()).decode()+"',true);"
        php=template.replace(needle,injected)+"\n"+EXTRA
        result=subprocess.run(
          ['docker','exec','-i','php-wkgtkzta35wkkgouwt6ruljc','php',
           '-d','zend.assertions=1','-d','assert.exception=1','/dev/stdin'],
          input=php.encode(),capture_output=True,timeout=60,check=False)
        self.assertEqual(result.returncode,0,result.stderr.decode(errors='replace'))
        self.assertIn(b'"signed_revoke_readback_shape_pass":true',result.stdout)
        self.assertIn(b'"real_wordpress_mutated":false',result.stdout)
if __name__=='__main__':unittest.main(verbosity=2)
