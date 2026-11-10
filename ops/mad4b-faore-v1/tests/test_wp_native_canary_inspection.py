"""Static guards for the read-only WordPress Canary inspection module."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
PLUGIN = ROOT / 'wp-content/plugins/mad4b-site-control-plane'
SOURCE = PLUGIN / 'includes/class-mad4b-scp-faore-staging-canary-inspection.php'


class NativeCanarySourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.code = SOURCE.read_text()
        cls.loader = (PLUGIN / 'mad4b-site-control-plane.php').read_text()

    def test_no_writer_registered_or_called(self):
        self.assertIn('mad4b/faore-staging-canary-plan', self.code)
        self.assertIn('mad4b/faore-staging-canary-verify', self.code)
        for call in ('add_option(', 'update_option(', 'delete_option(',
                     'wp_set_current_user(', 'canary-apply'):
            self.assertNotIn(call, self.code)

    def test_exact_original_canary_key_shape(self):
        self.assertIn("mad4b_faore_write_canary_", self.code)
        self.assertIn("^[a-f0-9]{16}$", self.code)
        self.assertIn("'required' => array( 'correlation_id', 'option_suffix' )", self.code)

    def test_loads_from_original_control_plane(self):
        self.assertEqual(self.loader.count(
            "includes/class-mad4b-scp-faore-staging-canary-inspection.php"), 1)
        self.assertEqual(self.loader.count(
            'MAD4B_SCP_FAORE_Staging_Canary_Inspection::boot();'), 1)

    def test_staging_and_read_only_metadata(self):
        for item in ('deployment_binding_match', 'origin_match', 'environment_match',
                     "wp_get_environment_type()", "'readonly' => true",
                     "'surface' => 'read'", "'mutation_performed' => false",
                     "'rollback_verified' => false"):
            self.assertIn(item, self.code)


if __name__ == '__main__':
    unittest.main()
