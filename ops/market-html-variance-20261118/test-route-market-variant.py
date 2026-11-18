#!/usr/bin/env python3
import importlib.util,pathlib,time
p=pathlib.Path(__file__).with_name('route-market-variant.py')
sp=importlib.util.spec_from_file_location('market_policy',p)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
now=1800000000
base={'host':'wayfatours.com','path':'/','language':'en',
      'source_revision':'a'*64,'trusted_origin':True,'signed_market_verified':True,
      'sampled_epoch':now,'currency':'USD','public_listing_sha':'b'*64,
      'displayed_prices':['$ 1575.00','$ 2310.00','$ 60.00']}
rows=[dict(base,market=market) for market in ('US','DE','EG','GB')]
tests=[]
def ok(label,res):
 assert res,label;tests.append(label);print('PASS',label)
decision=m.classify(rows,['US','DE','EG','GB'],now)
ok('actual equal prices group four countries in the same path/currency cohort',
   decision['state']=='shared_shell_candidate' and len(decision['cohorts'])==1)
ok('separate market variant only if an observed price actually changes',
   m.classify([dict(r,displayed_prices=['$ 1500.00'] if r['market']=='EG'
              else r['displayed_prices']) for r in rows],['US','DE','EG','GB'],now)['state']
   =='distinct_market_price_variants')
ok('actual currency change alone makes independent variant',
   m.classify([dict(r,currency='EUR' if r['market']=='DE' else 'USD') for r in rows],
             ['US','DE','EG','GB'],now)['state']=='distinct_market_price_variants')
ok('different public listings are not assumed to be price-only variation',
   m.classify([dict(r,public_listing_sha='f'*64 if r['market']=='DE'
              else 'b'*64) for r in rows],['US','DE','EG','GB'],now)['state']=='hold')
ok('missing market cannot authorize a shared shell',
   m.classify(rows[:3],['US','DE','EG','GB'],now)['state']=='hold')
ok('old source comparison never overrides new price policies',
   m.classify(rows,['US','DE','EG','GB'],now+1900)['state']=='hold')
ok('untrusted cookie or origin cannot authorize edge cache',
   m.classify([dict(r,signed_market_verified=False) if r['market']=='US' else r
     for r in rows],['US','DE','EG','GB'],now)['state']=='hold')
ok('client-only market cannot select a cross-company key',
   m.classify([dict(r,host='tours.dev.mad4b.com') if r['market']=='US'
     else r for r in rows],['US','DE','EG','GB'],now)['state']=='hold')
ok('explicit edge cache authorization remains separate',
   decision['edge_cache_activation']=='not_authorized_by_this_observation')
print('ROUTE_RENDERED_PRICE_VARIANCE_CHECKS',len(tests))
