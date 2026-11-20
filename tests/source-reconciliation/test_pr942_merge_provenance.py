import importlib.util
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('review_provenance', ROOT / 'tools/source-reconciliation/verify_pr942_merge_provenance.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def fixture_row():
    return {'path': 'file.php', 'source_920_blob': None,
            'source_882_blob': '882', 'current_candidate_blob': 'stale',
            'manual_functional_signoff': False}


def fixture_matrix(rows=None):
    rows = [fixture_row()] if rows is None else rows
    return {'rows': rows, 'conflict_count': len(rows),
            'semantic_conflict_review_completed': False, 'release_authorized': False}


def fixture_trees():
    return {name: {'file.php': blob} for name, blob in
            [('pr882', '882'), ('pr920', '920'), ('pr942', '920'), ('pr964', 'new'), ('master_20261120', 'master')]}


class ProvenanceTest(unittest.TestCase):
    def test_supplement_exposes_null_and_stale_evidence(self):
        count, rows = module.classify_rows(fixture_matrix(), fixture_trees())
        self.assertEqual(count['historic_pr920_null'], 1)
        self.assertEqual(count['historic_candidate_stale'], 1)
        self.assertEqual(count['pr920_exact'], 1)
        self.assertEqual(count['changed_pr942_to_pr964'], 1)
        self.assertFalse(rows[0]['semantic_review_completed'])

    def test_rejects_missing_tree_path(self):
        trees = fixture_trees()
        del trees['pr920']['file.php']
        with self.assertRaises(ValueError):
            module.classify_rows(fixture_matrix(), trees)

    def test_rejects_forged_original_sha_and_signoff(self):
        matrix = fixture_matrix()
        matrix['rows'][0]['source_882_blob'] = 'forged'
        with self.assertRaises(ValueError):
            module.classify_rows(matrix, fixture_trees())
        matrix = fixture_matrix()
        matrix['rows'][0]['manual_functional_signoff'] = True
        with self.assertRaises(ValueError):
            module.classify_rows(matrix, fixture_trees())

    def test_rejects_duplicate_paths_and_fake_authorization(self):
        rows = [fixture_row(), fixture_row()]
        with self.assertRaises(ValueError):
            module.classify_rows(fixture_matrix(rows), fixture_trees())
        matrix = fixture_matrix()
        matrix['release_authorized'] = True
        with self.assertRaises(ValueError):
            module.classify_rows(matrix, fixture_trees())


if __name__ == '__main__':
    unittest.main()
