import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'tools/verify_live_commerce_source.py'
s=importlib.util.spec_from_file_location('commerce_source_verifier',SRC)
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
M=json.loads(m.DOC.read_text())
class CommerceSource(unittest.TestCase):
 def test_exact_source_snapshot_without_authority(self):
  proof=m.verify(M)
  self.assertEqual(proof['source_files_verified'],269)
  self.assertFalse(proof['release_authorized'])
  self.assertFalse(proof['payment_gateway_activated'])
 def test_no_implicit_activation(self):
  d=copy.deepcopy(M);d['commerce_runtime_activated']=True
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_blob_drift_fails_closed(self):
  d=copy.deepcopy(M);d['files'][0]['sha256']='0'*64
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_traversal_fails_closed(self):
  d=copy.deepcopy(M);d['files'][0]['path']=m.ROOT_NAME+'/../../etc/passwd'
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_currency_link_cannot_be_implicitly_recreated(self):
  self.assertEqual(M['excluded_links'][0]['path'],'legacy-mu-tree/currency-data')
  self.assertFalse((ROOT/m.ROOT_NAME/M['excluded_links'][0]['path']).is_symlink())
 def test_date_filter_reinitializes_cloned_elementor_popup(self):
  js=(ROOT/m.ROOT_NAME/'modules/presentation/assets/unified-date-v1.js').read_text()
  php=(ROOT/m.ROOT_NAME/'modules/presentation/unified-date-presentation-v1.php').read_text()
  self.assertIn('const initializedRoots = new WeakSet()',js)
  self.assertIn('initializedRoots.has(root)',js)
  self.assertIn('initializedRoots.add(root)',js)
  self.assertIn('!instances[i].root.isConnected',js)
  self.assertIn("'0.2.5'",php)
  self.assertNotIn("'0.2.4'",php)
 def test_only_reviewed_date_310_semantic_overlay_is_allowed(self):
  from copy import deepcopy
  self.assertEqual(len(M.get('approved_candidate_overlays') or []),1)
  patch=M['approved_candidate_overlays'][0]
  self.assertEqual(patch['offline_php_cases_passed'],15)
  self.assertIs(patch['deployment_authorized'],False)
  forged=deepcopy(M)
  forged['approved_candidate_overlays'][0]['candidate_sha256']='0'*64
  with self.assertRaises(m.Blocked):m.verify(forged)
 def test_rate_widget_loader_stored_in_commerce_source(self):
  rel=ROOT/m.ROOT_NAME/'legacy-mu-tree/shared-product-core/adapters/rate-actions-styles.php'
  s=rel.read_text()
  self.assertIn('mad4b-rate-widget-lifecycle-2.4.0-20261111.js',s)
  self.assertIn("if (!is_singular('tours-and-activities')) return",s)
  self.assertIn("if (!is_readable($path)) return",s)
if __name__=='__main__':unittest.main()
