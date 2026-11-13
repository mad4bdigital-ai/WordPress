"""Readiness overlay combines live Media Origin/Presentation with Copy Coverage.

Source-only test: never inspects or edits user Production database/files.
"""
import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
SOURCE=ROOT/'overlays/wayfatours/content-readiness-validator.php'
COPY=ROOT/'overlays/wayfatours/mad4b-elementor-copy-readiness-producer.php'

class WayfaOverlayCoverageReconcile(unittest.TestCase):
    def test_source_has_all_required_integrity_providers(self):
        x=SOURCE.read_text()
        self.assertIn("MAD4B_Media_Origin_Integrity_V1",x)
        self.assertIn("MAD4B_SCP_Presentation_Integrity_V1::discover()",x)
        self.assertIn("MAD4B_SCP_Brand_Experience_Quality::discover(",x)
        self.assertIn("mad4b-media-origin-integrity",x)
        self.assertIn("mad4b-elementor-copy-coverage",x)
        self.assertIn("$faqGap||$mediaOrigin||$elementorCopy)",x)
        self.assertEqual(x.count("$elementorCopy=$source"),1)
        self.assertEqual(x.count("if($elementorCopy)$issues[count($issues)-1]['priority']"),1)
        self.assertIn("media_replacement_authorized",x)
        self.assertIn("source_evidence",x)
        self.assertIn("publication_authorized",x)

    def test_copy_producer_keeps_separate_read_only_scope(self):
        x=COPY.read_text()
        self.assertIn("mad4b-elementor-copy-coverage",x)
        self.assertNotIn("wp_set_current_user",x)
        self.assertNotIn("delete_post",x)
        self.assertNotIn("wp_insert_post",x)

if __name__=="__main__":
    unittest.main()
