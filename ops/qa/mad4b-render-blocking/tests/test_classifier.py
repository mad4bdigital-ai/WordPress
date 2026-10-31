import hashlib,importlib.util,pathlib,sys,types
root=pathlib.Path(__file__).resolve().parents[1]
pw=types.ModuleType('playwright')
api=types.ModuleType('playwright.async_api')
api.async_playwright=None
sys.modules['playwright']=pw;sys.modules['playwright.async_api']=api
spec=importlib.util.spec_from_file_location('cssprobe',root/'mad4b-live-css-render-blocking.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
sample={'url':'https://tours.dev.mad4b.com/ar/','uael_widgets':[],
'css':[{'handle':'uael-frontend-css','encoded_bytes':84262,'blocking':True}]}
items=m.classify([sample])
assert len(items)==1 and items[0]['severity']=='high'
assert items[0]['code']=='perf_rbcss_'+hashlib.sha256(b'/ar/|uael-frontend-css').hexdigest()[:12]
sample['css'][0]['blocking']=False
assert m.classify([sample])==[]
sample['url']='https://tours.dev.mad4b.com/destination/africa-containent/egypt/'
sample['uael_widgets']=[{'height':248}]
sample['css'][0]['blocking']=True
assert len(m.classify([sample]))==1 and 'retain styles' in m.classify([sample])[0]['label']
sample['css'][0]['encoded_bytes']=10000
assert m.classify([sample])==[]
print('PASS: mobile CSS render-blocking classification, resolution, widget preservation and size threshold')
