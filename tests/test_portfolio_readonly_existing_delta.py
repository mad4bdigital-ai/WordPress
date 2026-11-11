import hashlib
import json
from pathlib import Path
import subprocess
import unittest

R=Path(__file__).resolve().parents[1]
MANIFEST=json.loads((R/'docs/release-coverage/portfolio-existing-readonly-additions-20261111.json').read_text())
class AdditiveSourceSafety(unittest.TestCase):
    def test_exact_sha_and_previous_source_lineage(self):
        self.assertEqual(MANIFEST["contract"],"mad4b.portfolio-source-additive-existing.v1")
        self.assertEqual(len(MANIFEST["files"]),2)
        for item in MANIFEST["files"]:
            with self.subTest(item=item["path"]):
                path=item["path"]
                raw=(R/path).read_bytes()
                self.assertEqual(hashlib.sha256(raw).hexdigest(),item["live_sha256"])
                p=subprocess.run(['git','-C',str(R),'cat-file','blob',MANIFEST['snapshot_base']+':'+path],
                          capture_output=True,timeout=20)
                self.assertEqual(p.returncode,0)
                self.assertEqual(hashlib.sha256(p.stdout).hexdigest(),item["preimage_sha256"])
                self.assertTrue(item['no_original_lines_removed_or_modified'])
                old=p.stdout.decode().splitlines(keepends=True)
                current=raw.decode().splitlines(keepends=True)
                iter_new=iter(current)
                self.assertTrue(all(any(x==next_value for next_value in iter_new) for x in old))
    def test_read_only_target_schema(self):
        p=R/'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-presentation-option-ownership.php'
        text=p.read_text()
        for key in ('brand.hero_headline','brand.hero_subtitle','brand.hero_cta','brand.archive_cta','brand.tour_inquiry_cta'):
            self.assertIn("'"+key+"'",text)
        self.assertIn("'target_readback_verified'=>false",text)
        self.assertIn("'ready_to_apply'=>false",text)
    def test_missing_native_receipt_is_not_success(self):
        p=R/'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-start-reconcile-completeness.php'
        text=p.read_text()
        self.assertIn("target_state'=>'UNVERIFIED'",text)
        self.assertIn("'auto_apply'=>false",text)
        self.assertIn("'host_topology_full_deployment_verified'=>false",text)
        self.assertIn("'all_required_node_receipts_verified'=>false",text)
if __name__=='__main__':unittest.main()
