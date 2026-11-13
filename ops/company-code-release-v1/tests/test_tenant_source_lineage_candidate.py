"""Guard the full installed Tenant plugin file set in the source candidate."""
import hashlib
import json
from pathlib import Path
import unittest

OPS = Path(__file__).resolve().parents[2]
MANIFEST = OPS / "company-code-release-v1/catalog/tenant-sync-source-lineage-v1.json"
PLUGIN = OPS.parent / "wp-content/plugins/mad4b-tenant-sync"


class TenantSourceLineage(unittest.TestCase):
    def test_candidate_preserves_live_sources_with_exact_reviewed_content(self):
        doc=json.loads(MANIFEST.read_text())
        self.assertEqual(doc["contract"],"mad4b.tenant-source-lineage-snapshot.v1")
        self.assertTrue(doc["deployment_policy"]["must_preserve_all_live_files"])
        self.assertTrue(doc["deployment_policy"]["requires_coherent_plugin_activation"])
        self.assertTrue(doc["deployment_policy"]["requires_root_protected_provider_source"])
        self.assertFalse(doc["checks"]["trusted_source_root_provenance_ready"])
        self.assertFalse(doc["checks"]["coherent_multi_file_cutover_provider_registered"])
        self.assertFalse(doc["checks"]["production_modified_during_reconciliation"])
        declared={row["path"]:row for row in doc["files"]}
        self.assertEqual(len(declared),len(doc["files"]))
        actual={p.relative_to(PLUGIN).as_posix() for p in PLUGIN.rglob("*")
                if p.is_file()}
        self.assertEqual(actual,set(declared))
        preserved=updated=new=0
        for name,record in declared.items():
            with self.subTest(file=name):
                file=PLUGIN/name
                self.assertTrue(file.is_file() and not file.is_symlink())
                self.assertEqual(hashlib.sha256(file.read_bytes()).hexdigest(),
                                 record["candidate_sha256"])
                before=record["baseline_live_sha256"]
                state=record["change"]
                if state=="preserved_live":
                    self.assertEqual(before,record["candidate_sha256"])
                    preserved+=1
                elif state=="feature_upgrade":
                    self.assertIsInstance(before,str)
                    self.assertNotEqual(before,record["candidate_sha256"])
                    updated+=1
                elif state=="new_source":
                    self.assertIsNone(before)
                    new+=1
                else:
                    self.fail("UNKNOWN_LINEAGE_STATE:"+str(state))
        self.assertGreater(preserved,0)
        self.assertGreater(updated,0)
        self.assertGreater(new,0)


if __name__=="__main__":
    unittest.main()
