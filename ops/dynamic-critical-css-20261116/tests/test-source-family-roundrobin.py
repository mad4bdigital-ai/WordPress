#!/usr/bin/env python3
"""Scoped family + exact-route work stays fair across every WPML language."""
from pathlib import Path
import importlib.util,sys
D=Path(sys.argv[1])
sp=importlib.util.spec_from_file_location('css_family_rotating_planner',D/'public-source-families.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
fam=[c*64 for c in 'abcdef']
ind=[c*64 for c in '123']
retry='f'*64
report={'next_canary_route_key':fam[0],'next_canary_route_keys':fam,
        'next_individual_css_route_key':ind[0],
        'next_individual_css_route_keys':ind}
runs=[m.plan_next_css_issuance(report,i) for i in range(24)]
assert runs[:12]==[fam[0],fam[1],fam[2],ind[0],
                    fam[3],fam[4],fam[5],ind[1],
                    fam[0],fam[1],fam[2],ind[2]],runs
print('PASS six independent family canaries alternate without Arabic-first starvation')
assert all(runs[i] in ind for i in range(3,24,4))
assert all(runs[i] in fam for i in range(24) if i%4!=3)
print('PASS exactly 3/4 family slots and 1/4 source-exact CSS issuance')
report_retry={**report,'next_verifier_only_recheck_route_key':retry}
run2=[m.plan_next_css_issuance(report_retry,i) for i in range(24)]
assert run2[0]==run2[8]==run2[16]==retry
assert run2[3]==ind[0] and run2[7]==ind[1] and run2[11]==ind[2]
assert len(set(run2[i] for i in range(24)
               if i%4!=3 and i%8!=0))==len(fam)
print('PASS capped 1/8 source-bound failed-QA retry cannot starve other templates')
assert all(m.plan_next_css_issuance(report_retry,i,'9'*64)=='9'*64 for i in range(24))
print('PASS exact WordPress source drift remains absolute priority')
only_ind={'next_canary_route_keys':[],'next_individual_css_route_keys':ind}
assert [m.plan_next_css_issuance(only_ind,i) for i in range(6)]==ind*2
print('PASS individual CSS routes rotate when all families qualified')
only_fam={'next_canary_route_keys':fam,'next_individual_css_route_keys':[]}
assert set(m.plan_next_css_issuance(only_fam,i) for i in range(24))==set(fam)
print('PASS no artificial waiting when the 1/4 exact-route lane is empty')
for bad in [{'next_canary_route_keys':['0'*63]},
            {'next_canary_route_keys':'0'*64},
            {'next_canary_route_keys':['a'*64,'a'*64]},
            {'next_individual_css_route_keys':['../css']},
            {'next_canary_route_keys':['0'*64]*65}]:
    try:m.plan_next_css_issuance(bad,0)
    except ValueError:pass
    else:raise AssertionError('untrusted family route could cross source authority')
print('PASS 5 malformed/unbounded site-owned source queues are rejected')
print('PASS 6 source-family fairness regression groups')
