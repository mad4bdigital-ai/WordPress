from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[3]
CODE=(ROOT/"wp-content/mu-plugins/003-mad4b-lifecycle-access.php").read_text()

class LifecycleUreBoundaries(unittest.TestCase):
    def test_legacy_roles_api_exists_but_is_readonly(self):
        self.assertIn("public static function roles():void",CODE)
        self.assertIn("self::roleSchemaStatus();",CODE)
        self.assertIn("public static function roleSchemaStatus():array",CODE)
        self.assertNotIn("->add_cap(",CODE)
        self.assertNotIn("->remove_cap(",CODE)
        self.assertNotIn("add_role(",CODE)
        self.assertNotIn("remove_role(",CODE)
        self.assertNotIn("update_option('mad4b_lifecycle_access_schema'",CODE)

    def test_no_automatic_role_migration_on_request_or_user_change(self):
        self.assertNotIn("add_action('init',[self::class,'roles']",CODE)
        self.assertIn("add_action($hook,[self::class,'wpml'],100)",CODE)
        self.assertNotIn("self::roles();self::wpml()",CODE)
        self.assertIn("VERSION='20261106.2'",CODE)

    def test_wpml_repects_effective_ure_revoke(self):
        self.assertIn("!user_can((int)$id,'translate')",CODE)
        self.assertIn("!user_can((int)$id,'manage_translations')",CODE)
        self.assertIn("$records->store((int)$id",CODE)
        self.assertNotIn("$u->add_cap(",CODE)

    def test_preserve_existing_non_role_authorization_hooks(self):
        for fragment in (
            "ure_capabilities_groups_tree",
            "ure_custom_capability_groups",
            "register_post_type_args",
            "wp_insert_post_empty_content",
            "MAD4B_Lifecycle_Access::boot()",
            "add_filter('map_meta_cap'",
            "add_filter('fluent_crm/user_permissions'",
            "add_filter($hook,[self::class,'financialMeta'],9,3)"
        ):
            self.assertIn(fragment,CODE)

if __name__=="__main__":
    unittest.main()
