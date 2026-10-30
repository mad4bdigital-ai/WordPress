#!/usr/bin/env python3
"""Read-only checksum validation of the eight-source QA31 worklist."""
import hashlib
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / 'ops/qa31/rc118-source-intake'
def load(name):
    return json.loads((ROOT/name).read_text())

class Worklist(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc=load('source-reconciliation-worklist.json')
        cls.old=load('intake.json')
        cls.drift=load('runtime-drift-lineage.json')
        cls.native=load('native-ability-trust-intake.json')

    def test_inventory(self):
        d=self.doc
        self.assertEqual(d['contract'],'mad4b.qa31.rc118-source-reconciliation-worklist.v1')
        self.assertEqual((d['original_manifest_members'],d['original_manifest_exact_matches'],d['original_manifest_sha_mismatches'],d['unmanifested_runtime_dependencies']),(877,873,4,4))
        self.assertEqual((len(d['entries']),d['total_reconciliation_entries'],d['github_candidate_byte_matches'],d['unresolved_exact_github_source_candidates']),(8,8,2,6))
        self.assertEqual(len(set(x['path'] for x in d['entries'])),8)

    def test_source_integrity(self):
        for row in self.doc['entries']:
            p=ROOT/row['quarantined_candidate_path']
            self.assertTrue(p.is_relative_to(ROOT) and p.is_file() and not p.is_symlink())
            self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),row['current_staging_sha256'])
            self.assertEqual(p.stat().st_size,row['candidate_bytes'])
            self.assertEqual(len(row['required_independent_reviews']),3)

    def test_original_manifest_mismatches(self):
        rows={x['path']:x for x in self.doc['entries']}
        for old in self.drift['candidate_files']:
            r=rows[old['path']]
            self.assertEqual(r['kind'],'original_manifest_member_modified')
            self.assertEqual(r['original_manifest_sha256'],old['package_recorded_sha256'])
            self.assertEqual(r['current_staging_sha256'],old['observed_sha256'])

    def test_unmanifested_sources(self):
        rows={x['path']:x for x in self.doc['entries']}
        new=self.old['files']+[{'runtime_origin':self.native['runtime_origin'],'sha256':self.native['source_sha256']}]
        self.assertEqual(len(new),4)
        for source in new:
            row=rows[source['runtime_origin']]
            self.assertEqual(row['kind'],'unmanifested_new_runtime_dependency')
            self.assertIsNone(row['original_manifest_sha256'])
            self.assertEqual(row['current_staging_sha256'],source['sha256'])

    def test_non_authorizing_and_exact_draft_refs(self):
        d=self.doc
        for k in ('authorizing','staging_install_authorized','production_install_authorized','provider_certified','owner_release_attested','source_parity_certified','mutation_performed'):
            self.assertIs(d[k],False)
        self.assertIs(d['no_four_eyes_override_for_single_operator'],True)
        self.assertEqual(d['decision'],'BLOCKED_SOURCE_RECONCILIATION')
        found=[]
        for row in d['entries']:
            for k in ('independent_source_lineage_verified','semantic_source_approved','runtime_install_authorized','owner_release_attested','provider_certificate_issued'):
                self.assertIs(row[k],False)
            if row['candidate_github_exact_match']:
                c=row['candidate_github_exact_match']
                self.assertEqual(c['pr'],607)
                self.assertEqual(c['head_sha'],'eaf502ea8bc471ff02b290fa3d652249d07d92c1')
                self.assertEqual(c['state'],'OPEN_DRAFT_UNMERGED')
                found.append(row['path'])
        self.assertEqual(set(found),{'includes/class-mad4b-scp-adapter-registry.php','includes/class-mad4b-scp-native-ability-trust.php'})

if __name__=='__main__':
    unittest.main()
