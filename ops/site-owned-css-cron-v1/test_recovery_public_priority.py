#!/usr/bin/env python3
"""Read-only dispatch ordering must never mint source CSS or bypass Chrome CPU."""
import ast,datetime,json,os,pathlib,sys,tempfile,time,types,re,stat
root=pathlib.Path(sys.argv[1])
worker=root/'worker.py'
tree=ast.parse(worker.read_text())
fn=next((node for node in tree.body if isinstance(node,ast.FunctionDef)
         and node.name=='source_recovery_public_turn'),None)
assert fn is not None,'missing original WP CSS scheduling policy'
context={'ROOT':pathlib.Path('/opt/mad4b-company-provisioning'),
         'Path':pathlib.Path,'os':os,'stat':stat,'json':json,
         're':re,'datetime':datetime,'time':time}
exec(compile(ast.Module(body=[fn],type_ignores=[]),str(worker),'exec'),context)
m=types.SimpleNamespace(**context)
now=int(time.time())
p_family=(0,now-15,0,'families',200,'site:families:200')
p_public=(1,now-300,1,'public',190,'site:public:190')
p_audit=(-1,now-4000,4,'audit',180,'site:audit:180')
checks=0
def check(label,p):
 global checks
 assert p,label
 checks+=1
 print('PASS',label)
def report(site,stamp=now,n=2):
 return {'contract':'mad4b.public-css.source-family-registry.v1',
         'site':site,'host':'tours.dev.mad4b.com' if site=='source' else 'wayfatours.com',
         'refreshed_at_utc':datetime.datetime.fromtimestamp(stamp,datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
         'recoverable_listing_generation_count':n,
         'recoverable_listing_generation_route_keys':[f'{j:064x}' for j in range(1,n+1)],
         'source_groups_total':23,'source_canary_qualified_groups':0}
with tempfile.TemporaryDirectory() as temp:
 path=pathlib.Path(temp)/'source-families-latest.json'
 def save(doc,mtime=now):
  path.unlink(missing_ok=True)
  path.write_text(json.dumps(doc,sort_keys=True,indent=2)+'\n')
  os.chmod(path,0o600);os.utime(path,(mtime,mtime))
 save(report('wayfa'))
 choose=lambda site='wayfa',rows=None,admitted=True:m.source_recovery_public_turn(
     site,[p_family,p_public] if rows is None else rows,admitted,now,report_path=path)
 check('original valid Wayfa source-family report prioritizes previously waiting public WP ticket',choose()==p_public)
 check('no public CPU admission cannot use recovery priority',choose(admitted=False)==p_family)
 check('an overdue original audit remains higher priority',choose(rows=[p_audit,p_family,p_public])==p_audit)
 check('public cannot be selected when no original public ticket exists',choose(rows=[p_family])==p_family)
 check('not allowed to replace an already correctly selected public ticket',choose(rows=[p_public,p_family])==p_public)
 check('no foreign Source site may trust Wayfa report',choose(site='source')==p_family)
 save({**report('wayfa'),'host':'foreign.example.com'})
 check('wrong company hostname is rejected',choose()==p_family)
 save({**report('wayfa'),'recoverable_listing_generation_count':0,'recoverable_listing_generation_route_keys':[]})
 check('no stale recovery cannot displace normal family refresh',choose()==p_family)
 save({**report('wayfa'),'recoverable_listing_generation_route_keys':['a'*64,'a'*64]})
 check('duplicate or tampered route keys rejected',choose()==p_family)
 save({**report('wayfa'),'recoverable_listing_generation_route_keys':['invalid','f'*64]})
 check('malformed original route identity rejected',choose()==p_family)
 save(report('wayfa',stamp=now-605),mtime=now-605)
 check('a report older than 10 minutes yields priority to family refresh',choose()==p_family)
 save(report('wayfa',stamp=now-600),mtime=now)
 check('old-source timestamp with faked fresh mtime rejected',choose()==p_family)
 save(report('wayfa'))
 os.chmod(path,0o666)
 check('world writable family summary never authorizes recovery priority',choose()==p_family)
 path.unlink();path.symlink_to('/etc/passwd')
 check('symlink family summary never authorizes priority',choose()==p_family)
 path.unlink()
 check('missing original summary preserves normal priority',choose()==p_family)
 save(report('wayfa'))
 check('Wayfa selector may also respect an attested local Source report',choose()==p_public)
 text=worker.read_text()
 check('recovered public ticket still uses unchanged original strict browser gate',
       'headroom,reason=cpu_window()' in text and
       'source_recovery_public_turn(site,pending,headroom,now)' in text)
 check('no ticket ACK or browser grant in selector',all(x not in text[text.index('def source_recovery_public_turn('):text.index('def invoke(')]
       for x in ('ack(',"'certified'",'public-route-certificate.py','run_site.sh')))
print('PASS',checks,'original-source dispatch fairness invariants')
