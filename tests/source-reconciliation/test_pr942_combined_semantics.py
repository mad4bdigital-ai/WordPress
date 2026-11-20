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
