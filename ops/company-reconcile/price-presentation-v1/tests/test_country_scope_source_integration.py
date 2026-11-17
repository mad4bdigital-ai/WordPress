from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[4]
SCOPE=ROOT/'wp-content/mu-plugins/020-mad4b-company-country-operating-scope-v1.php'
WORKSPACE=ROOT/'wp-content/plugins/mad4b-jetengine/modules/functional-modes/class-pricing-workspace.php'

class CountryScopeSourceIntegrationTests(unittest.TestCase):
    def test_same_service_activation_owner_not_second_option(self):
        s=SCOPE.read_text()
        self.assertIn("get_option('service-activation'",s)
        self.assertIn("update_option('service-activation'",s)
        self.assertIn("pre_update_option_service-activation",s)
        self.assertIn("private static bool $authorized_scope_save=false",s)
        self.assertIn("!self::$authorized_scope_save",s)
        self.assertIn("finally { self::$authorized_scope_save=false; }",s)
        self.assertIn("destination-activation",s)
    def test_publication_is_fail_closed_for_undeclared_combined_tours(self):
        s=SCOPE.read_text()
        self.assertIn("$unknown=self::is_combined($tour_id)&&!$explicit",s)
        self.assertIn("'HOLD_COUNTRY_EVIDENCE_MISSING'",s)
        self.assertIn("'HOLD_COUNTRY_NOT_ENABLED'",s)
        self.assertIn("if(!self::decision($id)['eligible_for_geographic_publication'])",s)
        self.assertIn("$data['post_status']='draft'",s)
        self.assertNotIn("$data['post_status']='publish'",s)
    def test_tour_editing_has_nonce_and_distinct_country_requirements(self):
        s=SCOPE.read_text()
        self.assertIn("add_meta_boxes_tours-and-activities",s)
        self.assertIn("check_admin_referer('mad4b_country_operating_scope_save_v1')",s)
        self.assertIn("wp_verify_nonce($nonce",s)
        self.assertIn("'_mad4b_required_country_ids_v1'",s)
        self.assertIn("wpml_object_id",s)
        self.assertIn("update_post_meta($id,self::META,$countries)",s)
    def test_pricing_workspace_shows_scope_without_modifying_rates(self):
        s=WORKSPACE.read_text()
        self.assertIn("'Company Country Scope'",s)
        self.assertIn("MAD4B_Company_Country_Operating_Scope_V1::link()",s)
        self.assertIn("'Rate Generator'",s)
        self.assertIn("'Pricing Controls'",s)
    def test_no_uncontrolled_country_activation_in_module(self):
        s=SCOPE.read_text()
        self.assertNotIn("update_option('destination-activation'",s)
        self.assertNotIn("wp_set_post_terms($id,[]",s)
        self.assertNotIn("wp_publish_post(",s)

if __name__=='__main__':unittest.main()
