from qualify_navigation import Scan,evaluate
from copy import deepcopy
import unittest
def doc(term=665,body='Destination',listing=67125):
    return f'<div data-id="6072b3f"><div class="jet-listing-grid__item elementor-dcss-mad4b-aabbccddeeff0011" data-post-id="{term}"><div data-elementor-id="{listing}"><span>{body}</span></div></div></div>'
class Test(unittest.TestCase):
    def setUp(self):
        self.s={'templates':{'67125':{'published':True,'listing':{'source':'terms','tax':'destinations'}},'67124':{'published':True,'listing':{'source':'terms','tax':'tour-types'}}}}
        self.r=[[{'status':'V2_SCOPE_REJECTED'}]]*2
    def ev(self,a,b,s=None,r=None):
        return evaluate(Scan(a),Scan(b),self.s,self.s if s is None else s,self.r if r is None else r)
    def test_exact(self):
        v=self.ev(doc(),doc());self.assertTrue(v['cohorts']['67125']['http_document_evidence_pass']);self.assertFalse(v['replay_authorized'])
    def test_unobserved(self):self.assertEqual(self.ev(doc(),doc())['cohorts']['67124']['state'],'NOT_OBSERVED')
    def test_changed_content(self):self.assertFalse(self.ev(doc(),doc(body='Other'))['cohorts']['67125']['http_document_evidence_pass'])
    def test_changed_term(self):self.assertFalse(self.ev(doc(),doc(term=666))['cohorts']['67125']['http_document_evidence_pass'])
    def test_changed_source(self):self.assertFalse(self.ev(doc(),doc(),{'changed':True})['cohorts']['67125']['http_document_evidence_pass'])
    def test_private_markup(self):self.assertFalse(self.ev(doc(body='<form></form>'),doc(body='<form></form>'))['cohorts']['67125']['http_document_evidence_pass'])
    def test_active_link(self):self.assertFalse(self.ev(doc(body='<a aria-current="page">X</a>'),doc(body='<a aria-current="page">X</a>'))['cohorts']['67125']['http_document_evidence_pass'])
    def test_wrong_scope(self):self.assertFalse(self.ev(doc().replace('6072b3f','other'),doc())['cohorts']['67125']['http_document_evidence_pass'])
    def test_unknown_replay(self):self.assertFalse(self.ev(doc(),doc(),r=[[],[]])['cohorts']['67125']['http_document_evidence_pass'])
    def test_no_approval(self):
        v=self.ev(doc(),doc());self.assertFalse(any(v[x] for x in ['full_renderer_bytes_verified','asset_parity_verified','browser_parity_verified','market_attested','authority_verified','replay_authorized']))
if __name__=='__main__':unittest.main(verbosity=2)
