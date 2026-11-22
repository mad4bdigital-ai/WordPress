#!/usr/bin/env python3
"""No real WordPress/Chromium. QA backpressure source admission proof."""
import ast,sys,time
from pathlib import Path
root=Path(sys.argv[1]);source=(root/'bulk-publish.py').read_text()
tree=ast.parse(source)
fn=next(f for f in tree.body if isinstance(f,ast.FunctionDef) and f.name=='qa_backpressure')
scope={'MIN_PENDING_FOR_BACKPRESSURE':4,'MAX_PENDING_AGE_WITH_NEW_RELEASE':5400}
exec(compile(ast.Module(body=[fn],type_ignores=[]),str(root),'exec'),scope)
gate=scope['qa_backpressure'];now=int(time.time());checks=0
def ok(name,result):
 global checks
 assert result,name
 checks+=1;print('PASS',name)
def pending(count,age):
 return [(now-age+i,object(),{'route_key':'a'*64}) for i in range(count)]
ok('empty original source queue allows first issues',not gate([],now))
ok('a single older pending remains eligible for QA but not a global release freeze',not gate(pending(1,21601),now))
ok('three old pending do not globally starve all new releases',not gate(pending(3,8000),now))
ok('four recent pending can proceed within the 12 lease cap',not gate(pending(4,5399),now))
ok('four old postrelease pending enforce backpressure before new issue',gate(pending(4,5400),now))
ok('twelve old original pending block further publication',gate(pending(12,20000),now))
ok('expiring lease remains blocked until original QA or CAS rollback',gate(pending(4,21700),now))
ok('several fresh pending do not block if no lease near QA deadline',not gate(pending(6,1200),now))
ok('malformed original pending evidence fails closed',gate(['bad']*5,now))
ok('invalid clock fails closed',gate([],True))
ok('source keeps required independent CSS proof and original QA queue reader',
    'qa.pending_rows(site)' in source and 'run-public-route.py' in source
    and 'MAX_ACTIVE_PENDING=12' in source)
print('PASS',checks,'original 422 postrelease backpressure invariants')
