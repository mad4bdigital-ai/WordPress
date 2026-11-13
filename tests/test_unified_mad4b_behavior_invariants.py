"""One-source semantic regression guards: source proof, never live authority."""
from pathlib import Path
import hashlib
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTROL = ROOT / 'wp-content/plugins/mad4b-site-control-plane'
RELEASE = ROOT / 'ops/company-code-release-v1'

def code(path):
    return (ROOT / path).read_text()

class UnifiedSourceNonRegression(unittest.TestCase):
    def test_manifest_and_seeder_never_revert_to_old_skill_version(self):
        manifest = json.loads((CONTROL / 'config/skill-seed-manifest.json').read_text())
        seeder = (CONTROL / 'includes/class-mad4b-scp-skill-seeder.php').read_text()
        version = int(manifest['seed_version'])
        self.assertGreaterEqual(version, 30, 'merged older branch replaced v30')
        self.assertIn('const SEED_VERSION = ' + str(version) + ';', seeder)
        names = [x['name'] for x in manifest['skills']]
        self.assertEqual(len(names),len(set(names)))
        self.assertGreaterEqual(len(names),21)
        for name in names:
            with self.subTest(skill=name):
                a=CONTROL/'skill-seeds'/name/'SKILL.md'
                b=ROOT/'plugins/mad4b-wordpress/skills'/name/'SKILL.md'
                self.assertTrue(a.is_file() and b.is_file())
                self.assertFalse(a.is_symlink() or b.is_symlink())
                self.assertEqual(a.read_bytes(),b.read_bytes())
        self.assertIn('self::exact_enrolled_site()',seeder)
        self.assertIn('MAD4B_SCP_Site_Profile::origin_enrolled()',seeder)

    def test_operator_grant_policy_is_dynamic_and_historical_plan_kept(self):
        op=(CONTROL/'includes/class-mad4b-scp-operator-grant-convergence.php').read_text()
        sidecar=(CONTROL/'includes/class-mad4b-scp-developer-authority-policy-v2.php').read_text()
        self.assertNotIn("nonproduction_governed( 'write' )",op)
        self.assertIn('Registered_Write_Admission::preflight( self::ABILITY )',op)
        self.assertIn('Developer_Authority_Policy_V2::project(',op)
        self.assertIn("'authorization_issued' => false",sidecar)
        self.assertIn("'grant_issued' => false",sidecar)
        self.assertIn("'canonical_plan_modified' => false",sidecar)
        descriptor=json.loads((CONTROL/'config/operation-registry.json').read_text())
        matches=[x for x in descriptor['operations']
                 if x.get('planner')=='mad4b/operator-grants-converge']
        self.assertEqual(len(matches),1)
        self.assertEqual(matches[0]['write_admission']['policy_effect'],'preflight_only')

    def test_elementor_native_display_owner_is_not_replaced(self):
        adapter=(CONTROL/'includes/adapters/class-mad4b-scp-elementor-adapter.php').read_text()
        parity=(CONTROL/'includes/class-mad4b-scp-elementor-native-template-parity.php').read_text()
        self.assertIn('MAD4B_SCP_Elementor_Native_Template_Parity',adapter)
        for marker in ('JetEngine','is_condition_key', 'condition_ancestors',
                       'mad4b_elementor_native_parity_owner_removed',
                       'mad4b_elementor_native_parity_parallel_surface'):
            self.assertIn(marker,parity)
        self.assertIn('native-template-parity',
            ' '.join(p.name for p in (CONTROL/'includes').glob('class-mad4b-scp-elementor-native-template-parity.php')))

    def test_company_release_cas_and_native_assets_both_survive(self):
        src=(RELEASE/'company_code_release.py').read_text()
        for marker in ('CAPABILITY_OWNER_STATE_DRIFT',
                       'PHP_LITERAL_DEPENDENCY_STATE_DRIFT',
                       'TENANT_PLUGIN_PROFILE_STATE_DRIFT',
                       'registered_component_files(',
                       'RELEASE_COMPONENT_NO_ROOT_PHP_LOADER',
                       'RELEASE_COMPONENT_SCOPE_INVALID',
                       'SOURCE_COMMIT_DRIFT'):
            self.assertIn(marker,src)
        self.assertIn('catalog_files.add(target)',src)
        catalog=json.loads((RELEASE/'catalog/mad4b-release-components.json').read_text())
        self.assertEqual(catalog['contract'],'mad4b.company-release-components.v1')
        components={x['id']:x for x in catalog['components']}
        self.assertEqual(len(components),len(catalog['components']))
        for name in ('mad4b-composite-media','mad4b-uael-tour-gallery-tabs',
                     'mad4b-extra-quick-preview-native-gallery-interop',
                     'mad4b-native-reviews-help-preview-visibility'):
            self.assertIn(name,components)
        assets=components['mad4b-extra-quick-preview-native-gallery-interop']['files']
        self.assertTrue(any(x.endswith('.js') for x in assets))
        self.assertTrue(any(x.endswith('.css') for x in assets))
        self.assertTrue(any(x.endswith('.php') for x in assets))

    def test_company_product_eligibility_reuses_native_filters(self):
        s=code('packages/mad4b-shared-product-core/mu-plugins/shared-product-core/adapters/company-term-eligibility-v1.php')
        for marker in ('public static function catalog_sources()',
                       'product_navigation_registry', 'taxonomy_is_product_attribute',
                       'jet-ajax-search'):
            self.assertIn(marker,s)
        self.assertIn('company-term-eligibility-v1.php',
            code('packages/mad4b-shared-product-core/mu-plugins/shared-product-core/adapters/presentation-native-filter-policy-v1.php'))

    def test_review_evidence_is_non_authorizing(self):
        ledger=json.loads(code('governance/production-readiness/capability-coverage.json'))
        self.assertFalse(ledger['authorizing'])
        self.assertFalse(ledger['production_promotion_from_ci'])
        cls='service:wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-elementor-native-template-parity.php'
        self.assertIn(cls,{x['id'] for x in ledger['reviewed_capabilities']})
        for path in ('wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json',
                     'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skill-seeder.php'):
            git_sha=hashlib.sha1(b'blob '+str((ROOT/path).stat().st_size).encode()+b'\0'+(ROOT/path).read_bytes()).hexdigest()
            self.assertIn((path,git_sha),{
                (x['path'],x['source_blob_sha']) for x in ledger['reviewed_changes']})

if __name__=='__main__': unittest.main()
