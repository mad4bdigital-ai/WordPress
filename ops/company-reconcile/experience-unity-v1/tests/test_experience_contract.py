from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[4]
AUDIT=ROOT/'ops/company-reconcile/experience-unity-v1/audit_experience.py'
GEO=ROOT/'ops/company-reconcile/price-presentation-v1/country_scope_contract.py'
SCOPE=ROOT/'wp-content/mu-plugins/020-mad4b-company-country-operating-scope-v1.php'

class ExperienceContractRegressionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.audit=AUDIT.read_text()
        cls.geo=GEO.read_text()
        cls.scope=SCOPE.read_text()

    def test_active_locale_list_never_assumes_en_es(self):
        self.assertIn("mad4b_home_sections_active_languages()",self.audit)
        self.assertIn("'active_language_codes'=>$active",self.geo)
        self.assertNotIn("foreach(['en','es']",self.audit)

    def test_country_scope_audit_every_locale(self):
        self.assertIn("language_scope_coverage",self.geo)
        self.assertIn("PUBLISHED_TOURS_OUTSIDE_OPERATING_SCOPE",self.geo)
        self.assertIn("WPML_GEOGRAPHIC_LANGUAGE_COVERAGE_REQUIRED",self.geo)
        self.assertIn("do_action('wpml_switch_language',$lang)",self.geo)

    def test_tour_country_authority_never_localized_ancestor(self):
        self.assertIn("{$wpdb->term_taxonomy}",self.scope)
        self.assertIn("function reviewed_countries",self.scope)
        self.assertIn("HOLD_COUNTRY_REQUIREMENTS_MISMATCH",self.scope)
        self.assertIn("HOLD_COUNTRY_NOT_ENABLED",self.scope)

    def test_frontend_checks_all_three_journey_stages(self):
        self.assertIn('"home",lang',self.audit)
        self.assertIn('"archive",lang',self.audit)
        self.assertIn('"tour",lang',self.audit)
        self.assertIn("COMBINED_FILTER_HOME_CONTENT_MISMATCH",self.audit)
        self.assertIn("HEADER_COUNTRY_OUTSIDE_OPERATING_SCOPE",self.audit)
        self.assertIn("ARCHIVE_PRICE_TAG_NOT_RENDERED",self.audit)

    def test_no_unauthorized_mutation_or_cross_domain_request(self):
        self.assertIn("UNENROLLED_LOCALE_ORIGIN",self.audit)
        self.assertIn("UNEXPECTED_HOST_REDIRECT",self.audit)
        self.assertIn('"read_only":True',self.audit)
        self.assertNotIn("wp_update_post(",self.audit)
        self.assertNotIn("update_option(",self.audit)
        self.assertNotIn("wp_cache_flush(",self.audit)

if __name__=="__main__":
    unittest.main()
