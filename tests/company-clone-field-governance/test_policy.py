#!/usr/bin/env python3
"""Review-time contract tests; no WordPress or database connections."""
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
POLICY = ROOT / "governance/company-clone/field-source-policy-v1.json"

class CompanyCloneFieldPolicyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.p = json.loads(POLICY.read_text(encoding="utf-8"))

    def test_proposed_and_fail_closed(self):
        p = self.p
        self.assertEqual(p["contract"], "mad4b.company-clone-field-source-governance.v1")
        self.assertEqual(p["status"], "proposed_source_controlled_policy_no_runtime_apply")
        self.assertEqual(p["default_unknown_action"], "REVIEW_REQUIRED")
        self.assertTrue(p["origin"]["core_demo_site_name_alignment_verified"])
        self.assertFalse(p["origin"]["full_demo_brand_neutralization_verified"])
        self.assertTrue(p["origin"]["legacy_company_contact_media_provider_bindings_pending"])

    def test_source_inventory_coverage_is_explicit(self):
        ev = self.p["evidence"]
        self.assertEqual(ev["source_occurrences"], 4837)
        self.assertEqual(ev["option_keys"], 2461)
        self.assertEqual(ev["postmeta_type_key_pairs"], 801)
        self.assertEqual(ev["termmeta_taxonomy_key_pairs"], 148)
        self.assertEqual(ev["usermeta_keys"], 253)
        self.assertEqual(ev["nested_keys_explicit"], 71)
        self.assertGreater(ev["unclassified_provisional"], 0)

    def test_nested_company_options_exact_source(self):
        nested = self.p["nested_options"]
        self.assertEqual(len(nested["contact-and-help-info"]), 17)
        self.assertEqual(len(nested["look-and-feel"]), 39)
        self.assertEqual(len(nested["rank-math-options-titles"]), 9)
        term_fields = self.p["taxonomy_term_fields"]["destinations"]
        self.assertEqual(len(term_fields), 6)
        self.assertEqual(sum(len(v) for v in nested.values()) + len(term_fields), 71)
        self.assertEqual(term_fields["google_place_id"], "REMAP_GEO")
        self.assertEqual(term_fields["image_gellery"], "TENANT_MEDIA")
        self.assertEqual(nested["contact-and-help-info"]["site_email"], "TENANT_SOURCE")
        self.assertEqual(nested["contact-and-help-info"]["site_whatsapp_num"], "TENANT_SOURCE")
        self.assertEqual(nested["look-and-feel"]["review_gellery_slider"], "TENANT_MEDIA")
        self.assertEqual(nested["look-and-feel"]["company_pattern_accent"], "TENANT_SOURCE")
        self.assertEqual(nested["look-and-feel"]["destination_tours_heading"], "SHARED_DEFINITION")
        self.assertEqual(nested["rank-math-options-titles"]["facebook_secret"], "RECONNECT_PROVIDER")
        self.assertEqual(nested["rank-math-options-titles"]["knowledgegraph_name"], "TENANT_SOURCE")
        self.assertEqual(nested["rank-math-options-titles"]["knowledgegraph_logo_id"], "TENANT_MEDIA")

    def test_no_company_media_is_shared_in_review_gallery(self):
        for key in ("review_hero_image", "review_gellery_slider", "company_about_customization"):
            self.assertEqual(self.p["nested_options"]["look-and-feel"][key], "TENANT_MEDIA")
        review = [x for x in self.p["provision_group_policy"]
                  if x["source"] == "Our traveler reviews template"]
        self.assertEqual(len(review), 1)
        self.assertEqual(review[0]["policy"], "SHARED_DEFINITION")
        self.assertIn("TENANT_MEDIA", review[0]["caveat"])

    def test_provider_account_bindings_are_not_shared(self):
        provider_names = [x["provider"] for x in self.p["external_provider_bindings"]]
        for name in ("Trustindex / TripAdvisor", "LiteSpeed Cache / QUIC.cloud",
                     "Google Tag Manager / Google Analytics", "Fluent SMTP and FluentCRM delivery",
                     "WooCommerce gateways / Elementor payment",
                     "Google Drive OAuth and AI providers"):
            self.assertIn(name, provider_names)
        for x in self.p["external_provider_bindings"]:
            self.assertIn(x["policy"], ("RECONNECT_PROVIDER", "TENANT_SOURCE"))

    def test_site_commerce_secrets_and_qa_isolation(self):
        for group in self.p["provision_group_policy"]:
            self.assertIn(group["policy"], self.p["semantics"])
            if group["source"].startswith("QA fixtures"):
                self.assertEqual(group["policy"], "SANDBOX_ONLY")
            if group["source"].startswith("Tenant users, roles"):
                self.assertEqual(group["policy"], "NO_CROSS_TENANT_COPY")
        requirements = " ".join(self.p["required_preflight"]).lower()
        for word in ("database", "credentials", "submission", "media", "no qa", "unknown"):
            if word == "unknown":
                self.assertEqual(self.p["default_unknown_action"], "REVIEW_REQUIRED")
            else:
                self.assertIn(word, requirements)

if __name__ == "__main__":
    unittest.main()
