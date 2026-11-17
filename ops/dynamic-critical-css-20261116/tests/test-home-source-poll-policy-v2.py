import tempfile,pathlib,json,sys,importlib.util
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('poll',D/'home-source-poll-policy-v2.py');p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
t=1_000_000
with tempfile.TemporaryDirectory() as root:
 f=pathlib.Path(root)/'monitor.json'
 def check(name,want):
  actual=p.cooldown(t,f)
  assert actual==want,(name,actual,want)
  print('PASS',name)
 check('missing evidence retains hourly observation',3600)
 base={'contract':p.CONTRACT,'host':'wayfatours.com','state':'degraded','observed_at':t,
       'routes':{'en':{'http_status':200,'css_ready':0},'es':{'http_status':200,'css_ready':0}}}
 f.write_text(json.dumps(base));check('confirmed current fallback accelerates to 5min',300)
 for key,value in [('state','healthy'),('host','foreign.example'),('contract','unknown')]:
  x=dict(base,**{key:value});f.write_text(json.dumps(x));check('reject '+key,3600)
 f.write_text(json.dumps(dict(base,observed_at=t-1801)));check('stale evidence cannot force perpetual polling',3600)
 f.write_text(json.dumps(dict(base,observed_at=t+1)));check('future timestamp rejected',3600)
 f.write_text(json.dumps(dict(base,routes={'en':base['routes']['en']})));check('incomplete language proof rejected',3600)
 f.write_text(json.dumps(dict(base,routes={'en':{'http_status':200,'css_ready':1},'es':{'http_status':200,'css_ready':1}})));check('healthy route data cannot force 5min',3600)
 f.write_text('bad json');check('corrupt evidence fail safe',3600)
print('PASS 10/10 adaptive-source-drift-read-only TTL policies')
