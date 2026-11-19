import copy,importlib.util,pathlib,sys,hashlib
D=pathlib.Path(sys.argv[1])
def load(file,name):
 sp=importlib.util.spec_from_file_location(name,D/file)
 m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m);return m
n=load('public-route-native-preferred.py','native')
q=load('public-route-queue.py','queue')
widths=[320,390,414,767,768]
def measured(width,gain,passed):
 b={'status':200,'fcp':800,'lcp':1000,'cls':0.006,'h1':[1,2,3,4],
    'hero':None,'popupOpened':None,'calendarOpened':None}
 v=dict(b,rewritten=True,styleLoaded=True,cssMedia='all',
        failedCss=[],errors=[],overflow=0,fcp=800-gain,lcp=1020)
 return {'lang':'en','width':width,'pass':passed,'fcpGain':gain,'lcpReg':20,'baseline':b,'variant':v}
qa={'tested':5,'gates':{'en':{'decision':'hold','widths':widths}},
 'results':[measured(w,(-20 if w<768 else -4),w==768) for w in widths]}
checks=[]
def check(label,value):
 assert value,label;checks.append(label);print('PASS',label)
check('full five-width nonbenefit preserves native original CSS',n.is_native_preferred(qa,'en'))
for label,mutate in [
 ('partial Chromium measurement not terminal',lambda x:x.update(tested=4)),
 ('wrong QA locale does not suppress remeasurement',lambda x:x['results'][0].update(lang='es')),
 ('missing source performance gate not terminal',lambda x:x['gates']['en'].update(decision='unknown')),
 ('interaction error never treated as a performance-only hold',lambda x:x['results'][0]['variant'].update(errors=['boom'])),
 ('layout shift never treated as a performance-only hold',lambda x:x['results'][0]['variant'].update(overflow=200)),
 ('failed fast LCP with good FCP requires investigation not terminal',lambda x:x['results'][0].update({'fcpGain':500,'pass':False}))]:
 x=copy.deepcopy(qa);mutate(x);check(label,not n.is_native_preferred(x,'en'))
route={'type':'page','id':54,'path':'/about-us/','language':'en','revision':3,
       'route_key':hashlib.sha256(b'route').hexdigest()}
cat={'contract':q.CATALOG,'host':'tours.dev.mad4b.com',
     'wpml_languages':['en'],'default_language':'en',
     'global_generation':0,'listing_generation':5,'routes':[route]}
state={'site_host':cat['host'],'critical_css':'on','css_epoch':129,'runtime_sha':'a'*64}
key=q.source_key(cat,route,state,True)
ledger={'contract':q.QUEUE,'site':'source','routes':{
    route['route_key']:{'source_key':key,'status':'native_preferred',
       'depends_on_listings':True,'failures':0,'retry_after':0}}}
check('unchanged native preferred skips further Chromium',q.choose(cat,state,ledger,100)[0] is None)
cat['listing_generation']=6
check('changed first-screen listing automatically requeues native preferred',
      q.choose(cat,state,ledger,100)[0]==route)
print(f'PASS {len(checks)}/{len(checks)} native-preferred negative evidence invariants')
