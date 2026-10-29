#!/usr/bin/env python3
"""Exact-source static security gates for quarantined RC118 candidates.

This deliberately does NOT execute any PHP, register hooks, alter tenant data,
approve source, issue credentials, or certify a new runtime package.
"""
import hashlib
import json
import re
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
INBOX=ROOT/'ops/qa31/rc118-source-intake'
ACTIVE=ROOT/'wp-content/plugins/mad4b-site-control-plane'
NAME='source/class-mad4b-scp-native-ability-trust.php.txt'
EXPECTED_SHA='bf76bcdbcd240716f8276a9c764a103250141223a8882839a8fd5c4186c3ee49'

def source(name):
    p=INBOX/'source'/name
    if p.is_symlink() or not p.is_file():
        raise AssertionError(f'SOURCE_CANDIDATE_MISSING: {name}')
    return p.read_text()

def guards(src, tokens):
    """Pure contract probe; caller can mutate in-memory source to test failures."""
    return tuple(token for token in tokens if token not in src)

class NativeAbilityQuarantine(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.meta=json.loads((INBOX/'native-ability-trust-intake.json').read_text())
        cls.body=source('class-mad4b-scp-native-ability-trust.php.txt')
        cls.tenant=source('class-mad4b-scp-company-tenant-migration.php')
        cls.decisions=source('class-mad4b-scp-company-migration-decisions.php')
        cls.catalog=source('class-mad4b-scp-migration-decision-catalog.php')

    def test_exact_new_source_and_release_denials(self):
        d=self.meta
        p=INBOX/NAME
        self.assertEqual(d['contract'],'mad4b.qa31.rc118-native-ability-trust-intake.v1')
        self.assertEqual(d['candidate_path'],NAME)
        self.assertEqual(d['runtime_origin'],'includes/class-mad4b-scp-native-ability-trust.php')
        self.assertEqual(d['source_sha256'],EXPECTED_SHA)
        self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),EXPECTED_SHA)
        self.assertEqual(d['bytes'],p.stat().st_size)
        for f in ('authorizing','staging_install_authorized','production_install_authorized',
                  'provider_certified','staging_mutation_performed','semantic_review_completed'):
            self.assertIs(d[f],False)
        for f in ('absent_from_original_877_member_manifest',
                  'not_present_in_reviewed_github_runtime',
                  'source_candidate_only','non_executable_source_intake',
                  'independent_source_review_required'):
            self.assertIs(d[f],True)
        self.assertEqual(d['release_status'],'BLOCKED_SOURCE_RECONCILIATION')
        self.assertFalse((ACTIVE/'includes/class-mad4b-scp-native-ability-trust.php').exists())

    def test_native_manifest_is_external_exact_source_read_only(self):
        required=(
            'const CONTRACT = \'mad4b.native-ability-trust.v1\'',
            'MAD4B_SCP_NATIVE_ABILITY_MANIFEST_PATH',
            'MAD4B_SCP_NATIVE_ABILITY_MANIFEST_SHA256',
            'is_link($path)',
            'filesize($real)>65536',
            "hash_equals($expected,$actual)",
            "'scope']??null)!=='read'",
            "count($data['abilities'])>self::MAX_ENTRIES",
            "'site_host']??null)!==(string)wp_parse_url(home_url('/')",
            'self::safe_path($row[\'source_relpath\']??null)',
            "hash_file('sha256',$source)!==$row['source_sha256']",
            "hash_equals($row['input_schema_sha256'],$schema_hash)",
        )
        self.assertEqual(guards(self.body,required),())

    def test_native_catalog_discovery_cannot_grant_execution(self):
        required=(
            "if (!$is_read) $reason='not_a_private_readonly_ability';",
            "&& empty($meta['annotations']['destructive'])",
            "&& empty($meta['show_in_rest'])",
            "&& (($meta['mcp']['surface']??null)==='read')",
            "'execution_authorized_by_catalog'=>false",
            "'write_authority_created'=>false",
            "'mutation_performed'=>false",
            "MAD4B_SCP_Native_Ability_Trust",
        )
        self.assertEqual(guards(self.body,required),())
        self.assertNotIn('->execute(',self.body)
        self.assertNotIn('wp_execute_ability(',self.body)
        self.assertNotIn("register_rest_route(",self.body)

    def test_migration_role_change_is_owner_and_digest_gated(self):
        required=(
            "current_user_can('manage_options')&&current_user_can('activate_plugins')",
            "MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized()",
            "!hash_equals(self::CONFIRM,(string)$request->get_param('confirmation'))",
            "!hash_equals($plan['expected_digest'],(string)$request->get_param('expected_digest'))",
            "SELECT GET_LOCK(%s, 5)",
            "SELECT RELEASE_LOCK(%s)",
            "'direct_user_system_grants_require_manual_review'",
            "update_option(self::RECEIPT,$receipt,false)",
            "update_option($wpdb->prefix.'user_roles',$rolesBefore,false)",
            "MAD4B_SCP_Audit::record('mad4b/company-tenant-migration-result'",
        )
        self.assertEqual(guards(self.tenant,required),())

    def test_migration_decisions_require_separation_and_staging(self):
        required=(
            "wp_get_environment_type()!=='staging'",
            "WP_ENVIRONMENT_TYPE!=='staging'",
            "MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized()",
            "if((int)$r['proposed_by']===get_current_user_id())",
            "if(!hash_equals(self::APPLY,(string)$confirmation))",
            "if(!hash_equals(self::REVERT,(string)$confirmation))",
            "if(!self::matches_source($r))",
            "'execution_scope'=>'decision_registry_only'",
            "'external_asset_changed'=>false",
            "$wpdb->query('ROLLBACK')",
        )
        self.assertEqual(guards(self.decisions,required),())

    def test_legal_pages_and_provider_mutations_are_not_auto_executed(self):
        self.assertIn('requires_company_specific_review',self.catalog)
        self.assertIn('public static function legal_page($id)',self.catalog)
        self.assertIn("MAD4B_SCP_Migration_Decision_Catalog::legal_page((int)$matches[1])",self.decisions)
        self.assertIn("return self::err('mad4b_decision_legal_page_protected'",self.decisions)
        self.assertIn("'provider_mutation'=>false",self.decisions)
        self.assertNotIn('register_rest_route(',self.catalog)

    def test_guard_removal_is_detected_without_executing_source(self):
        for fragment in (
            "'execution_authorized_by_catalog'=>false",
            "'scope']??null)!=='read'",
            "hash_equals($expected,$actual)",
        ):
            mutated=self.body.replace(fragment,'/* guard removed */')
            self.assertEqual(guards(mutated,(fragment,)),(fragment,))
        fragment="if((int)$r['proposed_by']===get_current_user_id())"
        self.assertEqual(guards(self.decisions.replace(fragment,'/* absent */'),(fragment,)),(fragment,))

if __name__=='__main__':
    unittest.main()
