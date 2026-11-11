"""Source-only confirmation that additive Portfolio modules are actually wired."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
MAIN=ROOT/'wp-content/plugins/mad4b-company-portfolio-manager/mad4b-company-portfolio-manager.php'
INFO=json.loads((ROOT/'docs/release-coverage/portfolio-bootstrap-reconcile-20261111.json').read_text())
MODULES=[
    'class-mad4b-company-native-scoped-write-handler.php',
    'class-mad4b-company-native-scoped-write-providers.php',
    'class-mad4b-company-native-mu-deploy-handler.php',
    'class-mad4b-company-native-mu-deploy-provider.php',
    'class-mad4b-company-mu-deploy-lifecycle.php',
    'class-mad4b-company-original-lifecycle-router.php',
    'class-mad4b-company-host-topology-registry.php',
    'class-mad4b-company-host-runner-enrollment.php',
    'class-mad4b-company-site-operations.php',
    'class-mad4b-company-distribution-provision.php',
]
class SourceBootstrapTests(unittest.TestCase):
    def test_pinned_main_preimage(self):
        p=subprocess.run(['git','-C',str(ROOT),'cat-file','blob',
                          'ba5484b1d5d1120b648f3079b4ac47b6e087e45b:wp-content/plugins/mad4b-company-portfolio-manager/mad4b-company-portfolio-manager.php'],
                         capture_output=True,timeout=15)
        self.assertEqual(p.returncode,0)
        self.assertEqual(hashlib.sha256(p.stdout).hexdigest(),INFO['prior_git_sha256'])
    def test_original_bootstrap_reconcile_sha_preserved_in_git_history(self):
        p=subprocess.run(['git','-C',str(ROOT),'cat-file','blob',
                          'b5484a26099414ed7dbc01cb5b4d98f753a75feb:wp-content/plugins/mad4b-company-portfolio-manager/mad4b-company-portfolio-manager.php'],
                         capture_output=True,timeout=15)
        self.assertEqual(p.returncode,0)
        self.assertEqual(hashlib.sha256(p.stdout).hexdigest(),
                         INFO['reconciled_git_sha256'])
    def test_new_requires_are_one_time_and_exist(self):
        source=MAIN.read_text()
        for file in MODULES:
            with self.subTest(file=file):
                self.assertEqual(source.count("'/includes/"+file+"'"),1)
                self.assertTrue((MAIN.parent/'includes'/file).is_file())
    def test_legacy_fixed_wayfa_launch_not_registered(self):
        source=MAIN.read_text()
        self.assertNotIn("'/includes/class-mad4b-company-mu-deploy-owner-launch.php'",source)
        self.assertNotIn("function has_production_delegation",source)
        self.assertNotIn("environments/production/delegation",source)
    def test_site_ops_callback_one_registration(self):
        source=MAIN.read_text()
        self.assertEqual(source.count("['MAD4B_Company_Site_Operations_V1','boot']"),1)
        self.assertIn("if (wp_get_environment_type() === 'staging')",source)
        self.assertIn("class-mad4b-company-distribution-provision.php",source)

if __name__=='__main__':unittest.main()
