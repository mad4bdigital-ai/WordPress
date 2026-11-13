"""Visibility source release must stay a disabled, exact Tours.dev proposal.

This is a source-only scope test; no keys, Jobs, Grants or live policies.
"""
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "ops" / "native-source-publisher-v1" / "catalog"
REQUIRED = {
    "wp-content/mu-plugins/mad4b-home-reviews-native-visibility.php",
    "wp-content/mu-plugins/mad4b-need-help-native-visibility.php",
    "wp-content/mu-plugins/mad4b-single-reviews-native-visibility.php",
}


class NativeVisibilitySourceScope(unittest.TestCase):
    def test_exact_scope_is_disabled_and_has_no_signing_authority(self):
        policy = json.loads((CATALOG / "policy-template.DISABLED.json").read_text())
        proposal = json.loads((CATALOG / "publisher-enrollment-proposal.json").read_text())
        self.assertFalse(policy["enabled"])
        self.assertEqual(policy["contract"], "mad4b.native-source-publisher-policy.v2")
        self.assertEqual(policy["operation"], "source.code.release")
        self.assertEqual(policy["broker_public_key_b64"], "NOT_ENROLLED")
        self.assertEqual(policy["master_approval_public_key_b64"], "NOT_ENROLLED")
        self.assertEqual(list(policy["targets"]), ["tours-central:staging"])
        target = policy["targets"]["tours-central:staging"]
        self.assertEqual(target["site_uuid"], "e5e64bca-ed62-48d2-b2b3-dff693cd702a")
        self.assertEqual(target["origin"], "https://tours.dev.mad4b.com")
        paths = target["allowed_targets"]
        self.assertEqual(len(paths), len(set(paths)))
        self.assertTrue(REQUIRED.issubset(paths))
        self.assertEqual(paths, proposal["file_allowlist"])
        self.assertEqual(proposal["operation_id"], "source.code.release")
        self.assertEqual(proposal["approval_ability"], "mad4b/native-source-release")
        for key in ("registry_enrolled", "publisher_enabled", "production_writes_authorized"):
            self.assertFalse(proposal[key])
        self.assertTrue(proposal["source_only"])

if __name__ == "__main__":
    unittest.main()
