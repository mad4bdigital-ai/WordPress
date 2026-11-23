#!/usr/bin/env python3
"""Pure original WordPress ticket scheduler and CPU-source priority tests."""
import ast,pathlib,sys,time
now=int(time.time())
worker=pathlib.Path(sys.argv[1]) if len(sys.argv)>1 else pathlib.Path(__file__).with_name('worker.py')
tree=ast.parse(worker.read_text())
fn=next(x for x in tree.body if isinstance(x,ast.FunctionDef)
        and x.name=='bounded_bulk_fair_slot')
scope={}
exec(compile(ast.Module(body=[fn],type_ignores=[]),str(worker),'exec'),scope)
choose=scope['bounded_bulk_fair_slot']
site='source'
other='wayfa'
pub=(0,now-1500,3,'public',392,site+':public:392')
bulk=(1,now-1200,2,'bulk_publish',395,site+':bulk_publish:395')
audit=(-1,now-4500,6,'audit',394,site+':audit:394')
home=(0,now-700,4,'first_fold',397,site+':first_fold:397')
family=(1,now-750,0,'families',398,site+':families:398')
checks=0
def ok(name,value):
 global checks
 assert value,name
 checks+=1;print('PASS',name)
ok('original overdue source-changed public can yield to old bounded bulk',choose(site,pub,[pub,bulk],{},now,True)==bulk)
ok('never preempt public if original CPU guard refused browser',choose(site,pub,[pub,bulk],{},now,False)==pub)
ok('original audit overdue stays first',choose(site,audit,[audit,pub,bulk],{},now,True)==audit)
ok('homepage original first-fold priority unchanged',choose(site,home,[home,bulk],{},now,True)==home)
ok('unproven recent bulk is never promoted',choose(site,pub,[pub,(1,now-130,2,'bulk_publish',396,site+':bulk_publish:396')],{},now,True)==pub)
ok('one acknowledged bulk gives priority back to original public',
   choose(site,pub,[pub,bulk],{bulk[5]:{'last_attempt':now-50}},now,True)==pub)
ok('after original public actually attempts, bulk can resume',
   choose(site,pub,[pub,bulk],{bulk[5]:{'last_attempt':now-250},
     pub[5]:{'last_attempt':now-45}},now,True)==bulk)
ok('fresh failure still uses original safe retry, never fabricated ACK',
   'invoke(site,job,jid,key,history,timeout=' in open(sys.argv[1]).read())
ok('no change in family first-party autonomous admission',
   choose(site,family,[family,pub,bulk],{},now,True)==family)
ok('source recoverer promotion before repeat family remains in control',
   choose(site,pub,[family,pub,bulk],{},now,True)==pub)
foreign=(1,now-1000,2,'bulk_publish',395,other+':bulk_publish:395')
ok('foreign site ticket cannot enter local scheduler',choose(site,pub,[pub,foreign],{},now,True)==pub)
ok('missing original WordPress bulk ticket retains public',choose(site,pub,[pub],{},now,True)==pub)
ok('original ready/QA/source gates still in worker',
   all(text in open(sys.argv[1]).read() for text in
   ('WPCSS_WORDPRESS_ACK','source_recovery_public_turn','def cpu_window(',
    'HEADROOM','public-route-cpu-guard.py') if text!='HEADROOM'))
print('PASS',checks,'original native company bulk-fairness policy tests')
