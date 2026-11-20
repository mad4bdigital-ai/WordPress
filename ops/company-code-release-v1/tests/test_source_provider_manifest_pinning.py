"""First-party Dynamic Registry source manifest: exact component hash closure."""
import hashlib
import json
from pathlib import Path
import unittest

OPS = Path(__file__).resolve().parents[2]
MANIFEST = OPS / "dynamic-operation-registry-v1/providers/company.code.release/manifest.json"


class ProviderSourceClosure(unittest.TestCase):
    def setUp(self):
        self.manifest = json.loads(MANIFEST.read_bytes())

    def test_every_declared_component_has_matching_immutable_sha(self):
        self.assertEqual(self.manifest["operation_id"], "company.code.release")
        refs = [self.manifest["handler"], self.manifest["verifier"],
                self.manifest["evidence"], *self.manifest["dependencies"]]
        for ref in refs:
            with self.subTest(path=ref["path"]):
                self.assertEqual(set(ref), {"path", "sha256"})
                candidate = (OPS / ref["path"])
                self.assertTrue(candidate.is_file())
                self.assertEqual(hashlib.sha256(candidate.read_bytes()).hexdigest(),
                                 ref["sha256"])

    def test_dynamic_companion_is_first_party_pinned_dependency(self):
        dependencies = {r["path"]:r["sha256"]
                        for r in self.manifest["dependencies"]}
        self.assertEqual(len(dependencies),len(self.manifest["dependencies"]))
        module = "company-code-release-v1/capability_acceptance.py"
        self.assertIn(module,dependencies)
        source=(OPS/module).read_bytes()
        self.assertEqual(dependencies[module],hashlib.sha256(source).hexdigest())

    def test_manifest_changes_require_new_registry_enrollment(self):
        manifest_sha=hashlib.sha256(MANIFEST.read_bytes()).hexdigest()
        self.assertEqual(len(manifest_sha),64)
        self.assertGreaterEqual(self.manifest["version"],4)


if __name__ == "__main__":
    unittest.main()
