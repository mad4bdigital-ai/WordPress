from pathlib import Path
import unittest
SRC=Path(__file__).resolve().parents[1]/"company_code_release.py"
class CopyReadinessHookContract(unittest.TestCase):
    def test_apply_filter_provides_policy_to_other_providers(self):
        s=SRC.read_text()
        needle='[],["post_types"=>["elementor_library"]]);'
        self.assertIn(needle,s)
        self.assertNotIn('apply_filters("mad4b/content_readiness/external_issues",[]);',s)
    def test_two_arg_acceptance_keeps_gallery_and_media_providers(self):
        s=SRC.read_text()
        self.assertIn('copy_coverage_readiness',s)
        self.assertIn('MAD4B_CODE_RELEASE_COPY_READINESS_PASS',s)
        self.assertIn('mad4b-elementor-copy-coverage',s)
if __name__=="__main__":unittest.main()
