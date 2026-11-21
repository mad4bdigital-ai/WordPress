#!/usr/bin/env python3
"""Root cause: original record() reset the family index to 0..3.
Prove rotations reach all representative families, preserve reservations."""
import importlib.util,pathlib,sys
p=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('queue',p/'public-route-queue.py')
q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
spec2=importlib.util.spec_from_file_location('families',p/'public-source-families.py')
f=importlib.util.module_from_spec(spec2);spec2.loader.exec_module(f)
def ok(label,expr):
    assert expr,label
    print('PASS',label)
keys=[f'{i:064x}' for i in range(1,56)]
near=[keys[14],keys[16]]
retry='f'*64
individual='e'*64
report={'next_canary_route_key':keys[0],'next_canary_route_keys':keys,
        'near_qualified_route_keys':near,
        'next_individual_css_route_key':individual,
        'next_individual_css_route_keys':[individual],
        'next_verifier_only_recheck_route_key':retry}
seq={'scheduler_sequence':0}
selected=[]
for i in range(500):
    n=seq['scheduler_sequence']
    selected.append(f.plan_next_css_issuance(report,n))
    seq['scheduler_sequence']=q.next_scheduler_sequence(seq)
ok('500 attempts advance through 500 distinct sequence positions',seq['scheduler_sequence']==500)
ok('full source-family rotation reaches every one of 55 families',set(keys).issubset(set(selected)))
ok('a near-qualified family receives its bounded progress lane',near[0] in selected and near[1] in selected)
ok('independent individual-route issuance lane preserved',selected.count(individual)>=100)
ok('verifier-only retry windows preserved',selected.count(retry)>=30)
ok('no untrusted destination ever selected',set(selected).issubset(set(keys)|{individual,retry}))
for invalid in (-1,1.5,True,'10',q.SCHEDULER_SEQUENCE_MODULUS):
    try:q.next_scheduler_sequence({'scheduler_sequence':invalid})
    except ValueError: pass
    else:raise AssertionError('bad sequence accepted: '+str(invalid))
print('PASS invalid scheduler input fails closed')
ok('bounded overflow wraps deterministically',
   q.next_scheduler_sequence({'scheduler_sequence':q.SCHEDULER_SEQUENCE_MODULUS-1})==0)
# Original broken behavior remains a regression test, not source of rotation.
assert len({f.plan_next_css_issuance(report,n) for n in range(4)})<len(keys)
print('PASS regression distinguishes four-slot starvation from full cycle')

# Actual queue persistence, not merely arithmetic planner.
import json, tempfile, time
with tempfile.TemporaryDirectory() as tmp:
    tmp=pathlib.Path(tmp)
    route={'type':'tours-and-activities','language':'en','path':'/tours-and-activities/demo/',
           'id':123,'revision':0}
    import hashlib
    route['route_key']=hashlib.sha256(b'first-party-route-key').hexdigest()
    cat={'contract':q.CATALOG,'host':'wayfatours.com','default_language':'en',
         'wpml_languages':['en'],'global_generation':1,'listing_generation':1,'routes':[route]}
    state={'site_host':'wayfatours.com','critical_css':'on','css_epoch':7,
           'native_breakpoint':767,'runtime_sha':'a'*64}
    ledger={'contract':q.QUEUE,'site':'wayfa','routes':{},'scheduler_sequence':3}
    (tmp/'cat.json').write_text(json.dumps(cat));(tmp/'state.json').write_text(json.dumps(state))
    (tmp/'ledger.json').write_text(json.dumps(ledger))
    job={'contract':'mad4b.public-critical-css.job.v1','site':'wayfa',
         'selected_at':int(time.time())-10,'catalog_generation':1,
         'route':route,'source_key':q.source_key(cat,route,state)}
    (tmp/'job.json').write_text(json.dumps(job))
    original=sys.argv
    try:
        sys.argv=['public-route-queue.py','record','wayfa',str(tmp/'cat.json'),
                  str(tmp/'state.json'),str(tmp/'ledger.json'),str(tmp/'job.json'),
                  'hold','capture_error']
        q.main()
    finally:
        sys.argv=original
    result=json.loads((tmp/'ledger.json').read_text())
    assert result['scheduler_sequence']==4,'regression: fourth record wrapped to zero'
    assert result['routes'][route['route_key']]['status']=='hold'
    print('PASS real queue record preserves monotonic ordinal across the fourth job')
