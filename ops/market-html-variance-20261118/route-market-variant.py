#!/usr/bin/env python3
"""Decide route-level public HTML equivalence from observed output, NOT engines.

Read-only: provides content-addressed grouping for a future verified FastCGI
public-shell integration. Nginx must NEVER use this helper's labels without
independent trusted-cookie admission, neutral shell and private price hydration.
"""
import hashlib,json,re,time
from urllib.parse import urlsplit

CONTRACT='mad4b.market-rendered-html-equivalence.v1'
HOSTS={'wayfatours.com':{'en','es'},'tours.dev.mad4b.com':{'en','ar','de','fr','es','it'}}
ISO2=re.compile(r'[A-Z]{2}|base')
CURRENCY=re.compile(r'[A-Z]{3}')
SHA=re.compile(r'[a-f0-9]{64}')

def digest(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True,
            ensure_ascii=False,separators=(',',':')).encode()).hexdigest()

def classify(snapshots,required_markets,now=None):
    """A missing, uncertain or stale comparison is not market equivalence."""
    now=int(time.time() if now is None else now)
    if (not isinstance(snapshots,list) or not snapshots
        or not isinstance(required_markets,(list,set,tuple))
        or not required_markets
        or len(required_markets)>100):
        return {'state':'hold','reason':'missing_exact_market_sample_set'}
    if any(not isinstance(s,dict) for s in snapshots):
        return {'state':'hold','reason':'invalid_market_sample'}
    first=snapshots[0];host=first.get('host')
    path=first.get('path');lang=first.get('language')
    rev=first.get('source_revision')
    if (host not in HOSTS or lang not in HOSTS[host]
        or not isinstance(path,str) or len(path)>500 or '?' in path or '#' in path
        or not path.startswith('/') or '//' in path or '..' in path
        or not isinstance(rev,str) or not SHA.fullmatch(rev)):
        return {'state':'hold','reason':'untrusted_route_or_generation'}
    markets=set(required_markets)
    if any(not isinstance(m,str) or not ISO2.fullmatch(m) for m in markets):
        return {'state':'hold','reason':'untrusted_market_scope'}
    if len(snapshots)!=len(markets) or {s.get('market') for s in snapshots}!=markets:
        return {'state':'hold','reason':'incomplete_or_duplicate_market_comparison'}
    profiles=[]
    for row in snapshots:
        if any(row.get(k)!=first.get(k) for k in ('host','path','language','source_revision')):
            return {'state':'hold','reason':'cross_route_or_generation_mix'}
        if row.get('trusted_origin') is not True or row.get('signed_market_verified') is not True:
            return {'state':'hold','reason':'unverified_origin_or_market'}
        stamp=row.get('sampled_epoch')
        if not isinstance(stamp,int) or isinstance(stamp,bool) or not -5<=now-stamp<=1800:
            return {'state':'hold','reason':'stale_market_output_sample'}
        currency=row.get('currency')
        amounts=row.get('displayed_prices')
        graph=row.get('public_listing_sha')
        if (not isinstance(currency,str) or not CURRENCY.fullmatch(currency)
            or not isinstance(graph,str) or not SHA.fullmatch(graph)
            or not isinstance(amounts,list) or len(amounts)>50
            or any(not isinstance(a,str) or not 1<=len(a)<=100 for a in amounts)):
            return {'state':'hold','reason':'invalid_live_pricing_or_listing_graph'}
        # Different public listing grids are not a price-only variation.
        profiles.append((row['market'],currency,digest(amounts),graph))
    if len({p[3] for p in profiles})!=1:
        return {'state':'hold','reason':'public_listing_graph_differs'}
    groups={}
    for market,currency,prices,graph in profiles:
        identity=digest({'host':host,'path':path,'language':lang,
                         'currency':currency,'displayed_prices_sha':prices,
                         'public_listing_sha':graph,'source_revision':rev})
        groups.setdefault(identity,[]).append(market)
    return {'contract':CONTRACT,
            'state':'shared_shell_candidate' if len(groups)==1 else 'distinct_market_price_variants',
            'host':host,'path':path,'language':lang,
            'source_revision':rev,
            'cohorts':[{'cache_identity_sha':key,'markets':sorted(markets)}
                       for key,markets in sorted(groups.items())],
            'independent_private_state_required':True,
            'edge_cache_activation':'not_authorized_by_this_observation'}

if __name__=='__main__':
    import sys
    payload=json.load(sys.stdin)
    print(json.dumps(classify(payload['samples'],payload['markets']),indent=2))
