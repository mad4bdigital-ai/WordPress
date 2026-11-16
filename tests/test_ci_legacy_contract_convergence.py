"""CI tests may not resurrect retired runtime/repository trust semantics."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
P = ROOT / 'wp-content/plugins/mad4b-site-control-plane'

class LegacyCITrustContracts(unittest.TestCase):
    def test_connection_checks_observational_source_not_repair_verdict(self):
        source = (P / 'tests/runtime-mcp-runtime-conflict-recovered.php').read_text()
        self.assertIn("'runtime_source_observed'", source)
        self.assertNotIn("'canonical_runtime'", source)
        for required in ('runtime_class_provenance_complete',
                         'runtime_class_provenance_ready',
                         'mu_bootstrap_critical_class_set_pinned',
                         'source_provenance_observational'):
            self.assertIn(required, source)

    def test_unverified_source_evidence_must_still_fail_closed(self):
        source = (P / 'tests/runtime-plugin-adapter-discovery-smoke.php').read_text()
        self.assertIn("build_source_commit_sha", source)
        self.assertIn("empty( $zero_touch['evaluation']['ready'] )", source)
        self.assertIn("empty( $zero_touch['evaluation']['promotion_authorized'] )", source)
        self.assertIn("Unbound repository evidence requires explicit integrity blockers.", source)
        self.assertNotIn("Present build-embedded repository evidence must validate", source)

if __name__ == '__main__':
    unittest.main()
