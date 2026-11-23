#!/usr/bin/env python3
"""Only a fresh original same-source family report can admit public dispatch."""
import ast,datetime,json,os,pathlib,re,stat,sys,tempfile,time,types
root=pathlib.Path(sys.argv[1]);file=root/'worker.py'
tree=ast.parse(file.read_text())
fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef)
        and n.name=='source_recovery_public_turn')
scope={'ROOT':pathlib.Path('/opt/mad4b-company-provisioning'),
       'Path':pathlib.Path,'os':os,'stat':stat,'json':json,
       're':re,'datetime':datetime}
exec(compile(ast.Module(body=[fn],type_ignores=[]),str(file),'exec'),scope)
f=scope['source_recovery_public_turn'];now=int(time.time())
fam=(0,now-30,0,'families',201,'site:families:201')
pub=(1,now-400,1,'public',200,'site:public:200')
audit=(-1,now-4000,4,'audit',199,'site:audit:199')
n=0
def check(label,actual,expected):
 global n
 assert actual==expected,(label,actual,expected)
 n+=1;print('PASS',label)
with tempfile.TemporaryDirectory() as directory:
 p=pathlib.Path(directory)/'source-families-latest.json'
 def report(site='wayfa',age=0,listing=[],runtime=[],groups=21,routes=239):
  return {'contract':'mad4b.public-css.source-family-registry.v1',
    'host':'wayfatours.com' if site=='wayfa' else 'tours.dev.mad4b.com',
    'site':site,'source_groups_total':groups,'routes_discovered':routes,
    'refreshed_at_utc':datetime.datetime.fromtimestamp(now-age,datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
    'recoverable_listing_generation_count':len(listing),
    'recoverable_listing_generation_route_keys':listing,
    'recoverable_same_epoch_runtime_count':len(runtime),
    'recoverable_same_epoch_runtime_route_keys':runtime}
 def save(obj,mtime=now):
  p.unlink(missing_ok=True);p.write_text(json.dumps(obj))
  os.chmod(p,0o600);os.utime(p,(mtime,mtime))
 choose=lambda site='wayfa',c=None,allowed=True:f(site,[fam,pub] if c is None else c,allowed,now,report_path=p)
 save(report())
 check('fresh zero-recovery family report now admits old public browser ticket',choose(),pub)
 check('original CPU must be admitted',choose(allowed=False),fam)
 check('original overdue audit retains precedence',choose(c=[audit,fam,pub]),audit)
 check('no original public request cannot be manufactured',choose(c=[fam]),fam)
 check('already first public ticket remains first',choose(c=[pub,fam]),pub)
 save(report(site='source'));check('wrong site source cannot authorize Wayfa',choose(),fam)
 save(report());check('wrong Wayfa report cannot authorize Source',choose(site='source'),fam)
 save(report(age=601),mtime=now-601);check('older family report is not valid',choose(),fam)
 save(report());check('recently edited public requires a completed family turn',
   choose(c=[fam,(1,now-100,1,'public',200,'site:public:200')]),fam)
 save(report());check('report generated before public ticket must refresh',
   choose(c=[fam,(1,now+1,1,'public',200,'site:public:200')]),fam)
 save(report(groups=0));check('zero family source does not start browser',choose(),fam)
 save(report(routes=2));check('bad family routes inventory rejects browser',choose(),fam)
 save(report(runtime=['a'*64]));check('same-epoch historical runtime report also receives QA',choose(),pub)
 save(report(listing=['b'*64]));check('listing regression keeps original priority',choose(),pub)
 save(report(listing=['b'*64,'b'*64]));check('duplicate recovery keys cannot grant priority',choose(),fam)
 save(report());os.chmod(p,0o666);check('unsafe writable family report rejected',choose(),fam)
 p.unlink();p.symlink_to('/etc/passwd');check('symlink report rejected',choose(),fam)
 p.unlink();check('missing report rejected',choose(),fam)
print('PASS',n,'original WP-Cron public-first fairness invariants')
