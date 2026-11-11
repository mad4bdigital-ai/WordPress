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
  self.assertEqual(proof['source_files_verified'],286)
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
  js=(ROOT/m.ROOT_NAME/'modules/presentation/assets/unified-date-v0.3.5.js').read_text()
  php=(ROOT/m.ROOT_NAME/'modules/presentation/unified-date-presentation-v1.php').read_text()
  self.assertIn('const initializedRoots = new WeakSet()',js)
  self.assertIn('initializedRoots.has(root)',js)
  self.assertIn('initializedRoots.add(root)',js)
  self.assertIn('!instances[i].root.isConnected',js)
  self.assertIn("'0.3.5'",php)
  self.assertNotIn("'0.2.7'",php)
 def test_immutable_date_assets_and_legacy_fallback_provenance(self):
  assets=ROOT/m.ROOT_NAME/'modules/presentation/assets'
  php=(ROOT/m.ROOT_NAME/'modules/presentation/unified-date-presentation-v1.php').read_text()
  self.assertIn('unified-date-v0.3.5.js',php)
  self.assertIn('unified-date-v0.3.5.css',php)
  for ver in ('0.2.6','0.2.7','0.3.0'):
   for ext in ('js','css'):
    path=assets/('unified-date-v'+ver+'.'+ext)
    self.assertTrue(path.is_file())
    manifest_row=next(r for r in M['files'] if r['path'].endswith('/'+path.name))
    import hashlib
    candidate_sha=hashlib.sha256(path.read_bytes()).hexdigest()
    overlays={r['path']:r for r in M['approved_candidate_overlays']}
    self.assertEqual(candidate_sha,overlays[manifest_row['path']]['candidate_sha256']
      if manifest_row['path'] in overlays else manifest_row['sha256'])
  newest=assets/'unified-date-v0.3.5.js'
  row=next(r for r in M['files'] if r['path'].endswith('/'+newest.name))
  latest_overlay=next(o for o in M['approved_candidate_overlays'] if o['path']==row['path'])
  self.assertEqual(hashlib.sha256(newest.read_bytes()).hexdigest(),latest_overlay['candidate_sha256'])
  self.assertEqual(row['sha256'],latest_overlay['live_sha256'])
  overlays={r['path']:r for r in M['approved_candidate_overlays']}
  for oldasset in ('unified-date-v0.3.1.js','unified-date-v0.3.0.css','unified-date-v0.3.2.js'):
   old=assets/oldasset
   oldrow=next(r for r in M['files'] if r['path'].endswith('/'+oldasset))
   expected=overlays[oldrow['path']]['candidate_sha256'] if oldrow['path'] in overlays else oldrow['sha256']
   self.assertEqual(hashlib.sha256(old.read_bytes()).hexdigest(),expected)
  newestcss=assets/'unified-date-v0.3.5.css'
  cssrow=next(r for r in M['files'] if r['path'].endswith('/'+newestcss.name))
  self.assertEqual(hashlib.sha256(newestcss.read_bytes()).hexdigest(),cssrow['sha256'])

 def test_both_exact_reviewed_date_semantic_overlays_are_required(self):
  from copy import deepcopy
  patches=M.get('approved_candidate_overlays') or []
  self.assertEqual(len(patches),3)
  by_policy={item['policy']:item for item in patches}
  self.assertEqual(by_policy['read_only_and_intersection_fail_closed']['offline_php_cases_passed'],45)
  self.assertEqual(by_policy['clear_wpml_stale_date_and_empty_fluent_input']['offline_js_cases_passed'],23)
  self.assertEqual(m.verify(M)['semantic_overlays_verified'],3)
  self.assertEqual(m.verify(M)['live_files_byte_identical_to_candidate'],283)
  for patch in patches:
   self.assertIs(patch['deployment_authorized'],False)
   self.assertIs(patch['booking_authorized'],False)
 def test_reject_malformed_or_forged_date_overlays(self):
  from copy import deepcopy
  for i in (0,1):
   forged=deepcopy(M)
   forged['approved_candidate_overlays'][i]['candidate_sha256']='0'*64
   with self.assertRaises(m.Blocked):m.verify(forged)
   forged=deepcopy(M)
   forged['approved_candidate_overlays'][i]['deployment_authorized']=True
   with self.assertRaises(m.Blocked):m.verify(forged)
  forged=deepcopy(M)
  forged['approved_candidate_overlays'].append(forged['approved_candidate_overlays'][0])
  with self.assertRaises(m.Blocked):m.verify(forged)
  forged=deepcopy(M)
  forged['approved_candidate_overlays'][0]['path']='wp-content/plugins/mad4b-commerce/mad4b-commerce.php'
  with self.assertRaises(m.Blocked):m.verify(forged)
 def test_rate_widget_loader_stored_in_commerce_source(self):
  rel=ROOT/m.ROOT_NAME/'legacy-mu-tree/shared-product-core/adapters/rate-actions-styles.php'
  s=rel.read_text()
  self.assertIn('mad4b-rate-widget-lifecycle-2.4.0-20261111.js',s)
  self.assertIn("if (!is_singular('tours-and-activities')) return",s)
  self.assertIn("if (!is_readable($path)) return",s)
if __name__=='__main__':unittest.main()
