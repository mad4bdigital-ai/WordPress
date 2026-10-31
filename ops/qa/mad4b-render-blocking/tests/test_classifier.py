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

# Separately classifies a long element-render delay and a consistent slow TTFB.
slow=[{'url':f'https://tours.dev.mad4b.com/page-{i}/',
       'uael_widgets':[], 'css':[], 'ttfb_ms':5100} for i in range(7)]
slow[0]['lcp']={'ms':5200,'render_delay_ms':3880,'tag':'H1','element_id':'headline1'}
regress=m.classify(slow)
assert any(x['code']=='perf_ttfb_site' for x in regress), regress
assert any(x['code'].startswith('perf_lcpdelay_') and '3880ms' in x['label'] for x in regress),regress
slow[0]['lcp']['render_delay_ms']=160
assert not any(x['code'].startswith('perf_lcpdelay_') for x in m.classify(slow))
print('PASS: live TTFB and LCP element render-delay regression classifications')

# Detect third-party font CSS even though it is tiny (<64KiB).
font_page={'url':'https://tours.dev.mad4b.com/ar/', 'uael_widgets':[], 'css':[],
  'external_google_fonts':[{'url':'https://fonts.googleapis.com/css?family=Cairo','duration_ms':750}]}
assert any(x['code'].startswith('perf_fontcss_') for x in m.classify([font_page]))
font_page['external_google_fonts']=[]
assert m.classify([font_page])==[]
print('PASS: external Google Fonts regression detection and resolution')

# Inline CSS size is a separate performance signal; CSS is never removed by discovery.
inline={'url':'https://tours.dev.mad4b.com/tours-and-activities/example/',
 'uael_widgets':[], 'css':[], 'inline_css_bytes':1380000,
 'inline_css_largest':[{'id':'elementor-post-24784','bytes':180000}]}
assert any(x['code'].startswith('perf_inlinecss_') for x in m.classify([inline]))
inline['inline_css_bytes']=30000
assert m.classify([inline])==[]
print('PASS: large inline CSS regression and safe non-destructive detection')

# A late, unprioritized or wrong city LCP preload must raise a governed alert.
base={'url':'https://tours.dev.mad4b.com/','uael_widgets':[], 'css':[],
      'inline_css_bytes':0,'external_google_fonts':[],
      'lcp':{'element_id':'95d1c9c','resource':'https://tours.dev.mad4b.com/img.webp'},
      'city_lcp_preload':{'href':'https://tours.dev.mad4b.com/img.webp','priority':'high','as':'image'},
      'city_lcp_preload_head_offset':350,'city_lcp_request_entries':[{'initiator':'link'}]}
assert not any(x['code']=='perf_lcpdiscover_home' for x in m.classify([base]))
base['city_lcp_preload']['href']='https://tours.dev.mad4b.com/img.jpg'
assert any(x['code']=='perf_lcpdiscover_home' for x in m.classify([base]))
base['city_lcp_preload']['href']='https://tours.dev.mad4b.com/img.webp'
base['city_lcp_preload_head_offset']=200000
assert any(x['code']=='perf_lcpdiscover_home' for x in m.classify([base]))
print('PASS: HTML-first exact-image preload and priority regression contract')
