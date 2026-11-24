import importlib.util,unittest,tempfile,json
from pathlib import Path
from unittest.mock import patch
p=Path(__file__).resolve().parent/'run-http-continuity.py'
spec=importlib.util.spec_from_file_location('continuity',p);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Tests(unittest.TestCase):
    def row(self,locale='en',query=291):
        return dict(locale=locale,page_id=1,document_id=2,widget_id='78d62e0',query_id=query,listing_id=3,url='https://tours.dev.mad4b.com/',query_type='terms',query_generation='v1')
    def data(self,rows):
        return dict(contract='mad4b.native-lazy.auto-discovery.v1',site_host='tours.dev.mad4b.com',page_scan={'next_cursor':31},candidates=rows)
    def test_source_change_rejected(self):
        a={k:'old' for k in ('source_sha256','query_generation','global_data_revision','css_epoch','cli_market','locale','host')}
        self.assertTrue(m.same_source(a,a))
        for k in a:self.assertFalse(m.same_source(a,{**a,k:'new'}))
    def test_cross_tenant_and_nonpublic(self):
        a=self.row(); b={**a,'url':'https://wayfatours.com/'};c={**a,'query_type':'posts'}
        self.assertEqual(len(m.eligible_rows(self.data([a,b,c]),'tours.dev.mad4b.com')),1)
    def test_cursor_advances_and_summary(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(m,'STATE',Path(tmp)),patch.object(m,'copy'),patch.object(m,'cmd',return_value=json.dumps(self.data([self.row(),self.row('es')]))),patch.object(m,'observe',return_value={'host':'tours.dev.mad4b.com','locale':'en','source_sha256':'same'}),patch.object(m.subprocess,'run') as proc,patch.object(m.Path,'read_text',autospec=True,side_effect=lambda p: '0.1 0.1 0.1' if str(p)=='/proc/loadavg' else open(p).read()):
            proc.return_value.returncode=0;proc.return_value.stdout=json.dumps({'ordered_semantics_match':True})
            m.run('tours.dev.mad4b.com',1)
            d=Path(tmp)/'tours.dev.mad4b.com';state=json.loads((d/'state.json').read_text())
            self.assertEqual(state['last_key'],m.scope_key(self.row()))
            summary=json.loads(next(d.glob('*/summary.json')).read_text())
            self.assertEqual(summary['results'][0]['status'],'pass_functional')
            self.assertFalse(summary['native_certificate_written'])
    def test_no_fake_generation(self):
        r=self.row();r['query_generation']=None
        self.assertIsNone(m.eligible_rows(self.data([r]),'tours.dev.mad4b.com')[0]['query_generation'])
if __name__=='__main__':unittest.main()
