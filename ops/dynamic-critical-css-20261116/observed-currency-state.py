#!/usr/bin/env python3
"""Bind actual server-resolved WCML currency from anonymous Chromium source."""
import json,pathlib,re,sys
statefile,headersfile,target=sys.argv[1:]
s=json.load(open(statefile))
headers=pathlib.Path(headersfile).read_text().lower().replace('\r','')
match=re.findall(r'^x-mad4b-public-ccss-currency:\s*([a-z]{3})\s*$',headers,re.M)
market=re.findall(r'^x-mad4b-public-ccss-market:\s*([a-z]{2}|static|base)\s*$',headers,re.M)
if (len(match)!=1 or len(market)!=1 or not re.search(r'^http/[0-9.]+\s+200',headers,re.M)
    or s.get('site_host') not in ['tours.dev.mad4b.com','wayfatours.com']):
    raise SystemExit('HOLD_UNTRUSTED_SERVER_RESOLVED_CURRENCY')
s['currency']=match[0].upper()
s['market_country']=market[0].upper() if market[0] not in ['base','static'] else market[0]
pathlib.Path(target).write_text(json.dumps(s,sort_keys=True,indent=2)+'\n')
print('CHROMIUM_SOURCE_MARKET_BOUND',s['site_host'],s['market_country'],s['currency'])
