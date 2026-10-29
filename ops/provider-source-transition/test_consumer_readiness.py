"""Keep the native legacy consumer inventory separate from migration authority."""
import json
import re
import unittest
from audit_consumer_readiness import (
    NEUTRAL_ENTRY, READ_ADAPTER, WRITE_ADAPTER, REGISTRY, SKILLS, MIRROR, SOURCES,
    audit, current,
)


class ConsumerReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sources = {
            "read": READ_ADAPTER.read_text(encoding="utf-8"),
            "write": WRITE_ADAPTER.read_text(encoding="utf-8"),
            "neutral": NEUTRAL_ENTRY.read_text(encoding="utf-8"),
            "registry": json.loads(REGISTRY.read_text(encoding="utf-8")),
            "skills": json.loads(SKILLS.read_text(encoding="utf-8")),
            "mirror": MIRROR.read_text(encoding="utf-8"),
            "catalog": json.loads(SOURCES.read_text(encoding="utf-8")),
        }

    def run_audit(self, **updates):
        values = dict(self.sources)
        values.update(updates)
        return audit(
            values["read"], values["write"], values["neutral"],
            values["registry"], values["skills"], values["mirror"], values["catalog"],
        )

    def test_source_audit_never_authorizes_cutover(self):
        result = current()
        self.assertEqual(result["contract"], "mad4b.provider-consumer-readiness.v1")
        self.assertTrue(result["source_only_audit"])
        self.assertFalse(result["safe_to_cutover"])
        self.assertFalse(result["new_write_authority"])
        self.assertEqual(result["canonical_provider_id"], "dynamic-filter-seo-bridge")

    def test_distinct_actual_upstream_versions(self):
        result = current()
        self.assertTrue(result["legacy_version_alias_present"])
        self.assertTrue(result["legacy_read_class_reviewed"])
        self.assertTrue(result["legacy_write_class_reviewed"])
        self.assertTrue(result["legacy_read_skill_present"])
        self.assertTrue(result["legacy_option_mirror_present"])
        self.assertNotEqual(
            result["legacy_read_supported_version"], result["neutral_plugin_version"]
        )
        self.assertNotEqual(
            result["legacy_write_supported_version"], result["neutral_plugin_version"]
        )
        self.assertFalse(result["legacy_read_version_compatible"])
        self.assertFalse(result["legacy_write_version_compatible"])

    def test_legacy_ability_identifiers_still_inventory_only(self):
        result = current()
        self.assertIn("etg-dfsb/status", result["legacy_read_abilities"])
        self.assertIn("etg-dfsb/slot-apply", result["legacy_write_abilities"])
        self.assertIn("etg-dfsb/profile-apply", result["legacy_write_abilities"])
        self.assertFalse(result["new_write_authority"])

    def test_alias_removed_still_no_authority(self):
        content = self.sources["neutral"].replace(
            "'ETG_DFSB_VERSION' => DFSB_VERSION",
            "'HISTORICAL_VERSION_REMOVED' => DFSB_VERSION",
        )
        result = self.run_audit(neutral=content)
        self.assertFalse(result["legacy_version_alias_present"])
        self.assertFalse(result["safe_to_cutover"])

    def test_even_matching_version_is_not_a_rollout_certificate(self):
        current_version = self.run_audit()["neutral_plugin_version"]
        before = "define( 'DFSB_VERSION', '" + current_version + "' );"
        after = "define( 'DFSB_VERSION', '0.4.0-alpha.13' );"
        self.assertIn(before, self.sources["neutral"])
        neutral = self.sources["neutral"].replace(before, after)
        result = self.run_audit(neutral=neutral)
        self.assertTrue(result["legacy_read_version_compatible"])
        self.assertTrue(result["legacy_write_version_compatible"])
        self.assertFalse(result["new_write_authority"])
        self.assertFalse(result["safe_to_cutover"])

    def test_missing_reviewed_adapter_keeps_cutover_denied(self):
        reg = json.loads(json.dumps(self.sources["registry"]))
        reg["approved_classes"].remove("MAD4B_SCP_ETG_DFSB_Write_Adapter")
        result = self.run_audit(registry=reg)
        self.assertFalse(result["legacy_write_class_reviewed"])
        self.assertFalse(result["new_write_authority"])

    def test_candidate_version_drift_detected_without_promotion(self):
        data = json.loads(json.dumps(self.sources["catalog"]))
        source = next(row for row in data["sources"]
                      if row.get("id") == "dynamic-filter-seo-bridge")
        source["candidate_artifact_version"] = "0.0.0-unreviewed"
        report = self.run_audit(catalog=data)
        self.assertTrue(report["candidate_version_drift"])
        self.assertFalse(report["candidate_source_version_matches_checkout"])
        self.assertFalse(report["safe_to_cutover"])
        self.assertFalse(report["new_write_authority"])

    def test_matching_candidate_version_is_never_a_certificate(self):
        data = json.loads(json.dumps(self.sources["catalog"]))
        source = next(row for row in data["sources"]
                      if row.get("id") == "dynamic-filter-seo-bridge")
        source["candidate_artifact_version"] = self.run_audit()["neutral_plugin_version"]
        report = self.run_audit(catalog=data)
        self.assertTrue(report["candidate_source_version_matches_checkout"])
        self.assertFalse(report["candidate_version_drift"])
        self.assertFalse(report["safe_to_cutover"])
        self.assertFalse(report["new_write_authority"])

    def test_missing_or_duplicate_canonical_source_is_rejected(self):
        data = json.loads(json.dumps(self.sources["catalog"]))
        source = next(row for row in data["sources"]
                      if row.get("id") == "dynamic-filter-seo-bridge")
        data["sources"].append(json.loads(json.dumps(source)))
        with self.assertRaises(ValueError):
            self.run_audit(catalog=data)
        data["sources"] = [row for row in data["sources"]
                           if row.get("id") != "dynamic-filter-seo-bridge"]
        with self.assertRaises(ValueError):
            self.run_audit(catalog=data)

    def test_removed_native_version_contract_is_detected(self):
        source = self.sources["read"].replace(
            "const SUPPORTED_VERSION = '0.4.0-alpha.13';",
            "const REMOVED_VERSION = '0.4.0-alpha.13';",
        )
        with self.assertRaises(ValueError):
            self.run_audit(read=source)


if __name__ == "__main__":
    unittest.main()
