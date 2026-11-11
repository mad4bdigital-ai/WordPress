"""Read-only source drift must never authorize code publication."""
import importlib.util,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from audit_moving_live_source_drift import source_paths,safe_path,attestation
class SourceDrift(unittest.TestCase):
 def test_safe_file_scopes_only(self):
  self.assertEqual(safe_path('wp-content/mu-plugins/mad4b-example.php'),
     'wp-content/mu-plugins/mad4b-example.php')
  self.assertEqual(safe_path('wp-content/plugins/mad4b-demo/x.php'),
     'wp-content/plugins/mad4b-demo/x.php')
  for path in ('/etc/passwd','wp-content/plugins/another/index.php',
     'wp-content/plugins/mad4b-x/../../../etc/passwd',
     'wp-content/mu-plugins/nested/tool.php'):
   with self.assertRaises(ValueError):safe_path(path)
 def test_missing_and_divergent_source_hashes_are_captured(self):
  with tempfile.TemporaryDirectory() as td:
   w=Path(td)/'candidate';l=Path(td)/'live'
   base='wp-content/plugins/mad4b-demo'
   for p in (w/base,l/base,w/'wp-content/mu-plugins',l/'wp-content/mu-plugins'):
    p.mkdir(parents=True,exist_ok=True)
   (w/base/'core.php').write_text('old version')
   (l/base/'core.php').write_text('new version')
   (l/'wp-content/mu-plugins/new.php').write_text('bounded new code')
   missing={'wp-content/mu-plugins/new.php'}
   diffs={'wp-content/plugins/mad4b-demo/core.php'}
   rows=attestation(w,l,missing,diffs)
   self.assertEqual(len(rows),2)
   by_relation={r['relation']:r for r in rows}
   self.assertEqual(set(by_relation),{'missing_in_candidate','content_diverges'})
   self.assertEqual(len(by_relation['content_diverges']['live_sha256']),64)
   self.assertNotEqual(by_relation['content_diverges']['candidate_sha256'],
                       by_relation['content_diverges']['live_sha256'])
   self.assertIsNone(by_relation['missing_in_candidate']['candidate_sha256'])
 def test_inventory_must_not_conflate_absent_and_divergent(self):
  fleet={'modules':[{'missing_paths':['wp-content/plugins/mad4b-a/a.php'],
    'different_paths':['wp-content/plugins/mad4b-a/b.php']}]}
  mu={'missing_paths':['new.php'],'different_paths':['other.php']}
  missing,diffs=source_paths(fleet,mu)
  self.assertEqual(len(missing),2)
  self.assertEqual(len(diffs),2)
  fleet['modules'][0]['different_paths'].append('wp-content/plugins/mad4b-a/a.php')
  with self.assertRaises(ValueError):source_paths(fleet,mu)
 def test_no_operator_grant_in_source_script(self):
  s=(ROOT/'tools/audit_moving_live_source_drift.py').read_text()
  self.assertIn("'production_release_authorized':False",s)
  self.assertIn("'original313_signed_receipt_created':False",s)
  self.assertIn("'company_or_source_website_modified':False",s)
  self.assertNotIn('wp plugin install',s)
  self.assertNotIn('update_option(',s)
if __name__=='__main__':unittest.main()
