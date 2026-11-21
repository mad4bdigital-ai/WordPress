import importlib.util,pathlib,sys
p=pathlib.Path(sys.argv[1])/'public-source-families.py'
sp=importlib.util.spec_from_file_location('company_css_route_planner',p)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
c='a'*64;r='b'*64;d='c'*64
report={'next_canary_route_key':c,'next_individual_css_route_key':r}
checked=0
def ok(val,label):
 global checked
 assert val,label
 checked+=1;print('PASS',label)
for i in range(12):
 expected=r if i%4==3 else c
 ok(m.plan_next_css_issuance(report,i)==expected,'three family to one exact-route slot '+str(i))
ok(m.plan_next_css_issuance({'next_canary_route_key':None,'next_individual_css_route_key':r},5)==r,
   'qualified family remaining route proceeds when all canaries done')
ok(m.plan_next_css_issuance({'next_canary_route_key':c,'next_individual_css_route_key':None},3)==c,
   'pending family canary never suppressed')
ok(m.plan_next_css_issuance({'next_canary_route_key':None,'next_individual_css_route_key':None},3) is None,
   'no grant if no WordPress source route eligible')
ok(m.plan_next_css_issuance(report,5,d)==d,
   'exact verified CSS drift always precedes optional source work')
for candidate in [{**report,'next_canary_route_key':'../other'},
                   {**report,'next_individual_css_route_key':'1'*63},
                   {**report,'next_individual_css_route_key':[]},{}]:
 try:m.plan_next_css_issuance(candidate,0)
 except ValueError:checked+=1;print('PASS untrusted route planner input rejected')
 else:
  if not candidate:ok(m.plan_next_css_issuance(candidate,0) is None,'empty report native fallback')
  else:raise AssertionError('bad input accepted')
# A stale readback retry is a nomination, not a certificate. Never replace
# the original 1/4 independent CSS lane or an authenticated source drift.
retry='e'*64
retry_report={**report,'next_verifier_only_recheck_route_key':retry}
for i in range(12):
 expected=retry if i%4==0 else r if i%4==3 else c
 ok(m.plan_next_css_issuance(retry_report,i)==expected,'bounded old-verifier retry lane '+str(i))
ok(m.plan_next_css_issuance(retry_report,4,d)==d,
   'exact original CSS drift outranks obsolete readback recheck')
ok(m.plan_next_css_issuance({'next_verifier_only_recheck_route_key':retry},7)==retry,
   'sole due verifier retry progresses through unchanged original source admission')
try:m.plan_next_css_issuance({**report,'next_verifier_only_recheck_route_key':'bad'},0)
except ValueError:checked+=1;print('PASS bad verifier retry key denied')
else:raise AssertionError('untrusted verifier retry accepted')
print('PASS',checked,'source-fairness and independent CSS route planner tests')
