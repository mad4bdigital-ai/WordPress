"""Read-only source invariants for versioned dynamic Host readiness V4."""
from __future__ import annotations
import ast
import hashlib
import json
from pathlib import Path
import unittest

BASE = Path(__file__).resolve().parent
OPS = BASE.parent
MANIFESTS = OPS/'dynamic-operation-registry-v1/providers/host.source.release'

class HostV4StaticContract(unittest.TestCase):
    def test_v4_is_additive_to_v3_without_replacing_prior_pins(self):
        before = json.loads((MANIFESTS/'manifest.v3.CANDIDATE.json').read_text())
        after = json.loads((MANIFESTS/'manifest.v4.CANDIDATE.json').read_text())
        self.assertEqual((before['version'],after['version']),(3,4))
        self.assertEqual(after['dependencies'][:len(before['dependencies'])], before['dependencies'])
        self.assertEqual(len(after['dependencies']),len(before['dependencies'])+3)
        self.assertEqual({k:v for k,v in after.items() if k not in ('version','dependencies')},
                         {k:v for k,v in before.items() if k not in ('version','dependencies')})
        for dep in after['dependencies'][-3:]:
            self.assertEqual(hashlib.sha256((OPS/dep['path']).read_bytes()).hexdigest(),dep['sha256'])

    def test_doctor_uses_existing_dynamic_runtime_not_a_fixed_container(self):
        src=(BASE/'host_source_activation_doctor_v3.py').read_text()
        ast.parse(src)
        self.assertIn('from central_wpcli_runtime import central_wp',src)
        self.assertIn("cmd=central_wp()+['eval'",src)
        self.assertNotIn('php-wkgtkzta35wkkgouwt6ruljc',src)
        self.assertIn('hmac.compare_digest(',src)
        self.assertIn("rows[0].get('eligible') is not True",src)

    def test_v4_source_chain_uses_dynamic_doctor_without_changing_v3(self):
        handoff=(BASE/'original305_host_unsigned_readiness_request_v4.py').read_text()
        issuer=(BASE/'original_broker_host_readiness_refresh_v4.py').read_text()
        ast.parse(handoff);ast.parse(issuer)
        self.assertIn('import host_source_activation_doctor_v3 as doctor',handoff)
        self.assertIn('import original305_host_unsigned_readiness_request_v4 as handoff',issuer)
        self.assertIn('broker.verify_original_policy_readback(',issuer)
        self.assertLess(issuer.index('broker.verify_original_policy_readback('),issuer.index('key=broker.original_signer()'))
        self.assertEqual((BASE/'original_broker_host_readiness_refresh_v3.py').is_file(),True)

    def test_live_provider_is_firstparty_pinned_and_timer_is_hold_aware(self):
        import subprocess
        root=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
        blob=(root/'index.json').read_bytes()
        index=json.loads(blob)
        rows=[x for x in index['providers'] if x['manifest'].split('/')[-2]=='host.source.release']
        self.assertEqual(len(rows),1)
        pinned=rows[0]
        actual=(root/pinned['manifest']).read_bytes()
        self.assertEqual(hashlib.sha256(actual).hexdigest(),pinned['sha256'])
        version=json.loads(actual)['version']
        # A first-party V6 enrollment superseded the V2 staging baseline.
        # This is an exact installed index fingerprint, never a generic
        # 'version >= 2' exception that could admit unknown releases.
        fingerprints={
          '53ecf520548c3c7d48ba22c576e5adbced9927b434e790aaa7d32c5cd399d6f6':2,
          '6ada6de8622a49b5eb60d571d7edd8f69a2b8ed21c259ec0334c5bb02f1e5ed6':6,
          'f37b01faf36381a4e01b1220c006545e65cd7cebda85038b63c757bc9811dc08':7,
          'b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12':8,
        }
        self.assertIn(hashlib.sha256(blob).hexdigest(),fingerprints)
        self.assertEqual(version,fingerprints[hashlib.sha256(blob).hexdigest()])
        timer='/etc/systemd/system/mad4b-original-broker-host-readiness.timer'
        if version==2:
            self.assertFalse(Path(timer).exists())
        else:
            if version==6:
                receipt=Path('/opt/mad4b-company-provisioning/reports/host-original-provider-v6-firstparty-20261116/FINAL-ORIGINAL-HOST-V6-ROOT-READBACK.json')
            elif version==7:
                receipt=Path('/opt/mad4b-company-provisioning/reports/host-original-provider-v7-firstparty-retry2-20261116/FINAL-ORIGINAL-HOST-V7-ROOT-READBACK.json')
            else:
                receipt=Path('/opt/mad4b-company-provisioning/reports/host-original-provider-v8-firstparty-20261116/FINAL-ORIGINAL-HOST-V8-ROOT-READBACK.json')
            self.assertEqual(json.loads(receipt.read_text())['state'],
                             f'ORIGINAL_HOST_V{version}_ADDITIVE_PROVIDER_ENROLLED')
            self.assertTrue(Path(timer).is_file())
            status=subprocess.run(['systemctl','is-enabled','mad4b-original-broker-host-readiness.timer'],
                                  capture_output=True,text=True,timeout=12)
            # Future First-party activation is valid after reconciliation;
            # prohibit an active timer while a signed-write HOLD remains.
            self.assertIn(status.stdout.strip(),('disabled','enabled'))
            if status.stdout.strip()=='enabled':
                hold=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1/original305-readiness-ambiguous-write-HOLD.json')
                self.assertFalse(hold.exists())

if __name__=='__main__':unittest.main()
