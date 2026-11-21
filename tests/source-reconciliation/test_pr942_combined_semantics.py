import copy
import importlib.util
import json
from pathlib import Path
from unittest import TestCase, main
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
p = ROOT / 'tests/source-reconciliation/pr942_combined_semantic_contract.py'
spec = importlib.util.spec_from_file_location('semantic_contract', p)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class CompositeReviewNegativeTests(TestCase):
    def setUp(self):
        self.supplement = json.loads(m.SUPPLEMENT.read_text())
        self.sources = {p: (ROOT / p).read_text() for p in [
            *m.REVIEW,
            'wp-content/plugins/mad4b-site-control-plane/includes/class-external-skill-sources.php',
            'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-local-ollama-ability.php',
            *(path for path, _sha in m.CP4_EXACT_SOURCE_REVIEW),
        ]}
        self.source = lambda p: self.sources[p]

    def test_current_head_is_strictly_non_authorizing(self):
        rows = m.validate(self.supplement, self.source)
        self.assertEqual(len(rows), 11)
        self.assertFalse(m.report_rows(rows)['release_authorized'])
        self.assertFalse(m.report_rows(rows)['review_completed'])

    def test_fails_on_forged_release_approval(self):
        bad = copy.deepcopy(self.supplement)
        bad['release_authorized'] = True
        with self.assertRaises(AssertionError):
            m.validate(bad, self.source)

    def test_governance_successor_cannot_approve_production(self):
        key = m.GOVERNANCE_PATH
        ledger = json.loads(self.sources[key])
        ledger['authorizing'] = True
        self.sources[key] = json.dumps(ledger)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)

    def test_governance_successor_cannot_lose_original_artifact_fixture(self):
        key = m.GOVERNANCE_PATH
        ledger = json.loads(self.sources[key])
        for row in ledger['reviewed_changes']:
            if row.get('source_blob_sha') == '60750fb4a0cf8180f012254acd3fc2ccc43bba78':
                row['regression_tests'] = []
        self.sources[key] = json.dumps(ledger)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)

    def test_cwv_bootstrap_successor_requires_gated_include(self):
        key = m.P + 'mad4b-site-control-plane.php'
        self.sources[key] = self.sources[key].replace(
            'if ( MAD4B_SCP_CWV_Site_Admission::admitted() ) {',
            'if ( true ) {', 1)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)

    def test_cwv_governance_source_requires_exact_review_rows(self):
        key = m.GOVERNANCE_PATH
        doc = json.loads(self.sources[key])
        doc['reviewed_changes'] = [r for r in doc['reviewed_changes']
            if r.get('source_blob_sha') != m.CWV_REVIEWED_HELPER]
        self.sources[key] = json.dumps(doc)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)

    def test_cwv_runner_successor_denies_unknown_executable(self):
        path='tools/readiness_test_execution.py'
        self.sources[path]=self.sources[path].replace(
            '"wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php",',
            '"wp-content/plugins/mad4b-site-control-plane/tests/unreviewed-php-runner.php",',1)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement,self.source)

    def test_cwv_runner_successor_requires_real_negative_test(self):
        path='tools/readiness_test_execution.py'
        self.sources[path]=self.sources[path].replace(
            'assert not has_assertion_pass("FAIL: CWV_SITE_SCOPED_ADMISSION unauthorized policy',
            'assert has_assertion_pass("FAIL: CWV_SITE_SCOPED_ADMISSION unauthorized policy',1)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement,self.source)

    def test_brand_trait_merged_governance_cannot_lose_service(self):
        key=m.GOVERNANCE_PATH
        x=json.loads(self.sources[key])
        x['reviewed_capabilities']=[r for r in x['reviewed_capabilities']
            if r.get('id')!='service:'+m.P+'includes/trait-mad4b-scp-brand-integrity-term-meta.php']
        self.sources[key]=json.dumps(x)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement,self.source)

    def test_brand_trait_merged_governance_requires_exact_facade_review(self):
        key=m.GOVERNANCE_PATH
        x=json.loads(self.sources[key])
        x['reviewed_changes']=[r for r in x['reviewed_changes']
            if r.get('source_blob_sha')!=m.BRAND_TRAIT_REVIEWED_FACADE]
        self.sources[key]=json.dumps(x)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement,self.source)

    def test_brand_trait_merged_governance_never_authorizes_production(self):
        key=m.GOVERNANCE_PATH
        x=json.loads(self.sources[key]);x['production_promotion_from_ci']=True
        self.sources[key]=json.dumps(x)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement,self.source)

    def test_cp4_native_scanning_review_cannot_disappear(self):
        key=m.GOVERNANCE_PATH
        x=json.loads(self.sources[key])
        sha=m.CP4_EXACT_SOURCE_REVIEW[0][1]
        x['reviewed_changes']=[r for r in x['reviewed_changes']
                              if r.get('source_blob_sha')!=sha]
        self.sources[key]=json.dumps(x)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement,self.source)

    def test_cp4_original_reconciliation_cannot_gain_release_authority(self):
        key=m.GOVERNANCE_PATH
        x=json.loads(self.sources[key])
        sha=m.CP4_EXACT_SOURCE_REVIEW[1][1]
        for r in x['reviewed_changes']:
            if r.get('source_blob_sha')==sha:
                r['production_authorized']=True
        self.sources[key]=json.dumps(x)
        with self.assertRaises(AssertionError):
            m.validate(self.supplement,self.source)

    def test_cp4_exact_source_blob_mismatch_denied(self):
        path=m.CP4_EXACT_SOURCE_REVIEW[0][0]
        original=m.current_blob
        with patch.object(m,'current_blob',
                          lambda value: '0'*40 if value==path else original(value)):
            with self.assertRaises(AssertionError):
                m.validate(self.supplement,self.source)

    def test_fails_on_collision_sha_change(self):
        with patch.object(m, 'current_blob', lambda _p: '0' * 40):
            with self.assertRaises(AssertionError):
                m.validate(self.supplement, self.source)

    def test_fails_on_early_post_type_lookup(self):
        k = m.P + 'includes/class-mad4b-scp-provider-field-intelligence.php'
        start, rest = self.sources[k].split('public static function discover($input=[])', 1)
        rest = rest.replace('        $site=class_exists(',
                            '        $leak=post_type_exists($subtype);\n        $site=class_exists(', 1)
        self.sources[k] = start + 'public static function discover($input=[])' + rest
        with self.assertRaises((AssertionError, ValueError)):
            m.validate(self.supplement, self.source)

    def test_fails_on_implicit_tour_discovery(self):
        k = m.P + 'includes/class-mad4b-scp-provider-field-intelligence.php'
        self.sources[k] = self.sources[k].replace("$input['subtype']??'post'", "$input['subtype']??'tours-and-activities'")
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)

    def test_fails_on_missing_skill_opt_in(self):
        k = m.P + 'includes/class-mad4b-scp-skill-registry.php'
        self.sources[k] = self.sources[k].replace('&& true === MAD4B_SCP_Site_Profile::skills_enabled();', ';')
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)

    def test_fails_on_skills_source_nonce_bypass(self):
        k = m.P + 'includes/class-external-skill-sources.php'
        self.sources[k] = self.sources[k].replace("check_admin_referer( 'external_skill_sources', 'external_skill_sources_nonce' );", '')
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)

    def test_fails_on_ollama_autoload(self):
        k = m.P + 'mad4b-site-control-plane.php'
        self.sources[k] += "\\nrequire_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-ollama-ability.php';\\n"
        with self.assertRaises(AssertionError):
            m.validate(self.supplement, self.source)


if __name__ == '__main__':
    main()
