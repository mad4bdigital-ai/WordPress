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

# Regression of the nested Tour Rate Elementor JetEngine template CSS.
path='https://tours.dev.mad4b.com/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/'
rate={'url':path,'uael_widgets':[],'css':[],'tour_rate_css':{'shadow_present':True,'original_present':True,
 'card_count':2,'font_px':14.6,'radius_px':25,'bg':'rgb(251, 251, 251)'}}
assert not any('ratecss' in x['code'] for x in m.classify([rate]))
rate['tour_rate_css']['original_present']=False
assert any(x['code']=='perf_ratecss_source_missing' for x in m.classify([rate]))
rate['tour_rate_css']['shadow_present']=False
assert any(x['code']=='perf_ratecss_product' for x in m.classify([rate]))
rate['tour_rate_css']['shadow_present']=True
rate['tour_rate_css']['original_present']=True
rate['tour_rate_css']['font_px']=37.4
rate['tour_rate_css']['radius_px']=0
assert any(x['code']=='perf_ratecss_product' for x in m.classify([rate]))
print('PASS: live rate-style failures, shadow fallback, and source-loss alerts')

long_label={'url':'https://tours.dev.mad4b.com/tours-and-activities/example-long/',
 'uael_widgets':[], 'css':[], 'inline_css_bytes':1800000,
 'inline_css_largest':[{'id':'العناصر-'.join(['العناصر']*50),'bytes':500000}]}
for issue in m.classify([long_label]):
 assert len(issue['label'].encode('utf-8'))<=225,issue
print('PASS: UTF-8 byte-safe MAD4B alert labels')

# Shared rate/property display and mobile Tour Content spacing.
base={'url':'https://tours.dev.mad4b.com/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
 'uael_widgets':[],'css':[], 'accommodation_visibility':{'count':2,'invalid_visible':0,'eligible_visible':0},
 'mobile_toc_gap_px':12,'tour_rate_css':{'shadow_present':True,'original_present':True,'card_count':2,
 'font_px':14.66,'radius_px':25,'bg':'rgb(251, 251, 251)'}}
assert not any(x['code'] in ('perf_empty_accommodation_ui','perf_mobile_toc_spacing') for x in m.classify([base]))
base['accommodation_visibility']['invalid_visible']=1
assert any(x['code']=='perf_empty_accommodation_ui' for x in m.classify([base]))
base['accommodation_visibility']['invalid_visible']=0
base['mobile_toc_gap_px']=0
assert any(x['code']=='perf_mobile_toc_spacing' for x in m.classify([base]))
print('PASS: empty hotel-option display and mobile TOC spacing alerts')
# Departure display defaults to hidden. Continent cannot be a tour departure.
rate_page={'url':'https://tours.dev.mad4b.com/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
 'uael_widgets':[],'css':[],
 'departure_details':{'widgets':6,'unexpected_visible':0,'continent_visible':0,'optin_count':0,'label_count':2,'stale_default_label_count':0},
 'mobile_toc_gap_px':12,'mobile_toc_bottom_gap_px':16,
 'tour_h2':{'color':'rgb(32, 76, 80)','global_secondary':'#204C50','font':'Nunito, sans-serif'},
 'accommodation_visibility':{'count':2,'invalid_visible':0},
 'tour_rate_css':{'shadow_present':True,'original_present':True,'card_count':2,'font_px':14.66,'radius_px':25,'bg':'rgb(251, 251, 251)'}}
assert not any(x['code'] in ('perf_rate_origin_ui','perf_rate_schedule_default','perf_toc_bottom_gap','perf_tour_h2_global') for x in m.classify([rate_page]))
rate_page['departure_details']['unexpected_visible']=1
assert any(x['code']=='perf_rate_origin_ui' for x in m.classify([rate_page]))
rate_page['departure_details']['unexpected_visible']=0
rate_page['departure_details']['stale_default_label_count']=1
assert any(x['code']=='perf_rate_schedule_default' for x in m.classify([rate_page]))
rate_page['departure_details']['stale_default_label_count']=0
rate_page['mobile_toc_bottom_gap_px']=58
assert any(x['code']=='perf_toc_bottom_gap' for x in m.classify([rate_page]))
rate_page['mobile_toc_bottom_gap_px']=16
rate_page['tour_h2']['color']='rgb(16, 45, 64)'
assert any(x['code']=='perf_tour_h2_global' for x in m.classify([rate_page]))
print('PASS: Tour Rate Origin, opt-in schedule, bottom gap and global H2 regression alerts')
