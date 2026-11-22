#!/usr/bin/env python3
"""Hermetic original first-party QA urgency; no WordPress/Chromium access."""
import ast,json,os,pathlib,re,stat,sys,tempfile,time
worker=pathlib.Path(sys.argv[1])
tree=ast.parse(worker.read_text())
names=['aged_original_bulk_qa_exists','prioritize_aged_original_bulk_qa']
fns=[x for x in tree.body if isinstance(x,ast.FunctionDef) and x.name in names]
assert len(fns)==2
scope={'Path':pathlib.Path,'os':os,'json':json,'re':re,'stat':stat}
exec(compile(ast.Module(body=fns,type_ignores=[]),str(worker),'exec'),scope)
exists=scope['aged_original_bulk_qa_exists']
pick=scope['prioritize_aged_original_bulk_qa']
checks=0
def ok(text,value):
 global checks
 assert value,text
 checks+=1;print('PASS',text)
now=int(time.time())
site='wayfa';other='source'
pub=(0,now-9000,3,'public',354,'wayfa:public:354')
bulk=(1,now-7000,2,'bulk_publish',355,'wayfa:bulk_publish:355')
qa=(1,now-6500,1,'bulk_qa',353,'wayfa:bulk_qa:353')
audit=(-1,now-7200,6,'audit',352,'wayfa:audit:352')
with tempfile.TemporaryDirectory() as temp:
 root=pathlib.Path(temp)
 d=root/'20261122T102000Z';d.mkdir(mode=0o700)
 f=d/'bulk-pending.json'
 record={'contract':'mad4b.css.v2.bulk.pending.v1',
    'site':'wayfa','host':'wayfatours.com','jobdir':str(d),
    'route_key':'a'*64,'released_at':now-3650,'state':'pending_qa',
    'market_country':'base','currency':'USD','path':'/tours/test/'}
 f.write_text(json.dumps(record));f.chmod(0o600)
 ok('first-party 1h pending source triggers urgency',exists('wayfa',now,root))
 ok('native source publisher yields to aged original WordPress bulk QA',
    pick(site,pub,[pub,bulk,qa],now,True,root)==qa)
 ok('native family publisher also yields to urgent original WP QA',
    pick(site,bulk,[bulk,qa],now,True,root)==qa)
 ok('strict host CPU deferral unchanged',pick(site,pub,[pub,qa],now,False,root)==pub)
 ok('overdue original audit cannot be starved by QA',
    pick(site,audit,[audit,pub,qa],now,True,root)==audit)
 ok('cannot fabricate QA ticket',pick(site,pub,[pub,bulk],now,True,root)==pub)
 ok('QA already selected is preserved',pick(site,qa,[qa,pub],now,True,root)==qa)
 ok('other company source not accepted',not exists('source',now,root))
 ok('cross-company WP ticket cannot enter scheduler',
    pick(site,pub,[pub,(1,now-6500,1,'bulk_qa',355,'source:bulk_qa:355')],now,True,root)==pub)
 record['released_at']=now-1300
 f.write_text(json.dumps(record))
 ok('freshly issued pending V2 cert does not preempt source',
    not exists('wayfa',now,root) and pick(site,pub,[pub,qa],now,True,root)==pub)
 record['released_at']=now-6*3600-150
 f.write_text(json.dumps(record))
 ok('expired provisional still receives original CAS cleanup',
    exists('wayfa',now,root) and pick(site,pub,[pub,qa],now,True,root)==qa)
 (d/'bulk-complete.json').write_text('{}')
 ok('completed QA cannot hold a scheduler slot',not exists('wayfa',now,root))
 (d/'bulk-complete.json').unlink()
 record['host']='tours.dev.mad4b.com';f.write_text(json.dumps(record))
 ok('foreign site host rejected',not exists('wayfa',now,root))
 record['host']='wayfatours.com';record['site']='source';f.write_text(json.dumps(record))
 ok('foreign site document rejected',not exists('wayfa',now,root))
 record['site']='wayfa';record['jobdir']='/tmp/untrusted';f.write_text(json.dumps(record))
 ok('untrusted worker output path rejected',not exists('wayfa',now,root))
 record['jobdir']=str(d);f.write_text(json.dumps(record))
 f.chmod(0o666)
 ok('writable private proof rejected',not exists('wayfa',now,root))
 f.chmod(0o600)
 f.unlink();f.symlink_to('/etc/hosts')
 ok('symlink proof rejected',not exists('wayfa',now,root))
 f.unlink();f.write_text(json.dumps(record));f.chmod(0o600)
 ok('valid original proof accepted again after rejected payloads',exists('wayfa',now,root))
 ok('preserves original ack and CPU validation in worker source',
    all(x in worker.read_text() for x in ['def cpu_window():','WPCSS_WORDPRESS_ACK','def ack(site,job,jid):',
        'bounded_bulk_fair_slot','source_recovery_public_turn']))
print('PASS',checks,'root-owned original bulk QA slot fixtures')
