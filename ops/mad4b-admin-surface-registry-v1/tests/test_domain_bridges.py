from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[3]
read=lambda p:(ROOT/p).read_text()

class DomainBridgeContracts(unittest.TestCase):
    def test_fluent_forms_uses_only_native_form_and_entries_caps(self):
        text=read('wp-content/mu-plugins/014-mad4b-fluent-forms-scoped-menus-v1.php')
        self.assertIn("'fluent_forms#add=1'=>'fluentform_forms_manager'",text)
        self.assertIn("'fluent_forms_all_entries'=>'fluentform_entries_viewer'",text)
        self.assertIn("'fluent_forms_reports'=>'fluentform_entries_viewer'",text)
        self.assertIn("($item[1]??'')!=='fluentform_settings_manager'",text)
        self.assertNotIn("'fluent_forms_settings'=>'fluentform_entries_viewer'",text)
        self.assertNotIn("add_cap(",text)

    def test_both_pricing_entrypoints_preserve_read_only_and_scope(self):
        files=[
          'wp-content/mu-plugins/370-mad4b-pricing-workbench.php',
          'wp-content/plugins/mad4b-commerce/modules/commerce/370-mad4b-pricing-workbench.php',
        ]
        for path in files:
            with self.subTest(path=path):
                source=read(path)
                self.assertIn("MAD4B_Admin_Surface_Registry_V1::allowed('mad4b-pricing-workbench','read')",source)
                self.assertIn("current_user_can('edit_post',$id)",source)
                self.assertIn("add_action('admin_menu',['MAD4B_Pricing_Workbench','menu'],800)",source)
                self.assertIn("Preview-only:",source)

    def test_content_pipeline_native_save_is_still_admin_only(self):
        source=read('wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-dynamic-content-pipeline-admin.php')
        self.assertIn("MAD4B_Admin_Surface_Registry_V1::allowed(self::PAGE,'read')",source)
        self.assertIn("if(!current_user_can('manage_options')){",source)
        self.assertIn("Content Pipeline — read-only",source)
        self.assertIn("if(!current_user_can('manage_options'))wp_die(esc_html__('Administrator capability is required.",source)
        self.assertIn("check_admin_referer(self::ACTION)",source)

    def test_hub_keeps_central_permission_and_never_runs_native_operations(self):
        source=read('wp-content/mu-plugins/015-mad4b-company-content-hub-overview-v1.php')
        self.assertIn("MAD4B_Content_Hub::central_identity_ready()",source)
        self.assertIn("MAD4B_Admin_Surface_Registry_V1::allowed(self::SLUG,'read')",source)
        self.assertNotIn("::rest_run(",source)
        self.assertNotIn("wp_insert_post(",source)

    def test_legacy_crm_all_access_filter_no_longer_exists(self):
        legacy=read('wp-content/mu-plugins/003-mad4b-lifecycle-access.php')
        self.assertIn("MAD4B_Admin_Surface_Registry_V1::fluentcrm_permissions",legacy)
        self.assertNotIn("return \\FluentCrm\\App\\Services\\PermissionManager::pluginPermissions()",legacy)
        registry=read('wp-content/mu-plugins/012-mad4b-admin-surface-registry-v1.php')
        self.assertIn("public static function fluentcrm_permissions(",registry)
        self.assertIn("'fcrm_manage_settings'=>['commerce_manage_operations','commerce_manage_policies']",registry)
        self.assertIn("manager_permissions",registry)

if __name__=='__main__':
    unittest.main()
