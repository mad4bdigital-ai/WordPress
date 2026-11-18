#!/usr/bin/env python3
"""Hermetic PHP/WordPress service-activation-to-CCSS discovery contract."""
import importlib.util,pathlib
p=pathlib.Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116/run-public-route.py'
spec=importlib.util.spec_from_file_location('ccss_visibility_runner',p)
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
types=('page','post','properties','archive:properties','property-types',
       'tours-and-activities','archive:tours-and-activities',
       'tour-types','tour-categories','destinations','category')
base={'contract':'mad4b.public-critical-css.routes.v1','host':'wayfatours.com',
      'default_language':'en','wpml_languages':['en','es'],
      'routes':[{'type':kind,'path':'/'+kind+'/'} for kind in types]}
n=0
def check(ok,label):
 global n
 assert ok,label
 n+=1;print('PASS',label)
def get(flags,catalog=base):return mod.public_service_route_scope(catalog,flags)
active={'tours':True,'properties':True}
check(len(get(active)['routes'])==len(types),'all enabled services retain every published route')
hidden=get({'tours':True,'properties':False})
got={x['type'] for x in hidden['routes']}
check(not got.intersection(('properties','archive:properties','property-types')),
      'disabled standalone properties and their type archive excluded')
check(all(x in got for x in ('tours-and-activities','tour-types','destinations','page','category')),
      'disabled hotel channel does not suppress linked tours or independent pages')
check(base['routes'] and len(base['routes'])==len(types),'original WordPress catalogue immutable')
tour_off={x['type'] for x in get({'tours':False,'properties':True})['routes']}
check(all(x not in tour_off for x in ('tours-and-activities','tour-types','tour-categories','archive:tours-and-activities')),
      'disabled tours standalone service does not enter browser')
check('properties' in tour_off,'properties stay admitted when only tours disabled')
source={**base,'host':'tours.dev.mad4b.com'}
check(len(get(None,source)['routes'])==len(types),'original staging source unmodified without Wayfa helper')
for invalid in (None,{},'on',{'properties':False},{'properties':0,'tours':True},{'properties':False,'tours':True,'extra':True}):
 try:get(invalid)
 except ValueError:pass
 else:raise AssertionError('invalid first-party service state should fail closed')
check(True,'missing or malformed Wayfa native service policy fails closed')
check(get({'tours':True,'properties':False})['wpml_languages']==['en','es'],
      'WPML language contract preserved')
assert 'service_visibility_active("properties")' in p.read_text()
assert 'service_visibility_active("tours")' in p.read_text()
check(True,'first-party live service visibility source used for discovery')
print('SERVICE_VISIBILITY_ROUTE_REGRESSIONS',n)
