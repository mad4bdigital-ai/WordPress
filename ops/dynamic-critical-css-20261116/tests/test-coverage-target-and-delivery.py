#!/usr/bin/env python3
"""No WordPress, Chrome, CSS mutation: current source vs delivered HTML."""
import ast,sys
from pathlib import Path
tree=ast.parse((Path(sys.argv[1])/'health-audit.py').read_text())
fn=next(x for x in tree.body if isinstance(x,ast.FunctionDef)
        and x.name=='original_target_delivery_coverage')
scope={}
exec(compile(ast.Module(body=[fn],type_ignores=[]),'coverage', 'exec'),scope)
f=scope['original_target_delivery_coverage']
checks=0
def ok(msg,result):
 global checks
 assert result,msg
 checks+=1;print('PASS',msg)
def route(path,key,type_='page'):
 return {'type':type_,'path':path,'route_key':key}
catalog={'routes':[route('/','0'),route('/es/','1'),
   route('/tours/one/','2','tours-and-activities'),
   route('/es/tours/dos/','3','tours-and-activities'),
   route('/some-other/','4')]}
state={'default_language':'en','wpml_languages':['en','es']}
sources={'routes_with_exact_current_certificate':2}
p={'language':'en','path':'/tours/one/','market':'base','currency':'USD',
 'certificate_for_observed_market':True,'ready_public':'1',
 'active_in_observed_market':True,'html_delivery_contract':{
 'delivery':'CACHE_HIT_CCSS','html_css_verified':True,'css_contract':'v2'}}
out=f(catalog,state,sources,[p])
ok('catalog has five WPML routes including two independent home routes',
   out['discovered_catalog_entries']==5 and out['separate_home_first_fold_routes']==2)
ok('V2 target is exactly three original nonhome public routes',out['target_nonhome_routes']==3)
ok('two current source-bound certificate identities do not become three',
   out['exact_current_source_certificate_routes']==2 and out['unproven_or_native_target_routes']==1)
ok('source coverage is exact ratio not cached CSS delivery',out['current_source_route_percent']==66.67)
ok('verified public FastCGI HTML is one actual sample, never scaled',
   out['sampled_verified_v2_delivery_count']==1 and out['anonymous_http_sample_count']==1)
ok('never count small HTTP sample as sitewide full coverage',out['full_coverage_verified'] is False)
for invalid in (
 {**p,'ready_public':None},
 {**p,'certificate_for_observed_market':False},
 {**p,'active_in_observed_market':False},
 {**p,'html_delivery_contract':{**p['html_delivery_contract'],'css_contract':None}},
 {**p,'html_delivery_contract':{**p['html_delivery_contract'],'html_css_verified':False}},
 {**p,'html_delivery_contract':{**p['html_delivery_contract'],'delivery':'CACHE_HIT_NATIVE'}}):
 ok('invalid or nonmatching public/market source is never counted as delivered',
    f(catalog,state,sources,[invalid])['sampled_verified_v2_delivery_count']==0)
try:f(catalog,{**state,'wpml_languages':['en','es','fr']},sources,[])
except ValueError:ok('missing original locale home route fails safely',True)
else:raise AssertionError('unknown locale scope admitted')
try:f(catalog,state,{'routes_with_exact_current_certificate':4},[])
except ValueError:ok('cannot manufacture more certified routes than eligible target',True)
else:raise AssertionError('inflated certified count admitted')
print('PASS',checks,'original source-current route versus actual mobile HTML readback')
