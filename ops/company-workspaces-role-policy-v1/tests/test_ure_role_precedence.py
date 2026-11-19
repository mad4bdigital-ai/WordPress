from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
MU = ROOT / "wp-content/mu-plugins/009-mad4b-company-workspaces-v1.php"
ADOPTION = ROOT / "ops/company-workspaces-role-policy-v1/adopt-existing.php"

class UreRolePrecedence(unittest.TestCase):
    def test_workspaces_has_no_automatic_role_grants(self):
        code = MU.read_text()
        self.assertIn("ROLE_POLICY_VERSION = '20261119.1'", code)
        self.assertIn("add_action('admin_menu', [__CLASS__, 'menu'], 150)", code)
        self.assertNotIn("add_action('init'", code)
        self.assertNotIn("reconcile_scoped_caps", code)
        self.assertNotIn("add_cap(", code)
        self.assertNotIn("update_option(", code)
        self.assertNotIn("remove_cap(", code)

    def test_role_adoption_is_explicit_and_does_not_write_role_caps(self):
        code = ADOPTION.read_text()
        for fragment in [
            "MAD4B_ROLE_POLICY_ADOPT",
            "MAD4B_ROLE_POLICY_EXPECTED_MASTER_SHA256",
            "MAD4B_ROLE_POLICY_EXPECTED_ROLES_SHA256",
            "ROLE_PREIMAGE_CHANGED_CAS_REFUSED",
            "AUTHORIZED_ROLE_EDITOR_REQUIRED",
            "current_user_can('ure_manage_options')",
            "current_user_can('manage_options')",
            "VERSION_ALREADY_ADOPTED_NO_OVERWRITE",
            "REVIEW_MANUAL_ROLE_CHANGES_BEFORE_ADOPTING",
            "add_option(MAD4B_COMPANY_ROLE_SEED_OPTION, $receipt",
            "capability_write_performed' => false",
        ]:
            self.assertIn(fragment,code)
        for unsafe in ["->add_cap(", "->remove_cap(", "remove_role(", "add_role(", "update_option(", "wp_update_user("]:
            self.assertNotIn(unsafe, code)

    def test_no_master_role_editor_grant(self):
        for p in [MU, ADOPTION]:
            code = p.read_text()
            self.assertNotIn("add_cap('ure_edit_roles'", code)
            self.assertNotIn("add_cap('ure_manage_options'", code)

    def test_old_menu_still_registered_and_access_checks_preserved(self):
        code=MU.read_text()
        self.assertIn("MAD4B_Company_Workspaces_V1::boot()", code)
        self.assertIn("public static function menu(): void", code)
        self.assertIn("public static function jobs(): void",code)
        self.assertIn("current_user_can(self::OPERATIONS_CAP) && current_user_can('edit_others_posts')",code)
        self.assertIn("site_uuid_brand_id_tenant_id",code)

if __name__ == "__main__":
    unittest.main()
