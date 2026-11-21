from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[4]
SCOPE=ROOT/'wp-content/mu-plugins/020-mad4b-company-country-operating-scope-v1.php'

class CountryScopeCanonicalWpmlTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.s=SCOPE.read_text()

    def test_term_ancestry_does_not_use_locale_sensitive_get_term(self):
        start=self.s.index("    public static function canonical_country")
        end=self.s.index("    public static function canonical_set",start)
        body=self.s[start:end]
        self.assertIn("{$wpdb->term_taxonomy}",body)
        self.assertIn("WHERE taxonomy=%s AND term_id=%d",body)
        self.assertIn("'destinations',$id",body)
        self.assertNotIn("get_term($id,",body)
        self.assertNotIn("$term->parent",body)
        self.assertIn("get_term_meta($id,'destination_type',true)",body)

    def test_combined_tour_canonical_type_works_all_wpml_languages(self):
        start=self.s.index("    public static function is_combined")
        end=self.s.index("    public static function decision",start)
        body=self.s[start:end]
        self.assertIn("{$wpdb->term_taxonomy}",body)
        self.assertIn("apply_filters('wpml_object_id'",body)
        self.assertIn("'tour-types',false,'en'",body)
        self.assertNotIn("has_term('combined-tours'",body)

    def test_canonical_english_reviewed_itinerary_inherited(self):
        self.assertIn("function reviewed_countries",self.s)
        self.assertIn("'tours-and-activities',false,'en'",self.s)
        self.assertIn("self::reviewed_countries($tour_id)",self.s)
        self.assertIn("function language_country_mismatch",self.s)
        self.assertIn("'HOLD_COUNTRY_REQUIREMENTS_MISMATCH'",self.s)

    def test_tenant_scope_stays_tenant_owned(self):
        self.assertIn("!self::$authorized_scope_save",self.s)
        self.assertIn("'HOLD_COUNTRY_NOT_ENABLED'",self.s)
        self.assertIn("self::operating_countries()",self.s)
        self.assertNotIn("wp_publish_post(",self.s)
        self.assertNotIn("update_option('destination-activation'",self.s)

if __name__=="__main__":
    unittest.main()
