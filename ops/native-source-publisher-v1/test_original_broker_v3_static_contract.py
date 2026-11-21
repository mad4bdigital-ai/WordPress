"""Static-first safety tests; do not invoke issuer, signer or WordPress."""
from __future__ import annotations
import ast
import json
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parent
ISSUER=ROOT/'original_broker_host_readiness_refresh_v3.py'
OPS=ROOT.parent

class OriginalBrokerV3StaticContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root=ast.parse(ISSUER.read_text())
        cls.function=next(n for n in cls.root.body
                          if isinstance(n,ast.FunctionDef)
                          and n.name=='sign_ready_payload')
        cls.source=ISSUER.read_text()

    def test_root_policy_and_public_ledger_precede_private_issuer_api(self):
        actions=[]
        for node in ast.walk(self.function):
            if not isinstance(node,ast.Call):continue
            method=node.func
            if (isinstance(method,ast.Attribute)
                and isinstance(method.value,ast.Name)
                and method.value.id=='broker'):
                actions.append((method.attr,node.lineno))
        by_name=dict(actions)
        for required in ('authority','host_fingerprint',
                         'verify_original_policy_readback','original_signer',
                         'check_original_wordpress_signer'):
            self.assertIn(required,by_name)
        self.assertLess(by_name['authority'],by_name['original_signer'])
        self.assertLess(by_name['host_fingerprint'],by_name['original_signer'])
        self.assertLess(by_name['verify_original_policy_readback'],
                        by_name['original_signer'])
        self.assertLess(by_name['original_signer'],
                        by_name['check_original_wordpress_signer'])
        self.assertIn("'HOST_READINESS_ORIGINAL_SOURCE_CHANGED_DURING_SIGNING'",
                      self.source)

    def test_source_only_v3_adds_one_pinned_dependency_without_mutating_v2(self):
        path=OPS/'dynamic-operation-registry-v1/providers/host.source.release'
        old=json.loads((path/'manifest.v2.CANDIDATE.json').read_bytes())
        new=json.loads((path/'manifest.v3.CANDIDATE.json').read_bytes())
        self.assertEqual(old['version'],2)
        self.assertEqual(new['version'],3)
        self.assertEqual(old['dependencies'],new['dependencies'][:-1])
        extra=new['dependencies'][-1]
        self.assertEqual(extra['path'],
            'native-source-publisher-v1/original_broker_host_readiness_refresh_v3.py')
        import hashlib
        self.assertEqual(extra['sha256'],
            hashlib.sha256(ISSUER.read_bytes()).hexdigest())
        self.assertEqual({k:v for k,v in old.items() if k!='version'},
            {k:v for k,v in new.items()
             if k not in ('version','dependencies')} | {'dependencies':old['dependencies']})

    def test_pending_fixture_resolves_exact_adjacent_v3_module(self):
        # Prevent the future authorized test from accidentally loading an
        # unpinned issuer after moving the fixture to tests_pending/.
        pending=ROOT/'tests_pending/verify_original_broker_v3_admission_order.py'
        self.assertTrue(pending.is_file())
        self.assertEqual(pending.resolve().parents[1]/ISSUER.name,ISSUER)
        tree=ast.parse(pending.read_text())
        text=pending.read_text()
        self.assertIn('Path(__file__).resolve().parents[1]',text)
        self.assertIn("SOURCE/'original_broker_host_readiness_refresh_v3.py'",text)
        self.assertNotIn("Path(__file__).with_name('original_broker_host_readiness_refresh_v3.py')",text)

    def test_original_firstparty_provider_and_timer_are_governed(self):
        import hashlib,subprocess
        enrolled=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
        raw=(enrolled/'index.json').read_bytes();index=json.loads(raw)
        rows=[r for r in index['providers'] if 'host.source.release' in r['manifest']]
        self.assertEqual(len(rows),1)
        record=rows[0];actual=(enrolled/record['manifest']).read_bytes()
        self.assertEqual(hashlib.sha256(actual).hexdigest(),record['sha256'])
        enrolled_version=json.loads(actual)['version']
        expected={
          '53ecf520548c3c7d48ba22c576e5adbced9927b434e790aaa7d32c5cd399d6f6':2,
          '6ada6de8622a49b5eb60d571d7edd8f69a2b8ed21c259ec0334c5bb02f1e5ed6':6,
          'f37b01faf36381a4e01b1220c006545e65cd7cebda85038b63c757bc9811dc08':7,
          'b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12':8,
        }
        self.assertIn(hashlib.sha256(raw).hexdigest(),expected)
        self.assertEqual(enrolled_version,expected[hashlib.sha256(raw).hexdigest()])
        self.assertTrue((enrolled/'dynamic-operation-registry-v1/providers/host.source.release/manifest.v2.json').is_file())
        timer=Path('/etc/systemd/system/mad4b-original-broker-host-readiness.timer')
        if enrolled_version==2:
            self.assertFalse(timer.exists())
        else:
            self.assertTrue(timer.is_file())
            status=subprocess.run(['systemctl','is-enabled','mad4b-original-broker-host-readiness.timer'],
                                  capture_output=True,text=True,timeout=12)
            # Future First-party activation is valid after reconciliation;
            # prohibit an active timer while a signed-write HOLD remains.
            self.assertIn(status.stdout.strip(),('disabled','enabled'))
            if status.stdout.strip()=='enabled':
                hold=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1/original305-readiness-ambiguous-write-HOLD.json')
                self.assertFalse(hold.exists())

if __name__=='__main__':unittest.main()
