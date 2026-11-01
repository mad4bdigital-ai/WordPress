#!/usr/bin/env python3
"""Hermetic source contract guard, not a live deployment or 305/313 attestation."""
import ast
import pathlib
import subprocess
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parent
CORE = ROOT / "company-unified-operation-policy.py"
RESOLVER = ROOT / "universal-runtime" / "resolver.py"

class UnifiedPolicySourceTests(unittest.TestCase):
    def test_projection_selftest(self):
        completed = subprocess.run(
            [sys.executable, str(CORE), "selftest"], capture_output=True, text=True,
            timeout=15, check=False)
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertIn('"tests": 10', completed.stdout)

    def test_source_functions_present(self):
        source = RESOLVER.read_text()
        functions = {n.name for n in ast.parse(source).body if isinstance(n, ast.FunctionDef)}
        self.assertTrue({"inspect_registered_release_readback", "inspect_authority",
                         "inventory", "show_status"}.issubset(functions))
        self.assertNotIn('target=="egypt-tour-gates:staging"', source)

    def test_transport_is_not_authorization(self):
        source = RESOLVER.read_text()
        self.assertIn('"native_write_granted_by_observer":False', source)
        self.assertIn('"original_core_operation_authority_separate":True', source)
        projection = CORE.read_text()
        self.assertIn("'native_execution_granted_by_legacy':False", projection)
        self.assertIn("'original_authority':'live_mad4b_company_execution_resolver'", projection)

    def test_no_grant_creating_source_contract(self):
        projection = CORE.read_text()
        self.assertIn("'grants_created':False", projection)
        self.assertIn("'operations_executed':False", projection)
        self.assertIn("'production_mutated':False", projection)

if __name__ == "__main__":
    unittest.main()
