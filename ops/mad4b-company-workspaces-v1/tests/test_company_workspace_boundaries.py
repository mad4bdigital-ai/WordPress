from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
MOD = ROOT / "wp-content/plugins/mad4b-jetengine/modules/functional-modes"
SCP = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"
MU = ROOT / "wp-content/mu-plugins"
NAMES = [
    "class-pricing-workspace.php",
    "class-pricing-lab.php",
    "class-pricing-controls-access.php",
    "class-hybrid-pricing-policies.php",
    "class-currency-configuration.php",
    "class-contextual-help-registry.php",
    "class-provider-price-sources.php",
]

class WorkspacesContracts(unittest.TestCase):
    def test_scoped_pricing_preserves_admin_and_company_gates(self):
        for name in NAMES:
            with self.subTest(name=name):
                src=(MOD/name).read_text()
                self.assertIn("current_user_can('manage_woocommerce')",src)
                self.assertIn("current_user_can('mad4b_manage_company_operations')",src)
                self.assertIn("current_user_can('mad4b_work_company_tasks')",src)
                self.assertEqual(src.count("current_user_can('manage_woocommerce')"), 1)

    def test_master_staff_legacy_route_does_not_delegate_higher_role(self):
        src=(SCP/"class-mad4b-scp-company-master-admin.php").read_text()
        self.assertIn("public static function roleWithinCaller(string $role):bool",src)
        self.assertIn("if($role==='operator')return false",src)
        self.assertIn("!current_user_can($cap)",src)
        self.assertIn("if(!self::roleWithinCaller($role))",src)
        self.assertIn("foreach(self::ALLOWED_ROLES as$role)if(self::roleWithinCaller($role))",src)

    def test_workspaces_preserves_job_tenant_scope_and_parent_fallback(self):
        src=(MU/"009-mad4b-company-workspaces-v1.php").read_text()
        self.assertIn("site_uuid_brand_id_tenant_id",src)
        self.assertIn("MAD4B_SCP_Content_Jobs::list_jobs",src)
        self.assertIn("public static function menu_parent()",src)
        self.assertIn("add_menu_page('MAD4B Workspaces','MAD4B'",src)
        self.assertIn("add_submenu_page($parent, 'Content Jobs'",src)
        self.assertNotIn("->add_cap('manage_options'",src)

    def test_staff_permissions_are_subset_denied_system_and_post_nonce(self):
        src=(MU/"010-mad4b-company-scoped-staff-v1.php").read_text()
        for token in [
            "current_user_can('mad4b_manage_company_operations')",
            "current_user_can('mad4b_work_company_tasks')",
            "user_can($id,'mad4b_manage_company_operations')",
            "in_array($cap,self::SYSTEM_CAPS,true)",
            "!user_can($id,$cap)",
            "check_admin_referer('mad4b_scoped_staff')",
            "if ($id<=0 || $id===get_current_user_id())",
            "update_user_meta((int)$id,self::OWNER_META,get_current_user_id())",
            "wp_generate_password(40,true,true)",
        ]:
            self.assertIn(token,src)
        for role in ["administrator","master_admin","shop_manager","operator"]:
            self.assertIn(role,src)
        self.assertIn("'manage_links'",src)
        self.assertNotIn("add_cap('promote_users'",src)

if __name__=="__main__":
    unittest.main()
