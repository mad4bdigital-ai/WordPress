#!/usr/bin/env python3
"""Light original-HTML observation does not consume tickets or trust other tenants."""
import importlib.util,pathlib,sys,types
root=pathlib.Path(sys.argv[1])
sp=importlib.util.spec_from_file_location('original_company_light_observer',root/'worker.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
ss=importlib.util.spec_from_file_location('original_company_cached_snapshot',
    root/'renderer/cached-variant-refresh.py')
cached=importlib.util.module_from_spec(ss);ss.loader.exec_module(cached)
checks=0
def test(condition,label):
 global checks
 assert condition,label
 checks+=1;print('PASS',label)
test(cached.selected_sites(('wayfa',))==('wayfa',),
     'source observer has exact company-only selector')
test(cached.selected_sites(())==('source','wayfa'),
     'legacy trusted two-company diagnostics preserved')
for bad in [('wayfa','source'),('other',),('',),('wayfa/../source',)]:
 try:cached.selected_sites(bad)
 except ValueError:checks+=1;print('PASS untrusted company scope refused')
 else:raise AssertionError(bad)
called=[]
original_run,original_save=m.run,m.save_state
try:
 def captured(cmd,timeout=30,env=None):
  called.append((cmd,timeout,env))
  return types.SimpleNamespace(returncode=0,stdout='LIGHT_VARIANTS_FROM_WP_SNAPSHOT wayfa attempted 1 updated 1 total 203\n',stderr='')
 m.run=captured
 m.save_state=lambda record:None
 history={}
 test(m.observe_source_only_variant_progress('wayfa','families','central_wpcli_slots_reserved',history,1000),
      'advisory original site source can progress with reserved CLI slots')
 test(len(called)==1 and called[0][0][-1]=='wayfa'
      and 'cached-variant-refresh.py' in called[0][0][-2]
      and called[0][1]<=42,'no browser/WPCLI and exact site command only')
 test(not m.observe_source_only_variant_progress('wayfa','public','central_wpcli_slots_reserved',history,1001),
      'cooldown prevents repeated cheap observation on same tick')
 test(not m.observe_source_only_variant_progress('wayfa','public','cpu_headroom_insufficient',{},1600),
      'genuine CPU pressure cannot invoke fallback')
 test(not m.observe_source_only_variant_progress('source','public','central_wpcli_slots_reserved',{},1600),
      'cross company invocation never admitted')
 test(not m.observe_source_only_variant_progress('wayfa','first_fold','central_wpcli_slots_reserved',{},1600),
      'home certification cannot be bypassed by cheap route observer')
 test(len(called)==1,'no second source CLI/browser command emitted')
finally:m.run,m.save_state=original_run,original_save
print(f'PASS {checks}/{checks} site-only resource-admitted variant progress tests')
