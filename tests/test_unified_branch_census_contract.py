"""Offline branch-census contract; no remote, site or live branch mutation."""
import importlib.util
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
S = importlib.util.spec_from_file_location(
    'mad4b_readonly_branch_census', ROOT/'tools/unified_branch_census.py')
module = importlib.util.module_from_spec(S)
S.loader.exec_module(module)

class BranchCensusContract(unittest.TestCase):
    def shell(self, root, *args):
        return subprocess.run(['git', '-C', str(root), *args], capture_output=True,
                              text=True, check=True).stdout.strip()

    def test_divergent_branch_requires_review_even_if_main_can_merge(self):
        with TemporaryDirectory(prefix='mad4b-branch-census-fixture-') as tmp:
            repo=Path(tmp)
            subprocess.run(['git','init','-q','-b','master',str(repo)],check=True)
            self.shell(repo,'config','user.email','fixture@example.invalid')
            self.shell(repo,'config','user.name','Fixture')
            (repo/'demo.txt').write_text('baseline\n')
            self.shell(repo,'add','.')
            self.shell(repo,'commit','-q','-m','baseline')
            self.shell(repo,'branch','contained')
            self.shell(repo,'switch','-q','-c','divergent')
            (repo/'demo.txt').write_text('independent newer behavior\n')
            self.shell(repo,'commit','-q','-am','independent fix')
            self.shell(repo,'switch','-q','master')
            (repo/'another.txt').write_text('main behavior\n')
            self.shell(repo,'add','.')
            self.shell(repo,'commit','-q','-m','newer main')
            data=module.build(repo)
            self.assertFalse(data['auto_merge_authorized'])
            self.assertFalse(data['auto_delete_authorized'])
            self.assertFalse(data['signed_release_authorized'])
            self.assertFalse(data['source_coverage_proven_by_this_census'])
            self.assertEqual(data['counts']['divergent_review_required'],1)
            self.assertEqual(data['counts']['contained_ancestors'],1)
            branch=data['branches'][0]
            self.assertEqual(branch['branch'],'divergent')
            self.assertEqual(branch['tip_paths_differ'],['demo.txt'])
            self.assertTrue(branch['whole_branch_semantic_parity_unverified'])
            self.assertEqual(self.shell(repo,'branch','--list','divergent'),'divergent')

if __name__ == '__main__': unittest.main()
