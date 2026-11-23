"""Static gate for the narrow cross-site Elementor candidate. Does not write WP."""
from pathlib import Path
import json,unittest
ROOT=Path(__file__).resolve().parent
class CandidateScope(unittest.TestCase):
    def test_allowed_docs_and_changes(self):
        expected={'source':(27,31,9,18,4),'wayfa':(9,9,5,4,0)}
        for site,(docs,total,buttons,animations,locations) in expected.items():
            plan=json.loads((ROOT/f'{site}-plan.json').read_text())
            self.assertEqual(plan['host'],'tours.dev.mad4b.com' if site=='source' else 'wayfatours.com')
            self.assertEqual(len(plan['documents']),docs)
            operations=[o for d in plan['documents'].values() for o in d['operations']]
            self.assertEqual(len(operations),total)
            self.assertEqual(sum(o['field']=='button_css_id' for o in operations),buttons)
            self.assertEqual(sum(o['field']=='_animation_mobile' for o in operations),animations)
            self.assertEqual(sum(o['field']=='_element_id' for o in operations),locations)
            for doc,data in plan['documents'].items():
                self.assertRegex(doc,r'^\d+$')
                self.assertRegex(data['sha256'],r'^[a-f0-9]{64}$')
                for op in data['operations']:
                    self.assertIn(op['field'],('_animation_mobile','_element_id','button_css_id'))
                    if op['field']=='button_css_id':
                        self.assertEqual(op['from'],'tours_query_archive')
                        self.assertTrue(op['to'].startswith('mad4b-'))
                    if op['field']=='_animation_mobile':
                        self.assertEqual((op['from'],op['to']),('','none'))
                    if op['field']=='_element_id':
                        self.assertEqual(site,'source')
                        self.assertEqual(op['node'],'b51f7bd')
                        self.assertEqual(op['to'],'home-wonderful-locations-listing')
    def test_script_contains_safety_guards(self):
        src=(ROOT/'apply-elementor-surgical.php').read_text()
        for token in ('MAD4B_MODE','SOURCE_CAS_DRIFT','CHANGED_UNAUTHORIZED_FIELDS','START TRANSACTION','ROLLBACK','BINARY meta_value','POST_COMMIT_READBACK_FAILED','elementor_element_cache','SITE','wp_json_encode'):
            if token=='SITE':continue
            self.assertIn(token,src)
if __name__=='__main__':unittest.main()
