#!/usr/bin/env python3
import importlib.util, pathlib,sys
root=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('family',root/'public-source-families.py')
f=importlib.util.module_from_spec(spec);spec.loader.exec_module(f)
a='a'*64;b='b'*64;c='c'*64;w='d'*64;i='e'*64;r='f'*64
report={'next_canary_route_key':a,'next_canary_route_keys':[a,b,w,c],
        'near_qualified_route_keys':[w],
        'next_individual_css_route_key':i,'next_individual_css_route_keys':[i],
        'next_verifier_only_recheck_route_key':r}
output=[f.plan_next_css_issuance(report,s) for s in range(16)]
assert output[0]==r and output[8]==r,'original verifier retry retained'
assert output[1]==w and output[5]==w and output[9]==w,'warm completion receives 1/4 slots'
assert all(output[x]==i for x in [3,7,11,15]),'exact individual lane retained'
assert len({output[x] for x in [2,4,6,10,12,14]})>=2,'unseen family lane rotates'
fallback={k:v for k,v in report.items() if k!='near_qualified_route_keys'}
assert f.plan_next_css_issuance(fallback,1)!=w,'legacy planner unaffected by extension'
for invalid in (['0'*64],['bad'],[w,w],'fail'):
 try:
  f.plan_next_css_issuance({**report,'near_qualified_route_keys':invalid},1)
 except (ValueError,TypeError): pass
 else:raise AssertionError('invalid near-finish lane accepted')
print('PASS 7/7 source family warm completion and fairness invariants')
