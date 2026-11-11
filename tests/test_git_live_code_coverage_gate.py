import tempfile,unittest
from pathlib import Path
import importlib.util
ROOT=Path(__file__).resolve().parents[1]
module=ROOT/'tools/audit_git_live_code_coverage.py'
spec=importlib.util.spec_from_file_location('coverage_gate',module)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Gate(unittest.TestCase):
    def test_live_commerce_is_in_registered_audit_scope(self):
        self.assertIn(('mad4b-commerce','wp-content/plugins/mad4b-commerce'),m.COMPONENTS)
        self.assertGreaterEqual(len(m.COMPONENTS),8)
    def case(self,live=True,candidate=True,content=b'foo\n'):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root=Path(self.tmp.name)
        src=root/'src';site=root/'site'
        src.mkdir();site.mkdir()
        rel=Path('wp-content/plugins/example/plugin.php')
        if candidate:
            (src/rel).parent.mkdir(parents=True)
            (src/rel).write_bytes(b'foo\n')
        if live:
            (site/rel).parent.mkdir(parents=True)
            (site/rel).write_bytes(content)
        return m.audit(src,site,[('example',str(rel))])
    def test_exact_match_does_not_authorize(self):
        x=self.case()
        self.assertTrue(x['full_byte_parity'])
        self.assertFalse(x['release_authorized'])
    def test_missing_code_is_blocked(self):
        x=self.case(candidate=False)
        self.assertEqual(x['state'],'CODE_PATHS_MISSING__HOLD')
    def test_diverging_code_holds(self):
        x=self.case(content=b'foo 2\n')
        self.assertEqual(x['state'],'CODE_PATHS_COMPLETE_WITH_DIVERGENCE__HOLD')
    def test_undeployed_extra_source_does_not_claim_runtime(self):
        x=self.case(live=False,candidate=True)
        self.assertTrue(x['all_live_code_paths_represented'])
        self.assertFalse(x['wordpress_runtime_validated'])
    def test_known_commerce_currency_link_is_not_code(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td)
            link=root/'wp-content/plugins/mad4b-commerce/legacy-mu-tree/currency-data'
            link.parent.mkdir(parents=True)
            link.symlink_to('../../../mu-plugins/currency-data',target_is_directory=True)
            self.assertEqual(m.source_files(root,Path('wp-content/plugins/mad4b-commerce')),{})
    def test_unknown_code_symlink_remains_blocked(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td)
            link=root/'wp-content/plugins/mad4b-commerce/legacy-mu-tree/evil.js'
            link.parent.mkdir(parents=True)
            link.symlink_to('/tmp/untrusted.js')
            with self.assertRaisesRegex(ValueError,'CODE_PATH_SYMLINK_UNTRUSTED'):
                m.source_files(root,Path('wp-content/plugins/mad4b-commerce'))
    def test_bad_path_fails_closed(self):
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)
            with self.assertRaisesRegex(ValueError,'MODULE_OUTSIDE_SCOPE'):
                m.audit(p,p,[('invalid','../private.php')])
if __name__=='__main__':unittest.main()
