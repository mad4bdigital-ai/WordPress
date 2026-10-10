#!/usr/bin/env python3
"""Negative policy tests for observed, non-signing standalone native evidence."""
import tempfile
from pathlib import Path
import unittest

import mad4b_standalone_evidence as native


class EvidencePolicyTests(unittest.TestCase):
    def test_states_fail_closed(self):
        self.assertEqual(native.status_from_checks([]), "BLOCKED")
        self.assertEqual(native.status_from_checks([{"state": "BLOCKED"}]), "BLOCKED")
        self.assertEqual(native.status_from_checks([{"state": "PASS"}, {"state": "FAIL"}]), "FAIL")
        self.assertEqual(native.status_from_checks([{"state": "FAIL"}, {"state": "BLOCKED"}]), "FAIL")
        self.assertEqual(native.status_from_checks([{"state": "PASS"}]), "PASS")

    def test_missing_source_cannot_yield_pass(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            results, evidence = native.evidence(root, "a" * 40,
                root / "missing.zip", root / "receipt.json", "php")
            self.assertEqual(set(results), set(native.GATES))
            self.assertFalse(any(x == "PASS" for x in results.values()))
            self.assertFalse(evidence["external_owner_review_required"] is False)
            self.assertFalse(evidence["github_ci_certified"])
            self.assertFalse(evidence["production_authorized"])

    def test_no_signature_or_deployment_capability(self):
        self.assertEqual(native.CONTRACT, "mad4b.standalone-native-evidence.v1")
        self.assertEqual(len(native.GATES), 5)
        self.assertFalse(hasattr(native, "sign_release"))
        self.assertFalse(hasattr(native, "deploy_to_wordpress"))


if __name__ == "__main__":
    unittest.main()
