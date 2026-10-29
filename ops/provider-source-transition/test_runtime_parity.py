"""Prove that runtime version/filename alone is not deployment certification."""
import hashlib
import unittest
from audit_runtime_parity import MAIN, assess


class RuntimeParityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.actual = MAIN.read_text(encoding="utf-8")
        cls.version = assess(cls.actual)["source_version"]
        cls.sha = hashlib.sha256(cls.actual.encode("utf-8")).hexdigest()

    def test_actual_source_release_is_declared_consistently(self):
        result = assess(self.actual)
        self.assertTrue(result["source_header_constant_consistent"])
        self.assertFalse(result["exact_source_file_match"])
        self.assertFalse(result["runtime_adapter_migration_authorized"])

    def test_last_observed_staging_release_does_not_match_source(self):
        # Observation is informational and not signed runtime provenance.
        result = assess(self.actual, observed_version="0.4.0-rc.118")
        self.assertNotEqual(result["source_version"], result["observed_runtime_version"])
        self.assertFalse(result["exact_source_file_match"])

    def test_version_match_without_digest_is_not_parity(self):
        result = assess(self.actual, observed_version=self.version, site_uuid="tenant-001")
        self.assertFalse(result["exact_source_file_match"])
        self.assertFalse(result["verified_deployment_authority"])

    def test_file_match_without_site_identity_is_not_parity(self):
        result = assess(self.actual, self.version, self.sha, None)
        self.assertFalse(result["exact_source_file_match"])

    def test_exact_file_match_still_requires_signed_authority(self):
        result = assess(self.actual, self.version, self.sha, "tenant-001")
        self.assertTrue(result["exact_source_file_match"])
        self.assertFalse(result["verified_deployment_authority"])
        self.assertFalse(result["runtime_adapter_migration_authorized"])

    def test_malformed_source_version_fails(self):
        with self.assertRaises(ValueError):
            assess(self.actual.replace(
                "define( 'MAD4B_SCP_VERSION',", "define( 'MISSING_VERSION',"
            ))

    def test_unexpected_digest_does_not_match(self):
        result = assess(self.actual, self.version, "a" * 64, "tenant-001")
        self.assertFalse(result["exact_source_file_match"])


if __name__ == "__main__":
    unittest.main()
