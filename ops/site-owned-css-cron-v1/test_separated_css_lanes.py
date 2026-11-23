#!/usr/bin/env python3
"""Hermetic live 421/422 QA/publish lane policy; never launches WordPress/Chrome."""
import ast,json,os,pathlib,re,stat,sys,tempfile,time
HERE=pathlib.Path(__file__).resolve().parent
now=int(time.time());count=0

def check(label,valid):
 global count
 assert valid,label
 count+=1
 print('PASS',label)

for site,path in [('source',HERE/'worker.py'),
                  ('wayfa',HERE/'releases/wayfa-worker-20261123.py')]:
 tree=ast.parse(path.read_text())
 names=('original_qa_lease_deadline','budgeted_postrelease_and_publish_turn',
        'verified_task_outcome')
 funcs=[x for x in tree.body if isinstance(x,ast.FunctionDef) and x.name in names]
 check(site+' policy functions present',len(funcs)==3)
 scope={'Path':pathlib.Path,'os':os,'stat':stat,'re':re,'json':json,
        'ROOT':pathlib.Path('/not-used'),
        'aged_original_bulk_qa_exists':lambda *a,**kw:True}
 exec(compile(ast.Module(body=funcs,type_ignores=[]),str(path),'exec'),scope)
 choose=scope['budgeted_postrelease_and_publish_turn'];deadline=scope['original_qa_lease_deadline']
 qa=(1,now-600,1,'bulk_qa',10,site+':bulk_qa:10')
 pub=(1,now-800,2,'bulk_publish',11,site+':bulk_publish:11')
 audit=(-1,now-4000,6,'audit',12,site+':audit:12')
 changes={site+':bulk_qa:9':{'last_attempt':now-400},
          site+':bulk_publish:8':{'last_attempt':now-900}}
 scope['original_qa_lease_deadline']=lambda *a,**kw:now+18000
 check(site+' no urgent lease -> publication follows QA',
       choose(site,qa,[qa,pub],changes,now,True)==pub)
 check(site+' guarded CPU gate preserved',
       choose(site,qa,[qa,pub],changes,now,False)==qa)
 changes[site+':bulk_publish:8']['last_attempt']=now-300
 check(site+' post-publication QA turn',
       choose(site,pub,[qa,pub],changes,now,True)==qa)
 scope['original_qa_lease_deadline']=lambda *a,**kw:now+1100
 check(site+' imminent original QA deadline wins',
       choose(site,pub,[qa,pub],changes,now,True)==qa)
 scope['original_qa_lease_deadline']=lambda *a,**kw:None
 check(site+' no source pending QA -> publish instead of idle',
       choose(site,qa,[qa,pub],changes,now,True)==pub)
 check(site+' original overdue audit remains highest priority',
       choose(site,audit,[audit,qa,pub],changes,now,True)==audit)
 check(site+' cannot invent missing publisher ticket',
       choose(site,qa,[qa],changes,now,True)==qa)
 report=('BULK_CCSS_PUBLISH_RESOURCE_DEFER '+site+' aaaaaaaaaaaa '
         'preserve_wp_ticket_and_route_cursor\n'
         'BULK_CCSS_PUBLISH_COMPLETED '+site+' released 0 attempted 0 pending 0 planned 1200')
 check(site+' resource defer cannot ACK completed marker',
       scope['verified_task_outcome'](site,'bulk_publish',0,report,'')
       ==(False,'host_resource_deferred'))
 check(site+' verified publication still ACKs',
       scope['verified_task_outcome'](site,'bulk_publish',0,
         'BULK_CCSS_PUBLISH_COMPLETED '+site+' released 1 attempted 1 pending 1 planned 1200','')
       ==(True,'verified_bounded_bulk_publication'))
 with tempfile.TemporaryDirectory() as t:
  base=pathlib.Path(t);base.chmod(0o700)
  folder=base/'20261123T120000Z';folder.mkdir(mode=0o700)
  rec={'contract':'mad4b.css.v2.bulk.pending.v1','site':site,
       'host':{'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}[site],
       'jobdir':str(folder),'state':'pending_qa','route_key':'a'*64,
       'released_at':now-900}
  marker=folder/'bulk-pending.json';marker.write_text(json.dumps(rec));marker.chmod(0o600)
  check(site+' original pending certificate lease deadline derived exactly',
        deadline(site,now,root=base)==now-900+21600)
  rec['site']='wayfa' if site=='source' else 'source'
  marker.write_text(json.dumps(rec))
  check(site+' foreign tenant lease cannot enter priority',
        deadline(site,now,root=base) is None)

pub=(HERE.parent/'dynamic-critical-css-20261116/bulk-publish.py').read_text()
check('resource deferral marker before source retry cursor',
      pub.index("print('BULK_CCSS_PUBLISH_RESOURCE_DEFER'") < pub.index('attempts[key]=int(time.time())'))
check('resource gate keeps original strict browser guard',
      all(t in pub for t in ('DEFER_PUBLIC_CCSS_RESOURCE_WINDOW',
                             'SKIP_PUBLIC_CCSS_BROWSER_BUSY',
                             'preserve_wp_ticket_and_route_cursor')))
print('PASS',count,'/',count,'CSS site-owned separated scheduling budget regression checks')
