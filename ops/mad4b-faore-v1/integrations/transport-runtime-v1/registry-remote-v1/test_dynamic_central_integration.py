"""Regression tests for the canonical enrolled Central WP-CLI path."""
import importlib.util
import sys
import unittest
from pathlib import Path

ROOT=Path('/opt/mad4b-company-transport')
sys.path.insert(0,str(ROOT))
from central_wpcli_runtime import central_wp

FILES={
 'grant':ROOT/'unified-execution-router-v1/central_grant.py',
 'rotation':ROOT/'broker-v1/native-vps-policy-rotate.py',
 'late_receipt':ROOT/'broker-v1/vps-late-native-reconcile.py',
 'late_sweep':ROOT/'broker-v1/vps-late-native-sweeper.py'
}

def module(name):
    spec=importlib.util.spec_from_file_location('integration_'+name,FILES[name])
    item=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(item)
    return item

class CanonicalCentralRuntimeTests(unittest.TestCase):
    def test_signed_grant_verifier_uses_original_runtime(self):
        self.assertEqual(module('grant').WP,central_wp()+['--user=1','mad4b','wpcli-grant'])
    def test_signed_policy_rotation_uses_original_runtime(self):
        m=module('rotation')
        self.assertEqual(m.WP,central_wp())
        self.assertEqual(m.BASE,[])
    def test_late_reconciler_uses_original_runtime(self):
        self.assertEqual(module('late_receipt').WP,central_wp())
    def test_late_sweeper_uses_original_runtime(self):
        self.assertEqual(module('late_sweep').WP,central_wp())
    def test_previous_compose_container_is_not_in_live_adapters(self):
        for path in FILES.values():
            with self.subTest(file=path.name):
                self.assertNotIn('etg-commerce-staging-php',path.read_text())

if __name__=='__main__':
    unittest.main()
