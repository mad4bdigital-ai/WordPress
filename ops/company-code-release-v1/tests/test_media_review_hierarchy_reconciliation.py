"""Future source overlay must preserve the newer live media-review hierarchy.

Only validates source; it never mutates Production or historical Job/Plan.
"""
from pathlib import Path
import unittest
SRC=Path(__file__).resolve().parents[1]/'overlays/wayfatours/content-readiness-validator.php'
class HierarchicalMediaReviewRegression(unittest.TestCase):
    def test_source_uses_central_company_effective_media_policy(self):
        source=SRC.read_text()
        self.assertIn("MAD4B_Media_Review_Hierarchy_V1::effective()",source)
        self.assertIn("$policy['policy_ready']",source)
        self.assertIn("MAD4B_SCP_Context_Authority::review_policy()",source)
        self.assertIn("$policy['mode']",source)
        self.assertIn("$elementorCopy",source)
        self.assertIn("MAD4B_SCP_Presentation_Integrity_V1::discover()",source)
        self.assertIn("MAD4B_Media_Origin_Integrity_V1",source)
    def test_historical_release_not_auto_updated(self):
        self.assertNotIn('ORIGINAL_313_RECEIPT',SRC.read_text())
if __name__=='__main__': unittest.main()
