#!/usr/bin/env python3
"""Pure source contract checks for registered JetEngine Options Pages.

This is intentionally NOT a WordPress settings writer or a runtime field ACL.
"""
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "governance/company-clone/jetengine-options-source-policy-v1.json"
COMPANY = ROOT / "governance/company-clone/field-source-policy-v1.json"


class JetEngineCompanyOptionsPolicyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.p = json.loads(SOURCE.read_text(encoding="utf-8"))
        cls.company = json.loads(COMPANY.read_text(encoding="utf-8"))

    def field(self, slug, name):
        return next(x for x in self.p["pages"][slug]["fields"] if x["field"] == name)

    def test_registered_page_field_and_repeater_coverage(self):
        pages = self.p["pages"]
        self.assertEqual(len(pages), 11)
        self.assertEqual(sum(p["registered_fields"] for p in pages.values()), 180)
        self.assertEqual(
            sum(len(f.get("children", [])) for page in pages.values() for f in page["fields"]),
            64,
        )
        for page in pages.values():
            self.assertEqual(page["registered_fields"], len(page["fields"]))
            self.assertEqual(
                len({f["field"] for f in page["fields"]}), len(page["fields"])
            )
            self.assertNotIn("BLOCK_REVIEW", {f["policy"] for f in page["fields"]})
        self.assertEqual(self.p["schema_reconciliation"]["registered_field_unknown_count"], 0)
        self.assertEqual(self.p["state"], "review_only_no_runtime_enforcement")

    def test_legacy_option_keys_never_inherited(self):
        self.assertEqual(self.p["default_unknown"], "BLOCK_REVIEW")
        orphans = self.p["legacy_orphans"]
        self.assertEqual(
            set(orphans["look-and-feel"]["keys"]),
            {"busness_reviews_section_tab", "destination_activity_card_alternate"},
        )
        self.assertEqual(orphans["contact-and-help-info"]["keys"], ["social_links_tab"])
        for page in orphans.values():
            self.assertEqual(page["clone_policy"], "BLOCK_REVIEW")
        for slug, orphan in (
            ("look-and-feel", "busness_reviews_section_tab"),
            ("look-and-feel", "destination_activity_card_alternate"),
            ("contact-and-help-info", "social_links_tab"),
        ):
            self.assertNotIn(orphan, {f["field"] for f in self.p["pages"][slug]["fields"]})

    def test_demo_marketing_changed_fields_are_owned_not_shared(self):
        changed = self.p["demo_placeholder_updates"]["modified_option_fields"]
        self.assertTrue(self.p["demo_placeholder_updates"]["do_not_inherit_to_real_companies"])
        self.assertEqual(len(changed["look-and-feel"]), 11)
        self.assertEqual(changed["contact-and-help-info"], ["support_group_url_aria_label"])
        for page, keys in changed.items():
            for key in keys:
                field = self.field(page, key)
                self.assertTrue(field["demo_placeholder_updated_on_site"])
                self.assertEqual(field["policy"], "TENANT_MARKETING")
        self.assertEqual(self.field("look-and-feel", "review_gellery_slider")["policy"], "TENANT_MEDIA")
        self.assertEqual(self.field("look-and-feel", "company_pattern_accent")["policy"], "TENANT_BRAND")
        self.assertEqual(self.field("contact-and-help-info", "site_email")["policy"], "TENANT_CONTACT")
        self.assertEqual(self.field("contact-and-help-info", "trip_advisor_review_shortcode")["policy"], "RECONNECT_PROVIDER")

    def test_company_identity_emails_staff_and_sender_boundaries(self):
        page = "company-communication-operations"
        for key in ("company_display_name",):
            self.assertEqual(self.field(page, key)["policy"], "TENANT_IDENTITY")
        for key in ("transactional_from_email", "traveler_reply_to", "bookings_inbox", "support_inbox"):
            self.assertEqual(self.field(page, key)["policy"], "TENANT_CONTACT")
        for key in ("operations_internal_team", "operations_external_team"):
            field = self.field(page, key)
            self.assertEqual(field["policy"], "TENANT_PERSONNEL")
            self.assertEqual(len(field["children"]), 5)
            self.assertEqual(set(f["policy"] for f in field["children"]), {"TENANT_PERSONNEL"})
        self.assertEqual(self.field(page, "smtp_connection_label")["policy"], "RECONNECT_PROVIDER")

    def test_commerce_and_outbound_disabled_for_new_company(self):
        for page in ("etg-dynamic-pricing-controls", "calculate-booking-price"):
            for field in self.p["pages"][page]["fields"]:
                self.assertIn(field["policy"], ("SCHEMA_ONLY", "TENANT_COMMERCE_GATED"))
        for key in ("booking_enabled", "pricing_engine_enabled", "shared_financial_rules_json"):
            self.assertEqual(
                self.field("etg-dynamic-pricing-controls", key)["policy"],
                "TENANT_COMMERCE_GATED",
            )
        gate = " ".join(self.p["copy_gate"])
        for key in ("outbound_email_policy", "booking_enabled", "pricing_engine_enabled", "offers_enabled"):
            self.assertIn(key, gate)
        self.assertIn("disabled", gate)

    def test_company_scoped_storage_requires_resolver_no_global_fallback(self):
        model = self.p["scope_model"]
        self.assertIn("site-global", model["multi_company_single_site"])
        self.assertIn("company-specific storage", model["multi_company_single_site"])
        self.assertIn("company_id", " ".join(self.p["copy_gate"]))
        self.assertIn("site_uuid", " ".join(self.p["copy_gate"]))
        self.assertEqual(self.company["jetengine_options_ref"], SOURCE.relative_to(ROOT).as_posix())


if __name__ == "__main__":
    unittest.main()
