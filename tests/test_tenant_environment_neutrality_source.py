"""Static guardrails: no environment-as-authorization after tenant migration.

This suite never fabricates a grant, starts WordPress, or accesses production.
"""
from pathlib import Path
import re
import unittest

SOURCE=Path(__file__).resolve().parents[1]/'wp-content/plugins/mad4b-tenant-sync'
INCLUDES=SOURCE/'includes'

class TenantEnvironmentNeutralityTests(unittest.TestCase):
    def test_all_files_are_php_with_no_staging_only_runtime_branch(self):
        php=list(INCLUDES.glob('*.php'))+[SOURCE/'mad4b-tenant-sync.php']
        self.assertGreaterEqual(len(php),12)
        banned=(r'wp_get_environment_type\s*\(\s*\)\s*!==?\s*[\'\"]staging',
                r'wp_get_environment_type\s*\(\s*\)\s*===?\s*[\'\"]staging',
                r'in_array\s*\(\s*wp_get_environment_type\s*\(\s*\)\s*,\s*\[[^]]*staging[^]]*\]')
        for f in php:
            src=f.read_text()
            self.assertTrue(src.lstrip().startswith('<?php'),f.name)
            for pattern in banned:
                self.assertIsNone(re.search(pattern,src),f'{f.name} has forbidden Staging gating')

    def test_exact_site_binding_remains_required(self):
        src=(INCLUDES/'class-mad4b-tenant-operation-authority.php').read_text()
        for condition in ('same_origin_clone_protection','deployment_binding_match',
                          'origin_match','environment_match','mutation_pending_audit',
                          'MAD4B_SCP_Audit::storage_status()'):
            self.assertIn(condition,src)
        self.assertNotIn("['staging','local']",src)
        self.assertNotIn("['local','staging']",src)

    def test_schema_install_is_never_authorized_by_environment_label(self):
        pairs={
          'class-mad4b-tenant-sparse-store.php':"schema('tenant.sparse.schema'",
          'class-mad4b-tenant-workflow.php':"schema('tenant.workflow.schema'",
          'class-mad4b-tenant-company-media.php':"schema(\n            'tenant.media.schema'"
        }
        for file,condition in pairs.items():
            self.assertIn(condition,(INCLUDES/file).read_text())
        policy=(INCLUDES/'class-mad4b-tenant-operation-authority.php').read_text()
        self.assertIn('MAD4B_Original_Tenant_Schema_Migration_Authority_V1::verify_exact',policy)
        self.assertIn('original_signed_312_verified',policy)
        self.assertIn("'tenant.sparse.schema'=>[",policy)
        self.assertIn("'tenant.workflow.schema'=>[",policy)
        self.assertIn("'tenant.media.schema'=>[",policy)
        self.assertIn('if(!isset($allowed[$operation]) || $tables!==$allowed[$operation])return false;',policy)

    def test_qa_requires_independent_sandbox_proof(self):
        policy=(INCLUDES/'class-mad4b-tenant-operation-authority.php').read_text()
        shadow=(INCLUDES/'class-mad4b-tenant-shadow.php').read_text()
        signed=(INCLUDES/'class-mad4b-tenant-signed-publication.php').read_text()
        self.assertIn('MAD4B_Original_Tenant_QA_Sandbox_Authority_V1::verify_exact',policy)
        self.assertIn('ephemeral_cleanup_verified',policy)
        self.assertIn('MAD4B_Tenant_Operation_Authority::qa_draft()',shadow)
        self.assertIn('MAD4B_Tenant_Operation_Authority::qa_draft()',signed)

    def test_read_only_previews_require_admin_and_site_scope(self):
        for file in ('class-mad4b-tenant-rejoin-preview.php',
                     'class-mad4b-tenant-handover-inventory.php'):
            text=(INCLUDES/file).read_text()
            self.assertIn('MAD4B_Tenant_Operation_Authority::admin_read()',text)
            self.assertIn('MAD4B_Tenant_Operation_Authority::scope(false)',text)
            self.assertIn("'can_apply'=>false" if 'rejoin' in file else "'detach_authorized'=>false",text)

    def test_no_hardcoded_demo_site_identity_in_export(self):
        source=(INCLUDES/'class-mad4b-tenant-typed-schema.php').read_text()
        self.assertNotIn('e5e64bca-ed62-48d2-b2b3-dff693cd702a',source)
        self.assertNotIn('https://tours.dev.mad4b.com',source)
        self.assertIn("$s['site_uuid']",source)
        self.assertIn('deployment_binding_match',source)

    def test_plugin_bootstrap_loads_guard_before_other_classes(self):
        src=(SOURCE/'mad4b-tenant-sync.php').read_text()
        self.assertLess(src.find('class-mad4b-tenant-operation-authority.php'),
                        src.find('class-mad4b-tenant-sparse-store.php'))

if __name__=='__main__':unittest.main()
