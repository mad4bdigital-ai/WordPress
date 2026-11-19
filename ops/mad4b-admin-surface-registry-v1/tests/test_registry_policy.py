import json
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[3]
PHP=(ROOT/'wp-content/mu-plugins/012-mad4b-admin-surface-registry-v1.php').read_text()
CAT=Path(__file__).resolve().parents[1]/'catalog/observed-menu-surfaces-20261119.json'

class SurfaceRegistryTests(unittest.TestCase):
    def test_catalog_covers_all_observed_slugs_without_grants(self):
        data=json.loads(CAT.read_text())
        self.assertEqual(data['unique_records'],515)
        self.assertTrue(data['not_authoritative_for_permissions'])
        self.assertTrue(data['not_authorized_for_auto_grant'])
        rows=data['rows']
        self.assertEqual(sum(x['site_profile']=='tours' for x in rows),299)
        self.assertEqual(sum(x['site_profile']=='wayfa' for x in rows),216)
        self.assertEqual(len({(x['site_profile'],x['slug']) for x in rows}),515)
        self.assertTrue(all(x['read_policy']=='review_required'
                            and x['write_policy']=='review_required'
                            and x['approve_policy']=='review_required'
                            and not x['handler_verified'] for x in rows))

    def test_default_deny_read_only_contract(self):
        self.assertIn("if ($operation !== 'read' || !is_user_logged_in()) return false",PHP)
        self.assertIn("if (!$p || empty($p['read_caps'])) return false",PHP)
        self.assertIn("foreach ($p['read_caps'] as $cap) if (!current_user_can($cap)) return false",PHP)
        self.assertIn("'registry_write_gate']=false",PHP)
        self.assertIn("'registry_approve_gate']=false",PHP)
        self.assertIn("'handler_authorization_certified']=false",PHP)

    def test_never_mutates_roles_or_auth_callbacks(self):
        for banned in ('->add_cap(','->remove_cap(','add_role(','remove_role(',
                       'update_option(','add_option(','set_role(','wp_insert_user(',
                       "add_filter('map_meta_cap'","add_filter('user_has_cap'"):
            self.assertNotIn(banned,PHP)
        self.assertIn("add_action('admin_menu',[__CLASS__,'menu'],1750)",PHP)
        self.assertNotIn("add_action('init'",PHP)

    def test_five_reviewed_navigation_surfaces(self):
        for slug in ('mad4b-company-workspaces','mad4b-company-content-jobs',
                     'mad4b-company-staff-roles','mad4b-pricing-workspace',
                     'mad4b-pricing-lab'):
            self.assertIn("'"+slug+"' => [",PHP)
        self.assertIn("'scope'=>'original_content_jobs_site_uuid_brand_id_tenant_id'",PHP)
        self.assertIn("'scope'=>'staff_handler_owner_check_required'",PHP)
        self.assertIn("current_user_can('mad4b_manage_company_operations')",PHP)
        self.assertIn("current_user_can('edit_others_posts')",PHP)
        self.assertIn("does not authorize a page callback",PHP.lower())

if __name__=='__main__':
    unittest.main()
