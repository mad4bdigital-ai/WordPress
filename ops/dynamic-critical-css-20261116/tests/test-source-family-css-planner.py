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
print('PASS',checked,'source-fairness and independent CSS route planner tests')
