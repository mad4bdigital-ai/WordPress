"""Regression-proof 305 source guard, exercising only temporary files."""
from pathlib import Path
import hashlib
import importlib.util
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
GUARD=ROOT/"integrations/guard_original_305_no_user_release.py"
CANDIDATE=ROOT/"integrations/305-firstparty-native-no-wp-user.php"
HISTORIC=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/original-305-no-wp-user-20261108/305-mad4b-central-ssh-command-intake-with-sealed-argv.php.before-native-305")

def load():
    spec=importlib.util.spec_from_file_location("_mad4b_test_original305_guard",GUARD)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

class Native305GuardRecoveryTests(unittest.TestCase):
    def setUp(self):
        if not HISTORIC.is_file(): self.skipTest("No pinned prior live 305 baseline")
        self.temp=tempfile.TemporaryDirectory()
        self.base=Path(self.temp.name)
        self.module=load()
        self.guard=self.module.guard
        self.previous={n:getattr(self.guard,n) for n in
                       ("CORE","VETTED","REPORT","BACKUPS","LOCK")}
        self.guard.CORE=self.base/"original-305.php"
        self.guard.VETTED=self.base/"vetted-305.php"
        self.guard.REPORT=self.base/"report.json"
        self.guard.BACKUPS=self.base/"backups"
        self.guard.LOCK=self.base/"lock"
        self.guard.VETTED.write_bytes(CANDIDATE.read_bytes())
        self.guard.VETTED.chmod(0o600)
        self.guard.CORE.write_bytes(HISTORIC.read_bytes())
        self.guard.CORE.chmod(0o600)

    def tearDown(self):
        if hasattr(self,'previous'):
            for name,value in self.previous.items():setattr(self.guard,name,value)
        if hasattr(self,'temp'):self.temp.cleanup()

    def test_older_pinned_305_is_repaired_to_new_native_version(self):
        x=self.guard.run(False)
        self.assertEqual(x["action"],"KNOWN_REGRESSION_REPAIR_AVAILABLE")
        self.assertFalse(x["known_version_restored"])
        self.assertEqual(hashlib.sha256(self.guard.CORE.read_bytes()).hexdigest(),
                         "f9ad49d9aee65eeca37f7448e3f1913cb871a4025ca6ea82858881a047d5a302")
        repaired=self.guard.run(True)
        self.assertEqual(repaired["action"],"SIGNED_328_KNOWN_BASELINE_RECOVERED")
        self.assertTrue(repaired["known_version_restored"])
        self.assertEqual(self.guard.CORE.read_bytes(),CANDIDATE.read_bytes())
        self.assertFalse(repaired["original_305_grant_mutated"])
        self.assertFalse(repaired["wordpress_site_content_mutated"])
        self.assertEqual(self.guard.run(True)["action"],"PASS_ALREADY_CURRENT")

    def test_unknown_newer_source_is_never_replaced(self):
        original=self.guard.CORE.read_bytes()
        # A distinct realistic same-length source is not a known old baseline.
        mutated=original.replace(b'Original',b'OriginaL',1)
        self.assertNotEqual(mutated,original)
        self.guard.CORE.write_bytes(mutated)
        x=self.guard.run(True)
        self.assertEqual(x["action"],"DEFER")
        self.assertEqual(x["reason"],"UNKNOWN_RELEASE_SOURCE_DO_NOT_OVERWRITE")
        self.assertEqual(self.guard.CORE.read_bytes(),mutated)

    def test_guard_pin_matches_current_source_snapshot(self):
        self.assertEqual(self.guard.EXPECTED_NEW,
            hashlib.sha256(CANDIDATE.read_bytes()).hexdigest())
        self.assertIn(self.guard.EXPECTED_OLD,self.guard.KNOWN_OLD)
        self.assertNotIn(self.guard.EXPECTED_NEW,self.guard.KNOWN_OLD)

if __name__=="__main__":
    unittest.main()
