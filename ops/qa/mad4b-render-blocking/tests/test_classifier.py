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
 'uael_widgets':[],'css':[], 'is_tour_single':True, 'accommodation_visibility':{'count':2,'invalid_visible':0,'eligible_visible':0},
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
 'uael_widgets':[],'css':[], 'is_tour_single':True,
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

# Full Rate census: only opted-in invalid sources trigger errors, not hidden legacy data.
census={'site_uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a','scanned':200,'publish':180,'draft':20,'opted_in':0,'hidden_legacy_rates':33,'schema_valid':True,'defaults_hidden':True,'opted_in_invalid_origin':[],'orphan_published_rate':[]}
assert not any(x['code'] in ('perf_rate_schema_drift','perf_rate_origin_config','perf_rate_orphan') for x in m.classify([rate_page],census))
census['schema_valid']=False
assert any(x['code']=='perf_rate_schema_drift' for x in m.classify([rate_page],census))
census['schema_valid']=True
census['opted_in_invalid_origin']=[8001]
assert any(x['code']=='perf_rate_origin_config' for x in m.classify([rate_page],census))
census['opted_in_invalid_origin']=[]
census['orphan_published_rate']=[8003]
assert any(x['code']=='perf_rate_orphan' for x in m.classify([rate_page],census))
assert any(x['code']=='perf_rate_locale_parity' for x in m.classify([dict(rate_page,departure_details=dict(rate_page['departure_details'],label_count=2)), dict(rate_page,url='https://tours.dev.mad4b.com/ar/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',departure_details=dict(rate_page['departure_details'],label_count=0))]))
print('PASS: whole-site census, neutral hidden legacy data and translated product parity')

# A literal header escape is never equivalent to a normal line break.
clean_header={'url':'https://tours.dev.mad4b.com/','uael_widgets':[],'css':[],
 'header_literal_text_count':0,'swiper_active_inert':0,'forced_layout':{'supported':True,'frames':2,'forced_ms':30},
 'city_lcp_preload':{'href':'https://tours.dev.mad4b.com/wp-content/uploads/card.webp','priority':'high','as':'image'},
 'city_lcp_preload_head_offset':300,'ttfb_ms':100,'city_lcp_request_entries':[{'start_ms':125}],
 'lcp':{'element_id':'95d1c9c','resource':'https://tours.dev.mad4b.com/wp-content/uploads/card.webp'}}
assert not any(x['code'] in ('perf_header_escape_text','perf_forced_reflow_home','perf_lcp_request_wait') for x in m.classify([clean_header]))
clean_header['header_literal_text_count']=2
assert len([x for x in m.classify([clean_header,dict(clean_header,url='https://tours.dev.mad4b.com/ar/')]) if x['code']=='perf_header_escape_text'])==1
clean_header['header_literal_text_count']=0
clean_header['forced_layout']['forced_ms']=300
assert any(x['code']=='perf_forced_reflow_home' for x in m.classify([clean_header]))
clean_header['forced_layout']['forced_ms']=30
clean_header['city_lcp_request_entries'][0]['start_ms']=900
assert any(x['code']=='perf_lcp_request_wait' for x in m.classify([clean_header]))
clean_header['city_lcp_request_entries'][0]['start_ms']=125
clean_header['swiper_active_inert']=1
assert any(x['code'].startswith('perf_swiper_focus_') for x in m.classify([clean_header]))
print('PASS: header literal, forced layout, LCP resource discovery and Swiper focus alerts')

# Sitewide first-render/layout, manifest and crawlable-anchor detection.
catalog={'url':'https://tours.dev.mad4b.com/tours-and-activities/','css':[],'uael_widgets':[],
 'catalog_initial':{'present':True,'style_present':True,'layout_direction':'row'},
 'chosen_without_href':0,'ard_href':'https://tours.dev.mad4b.com/.well-known/ard.json'}
assert not any(i['code'] in ('perf_catalog_initial_layout','perf_chosen_crawlability','perf_ard_manifest') for i in m.classify([catalog]))
catalog['catalog_initial']['layout_direction']='column'
assert any(i['code']=='perf_catalog_initial_layout' for i in m.classify([catalog]))
catalog['catalog_initial']['layout_direction']='row'
home=dict(catalog,url='https://tours.dev.mad4b.com/',ard_manifest_valid=False,chosen_without_href=1)
codes={i['code'] for i in m.classify([home])}
assert 'perf_ard_manifest' in codes and 'perf_chosen_crawlability' in codes
home['ard_manifest_valid']=True;home['chosen_without_href']=0
assert not any(i['code'] in ('perf_ard_manifest','perf_chosen_crawlability') for i in m.classify([home]))
print('PASS: initial CSS, ARD endpoint and Chosen href are discoverable regressions')

# Older translated Elementor topology is a distinct non-blocking alert; do not
# report CSS missing if the actual locale-specific native style is present.
fr=dict(catalog,url='https://tours.dev.mad4b.com/fr/tours-and-activities/',
    catalog_initial={'present':True,'style_present':True,'layout_direction':'row',
        'wpml_locale':'fr','template_id':35070,'structural_drift':True})
issues={i['code'] for i in m.classify([fr])}
assert 'perf_catalog_template_drift' in issues and 'perf_catalog_initial_layout' not in issues
fr['catalog_initial']['structural_drift']=False
assert 'perf_catalog_template_drift' not in {i['code'] for i in m.classify([fr])}
print('PASS: genuine WPML structural drift is separated from first-render CSS availability')

# The List and Grid intentionally use different authored layouts. Only first-paint
# SVG and price-tag computed styles may regress. Price values are never rewritten.
valid={'post_id':'60629','card':{'width':883,'height':527},
       'arrow':{'width':18,'height':18},'clock':{'width':18,'height':18},
       'favorite':{'width':23,'height':23},
       'price':{'width':159,'height':20,'font_px':14},
       'amount':{'font_px':14},'unit':{'font_px':10.5},
       'widget':{'height':20},'price_source':'rate'}
archive={'url':'https://tours.dev.mad4b.com/tours-and-activities/', 'css':[],
         'uael_widgets':[], 'catalog_ui_cards':{'present':True,'samples':[valid]},
         'catalog_initial':{'present':True,'style_present':True,'layout_direction':'row'}}
assert 'perf_catalog_svg_price_first_paint' not in {i['code'] for i in m.classify([archive])}
import copy
broken=copy.deepcopy(archive)
broken['catalog_ui_cards']['samples'][0]['arrow']['width']=128
assert 'perf_catalog_svg_price_first_paint' in {i['code'] for i in m.classify([broken])}
broken=copy.deepcopy(archive)
broken['catalog_ui_cards']['samples'][0]['price']['font_px']=32
broken['catalog_ui_cards']['samples'][0]['widget']['height']=160
assert 'perf_catalog_svg_price_first_paint' in {i['code'] for i in m.classify([broken])}
no_rate=copy.deepcopy(archive)
no_rate['catalog_ui_cards']['samples'][0].pop('price')
assert 'perf_catalog_svg_price_first_paint' not in {i['code'] for i in m.classify([no_rate])}
print('PASS: first-paint SVG and rate-derived price size guards, legitimate no-rate state')
