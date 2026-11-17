"""No starvation across new languages, changed CSS and pending retry proof."""
import hashlib,importlib.util,pathlib,sys,time
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('queue',D/'public-route-queue.py')
q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
def row(typ,id,path,language='en'):
 return {'type':typ,'id':id,'path':path,'revision':0,'language':language,
         'route_key':hashlib.sha256(f'{typ}|{id}|{path}|{language}'.encode()).hexdigest()}
en0=row('page',100,'/about-us/')
en1=row('page',101,'/contact-us/')
en2=row('page',102,'/privacy-policy/')
en3=row('page',103,'/faq/')
es0=row('page',104,'/es/contacto/','es')
tour=row('tours-and-activities',105,'/tours-and-activities/river/')
ship=row('properties',106,'/properties/cruise/')
cat={'contract':q.CATALOG,'host':'tours.dev.mad4b.com',
     'default_language':'en','wpml_languages':['en','es'],
     'global_generation':0,'listing_generation':0,
     'routes':[en0,en1,en2,en3,es0,tour,ship]}
state={'site_host':cat['host'],'critical_css':'on','css_epoch':42,
       'native_breakpoint':767,'runtime_sha':'a'*64}
ledger={'contract':q.QUEUE,'site':'source','scheduler_sequence':1,'routes':{
 en0['route_key']:{'source_key':q.source_key(cat,en0,state),
                   'status':'certified','attempted_at':1000,'depends_on_listings':False}}}
checks=[]
def ok(msg,value):
 assert value,msg;checks.append(msg);print('PASS',msg)
picked=q.choose(cat,state,ledger,2000)[0]
ok('new translation gets coverage before repeating already measured English pages',picked==es0)
ledger['routes'][es0['route_key']]={'source_key':q.source_key(cat,es0,state),
   'status':'certified','attempted_at':1001,'depends_on_listings':False}
picked=q.choose(cat,state,ledger,2000)[0]
ok('untested tour receives a first browser slot when both static languages already covered',picked==tour)
ledger['routes'][tour['route_key']]={'source_key':q.source_key(cat,tour,state),
   'status':'hold','retry_after':4000,'attempted_at':1200,'depends_on_listings':True}
picked=q.choose(cat,state,ledger,2000)[0]
ok('route on cooldown does not displace new property coverage',picked==ship)
ledger['routes'][en0['route_key']]['source_key']='b'*64
ledger['scheduler_sequence']=0
picked=q.choose(cat,state,ledger,2000)[0]
ok('changed previously certified route receives a reserved refresh slot',picked==en0)
for slot in (1,2,3):
 ledger['scheduler_sequence']=slot
 picked=q.choose(cat,state,ledger,2000)[0]
 ok(f'fresh coverage receives reserved slot {slot}',picked!=en0 and picked in (ship,en1,en2,en3))
ledger['scheduler_sequence']=0
ledger['routes'][en0['route_key']]['source_key']=q.source_key(cat,en0,state)
ledger['routes'][en0['route_key']]['status']='native_candidate'
ledger['routes'][en0['route_key']]['attempted_at']=900
ledger['routes'][en0['route_key']]['retry_after']=1800
ok('independent negative performance confirmation is not starved by unseen routes',
   q.choose(cat,state,ledger,2000)[0]==en0)
ledger['routes'][en0['route_key']]['retry_after']=2400
ok('independent negative performance confirmation honors cooldown',
   q.choose(cat,state,ledger,2000)[0]!=en0)
cat['routes']=[en0];ledger['scheduler_sequence']=3
ledger['routes'][en0['route_key']]['status']='certified'
ledger['routes'][en0['route_key']]['source_key']=q.source_key(cat,en0,state)
ok('unchanged certified route never triggers browser again',
   q.choose(cat,state,ledger,2000)[0] is None)
cat['global_generation']=1
ok('real dependency change reopens exact already certified route',
   q.choose(cat,state,ledger,2000)[0]==en0)
print(f'PASS {len(checks)}/{len(checks)} automatic language and CSS epoch fairness invariants')
