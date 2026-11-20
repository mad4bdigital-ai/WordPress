#!/usr/bin/env python3
"""Hermetic safety checks for source-family ingestion of certified V2 CSS."""
import hashlib,importlib.util,json,pathlib,tempfile,copy,os
BASE=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('mad4b_v2_family_test',BASE/'public-v2-family-canary.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
count=0
def ok(condition,label):
 global count
 assert condition,label
 count+=1
 print('PASS',label)
with tempfile.TemporaryDirectory() as d:
 root=pathlib.Path(d)
 certroot=root/'mad4b-public-critical-css-v1/certs-v2'
 certroot.mkdir(parents=True,mode=0o755)
 host='wayfatours.com';path='/tours-and-activities/sample-day-tour/'
 cert={'contract':module.CONTRACT,'status':'certified','host':host,
       'language':'en','path':path,'market_country':'base','currency':'USD'}
 key=module.digest((host+'|en|'+path+'|base|USD').encode())
 (certroot/(key+'.json')).write_text(json.dumps(cert)+'\n')
 rows=module.load_into(root,{})
 ok(len(rows)==1,'certified v2 registered by exact route and market')
 ok(rows[('en',path,'base','USD')]==cert,'certificate contents preserved')
 (certroot/(key+'.json')).write_text(json.dumps({**cert,'status':'released_pending_qa'})+'\n')
 ok(module.load_into(root,{})=={},'unqualified pending certificate never added')
 (certroot/(key+'.json')).write_text(json.dumps({**cert,'status':'reverted_post_release_qa'})+'\n')
 ok(module.load_into(root,{})=={},'reverted certificate never added')
 (certroot/(key+'.json')).write_text(json.dumps(cert)+'\n')
 previous={('en',path,'base','USD'):{'contract':'legacy-route'}}
 ok(module.load_into(root,previous)[('en',path,'base','USD')]['contract']==module.CONTRACT,
    'new certified v2 replaces exact old v1 market route only')
 (certroot/(key+'.json')).unlink()
 wrong=certroot/('f'*64+'.json');wrong.write_text(json.dumps(cert)+'\n')
 ok(module.load_into(root,{})=={},'wrong content-addressed filename never admitted')
 wrong.unlink()
 (certroot/(key+'.json')).write_text('[]')
 ok(module.load_into(root,{})=={},'non-object JSON cannot break family registry')
 (certroot/(key+'.json')).unlink()
 (certroot/(key+'.json')).symlink_to(root/'dummy')
 ok(module.load_into(root,{})=={},'symlink certificates ignored')
 (certroot/(key+'.json')).unlink()
 route={'id':60473,'type':'tours-and-activities','language':'en','path':path,'revision':1}
 state={'css_epoch':252,'runtime_sha':'0'*64,'base_currency':'USD',
        'wcml_multi_currency':False,'enabled_currencies':['USD']}
 catalog={'global_generation':3,'listing_generation':6}
 ledger={'status':'certified','source_key':'source'}
 near={'contract':module.CONTRACT,'status':'released_pending_qa'}
 ok(not module.validate(root,host,route,state,catalog,ledger,near,'source'),
    'pending v2 cannot qualify a family regardless of past QA')
 near['status']='certified'
 ok(not module.validate(root,host,route,state,catalog,ledger,near,'other'),
    'different source ledger identity cannot qualify v2')
 near['market_country']='EG'
 ok(not module.validate(root,host,route,state,catalog,ledger,near,'source'),
    'different country context cannot qualify base USD family')
print(f'PASS {count}/{count} v2 family-source fail-closed tests')
