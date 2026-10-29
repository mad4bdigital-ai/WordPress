"""Behavioral certification: no implicit legacy ownership, activation or writes."""
import copy
import unittest
from resolve_source import CATALOG, POLICY, indexes, load, plan


class SourceTransitionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.policy = load(POLICY)
        cls.catalog = load(CATALOG)

    def test_source_catalog_has_distinct_legacy_and_canonical(self):
        families, sources = indexes(self.policy, self.catalog)
        family = families["dynamic-filter-seo-bridge"]
        self.assertNotEqual(family["canonical_source_id"], family["legacy_source_ids"][0])
        self.assertEqual(sources[family["canonical_source_id"]]["plugin_subdirectory"],
                         "wp-content/plugins/dynamic-filter-seo-bridge")
        self.assertEqual(sources[family["legacy_source_ids"][0]]["plugin_subdirectory"],
                         "wp-content/plugins/etg-dynamic-filter-seo-bridge")

    def test_new_site_cannot_inherit_legacy_source(self):
        result = plan(self.policy, self.catalog, "dynamic-filter-seo-bridge", "new_site")
        self.assertEqual(result["source_id"], "dynamic-filter-seo-bridge")
        self.assertFalse(result["legacy_source"])
        self.assertFalse(result["legacy_fallback_used"])
        self.assertEqual(result["selection"], "canonical_candidate_only")
        self.assertFalse(result["execution_allowed"])
        self.assertFalse(result["mutation_allowed"])
        self.assertFalse(result["publication_allowed"])

    def test_existing_site_preserves_legacy_pin_without_promotion(self):
        result = plan(self.policy, self.catalog, "dynamic-filter-seo-bridge",
                      "existing_site", "etg-dynamic-filter-seo-bridge")
        self.assertEqual(result["selection"], "preserve_existing_pin")
        self.assertTrue(result["legacy_source"])
        self.assertFalse(result["writes_inherited_from_legacy"])
        self.assertFalse(result["mutation_allowed"])

    def test_no_unlisted_site_or_source_inheritance(self):
        with self.assertRaises(ValueError):
            plan(self.policy, self.catalog, "dynamic-filter-seo-bridge",
                 "new_site", "etg-dynamic-filter-seo-bridge")
        with self.assertRaises(ValueError):
            plan(self.policy, self.catalog, "dynamic-filter-seo-bridge",
                 "existing_site", "some-other-company")
        with self.assertRaises(ValueError):
            plan(self.policy, self.catalog, "missing_family", "new_site")

    def test_real_candidate_is_review_only(self):
        result = plan(self.policy, self.catalog, "dynamic-filter-seo-bridge", "new_site")
        self.assertFalse(result["catalog_evidence"]["package_evidence_complete"])
        self.assertEqual(result["required_next_gate"],
                         "exact_package_certification_and_site_owner_approval")

    def test_forged_certification_cannot_authorize_runtime(self):
        fake = copy.deepcopy(self.catalog)
        target = next(row for row in fake["sources"]
                      if row["id"] == "dynamic-filter-seo-bridge")
        target.update({
            "package_certification": "certified",
            "source_commit_sha": "a" * 40,
            "artifact_sha256": "b" * 64,
            "artifact_version": "9.9.9",
        })
        report = plan(self.policy, fake, "dynamic-filter-seo-bridge", "new_site")
        self.assertTrue(report["catalog_evidence"]["package_evidence_complete"])
        self.assertFalse(report["execution_allowed"])
        self.assertFalse(report["publication_allowed"])
        self.assertFalse(report["mutation_allowed"])

    def test_missing_or_duplicated_catalog_fails_closed(self):
        duplicate = copy.deepcopy(self.catalog)
        duplicate["sources"].append(copy.deepcopy(duplicate["sources"][0]))
        with self.assertRaises(ValueError):
            indexes(self.policy, duplicate)
        missing = copy.deepcopy(self.catalog)
        missing["sources"] = []
        with self.assertRaises(ValueError):
            indexes(self.policy, missing)

    def test_policy_cannot_grant_execution(self):
        unsafe = copy.deepcopy(self.policy)
        unsafe["execution_authority"] = "inherit_legacy"
        with self.assertRaises(ValueError):
            indexes(unsafe, self.catalog)
        unsafe = copy.deepcopy(self.policy)
        unsafe["families"][0]["mutation_policy"] = "allow"
        with self.assertRaises(ValueError):
            indexes(unsafe, self.catalog)


if __name__ == "__main__":
    unittest.main()
