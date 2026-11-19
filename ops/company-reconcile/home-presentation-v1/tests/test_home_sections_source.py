import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[4]
MODULE=ROOT/"wp-content/plugins/mad4b-platform/modules/operations/mad4b-central-homepage-sections.php"
class PortableHomeSourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.s=MODULE.read_text()
    def test_no_staging_or_company_hostname_gate(self):
        self.assertNotIn("wp_get_environment_type()",self.s)
        self.assertNotIn("rtrim(home_url(), '/') !== 'https://tours.dev.mad4b.com'",self.s)
    def test_actual_front_page_source(self):
        self.assertIn("get_option('page_on_front')",self.s)
        self.assertIn("wpml_object_id',$source_home,'page'",self.s)
    def test_service_policy_is_authoritative(self):
        self.assertIn("Presentation_Policy_Context_v1::decide",self.s)
        self.assertIn("shared_presentation_public_tour_query_args_v1",self.s)
        self.assertIn("shared_presentation_published_facet_terms_v1",self.s)
    def test_wpml_shortcode_and_template_ids(self):
        self.assertIn("source_shortcodes=[41727=>",self.s)
        self.assertIn("wpml_object_id',$source_id,'elementor_library'",self.s)
        self.assertIn("translated_ids_by_language",self.s)
        self.assertIn("$memo_key=$key.'|'.$language",self.s)
    def test_empty_sections_hidden_without_content(self):
        self.assertIn("home_empty_nile",self.s)
        self.assertIn("home_empty_combined",self.s)
        self.assertIn("elementor/frontend/widget/should_render",self.s)
    def test_change_driven_cache_invalidation(self):
        self.assertIn("update_option_look-and-feel",self.s)
        self.assertIn("mad4b/public_html/safe_purge_all",self.s)
        self.assertNotIn("wp_cache_flush(",self.s)
        self.assertNotIn("purge_all_object(",self.s)
if __name__=="__main__":
    unittest.main()

class DynamicWpmlLanguageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module=MODULE.read_text()
        cls.verifier=(MODULE.parents[5]/"ops/company-reconcile/home-presentation-v1/verify_home_sections.py").read_text()

    def test_uses_wpml_active_languages_not_fixed_six(self):
        self.assertIn("function mad4b_home_sections_active_languages():array",self.module)
        self.assertIn("get_option('icl_sitepress_settings'",self.module)
        self.assertIn("wpml_active_languages",self.module)
        self.assertIn("mad4b_home_sections_active_languages() as $lang",self.module)
        self.assertNotIn("foreach(['en','ar','fr','de','es','it']",self.module)

    def test_wpml_activation_invalidates_public_html_only(self):
        self.assertIn("update_option_icl_sitepress_settings",self.module)
        self.assertIn("company_wpml_active_home_languages_changed",self.module)
        self.assertIn("mad4b/public_html/safe_purge_all",self.module)
        self.assertNotIn("wp_cache_flush(",self.module)

    def test_verifier_checks_dynamic_locale_codes_and_urls(self):
        self.assertIn("mad4b_home_sections_active_languages()",self.verifier)
        self.assertIn("active_language_codes",self.verifier)
        self.assertIn("home_page_status",self.verifier)
        self.assertIn("HOME_WPML_LANGUAGE_ORIGIN_NOT_ENROLLED",self.verifier)
        self.assertNotIn('assert language in ("en","es")',self.verifier)
        self.assertNotIn('len(x["languages"])!=2',self.verifier)

    def test_tenant_language_list_never_copy_source_list(self):
        self.assertNotIn("active_languages'=>['en'",self.module)
        self.assertIn("return array_keys($codes);",self.module)
