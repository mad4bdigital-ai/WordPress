#!/usr/bin/env python3
"""Regression: exact DE/EUR post-release proof contributes to source-family
observation, never authorizes currency/market transfer to sibling routes."""
import importlib.util, pathlib, sys
root=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('v2',root/'public-v2-family-canary.py')
v2=importlib.util.module_from_spec(spec);spec.loader.exec_module(v2)
def check(case,ok):
    assert ok, case
    print('PASS',case)
route={'type':'tours-and-activities'}
cert={'dependencies':{'contains_dynamic_listing':True},
      'market_country':'DE','currency':'EUR'}
geo={'wcml_multi_currency':True,'enabled_currencies':['EGP','EUR','GBP','USD'],
     'base_currency':'USD'}
check('valid country EUR certificate observed in exact geo scope',
      v2.exact_market_is_supported(route,cert,geo))
check('foreign currency rejected',
      not v2.exact_market_is_supported(route,{**cert,'currency':'JPY'},geo))
check('malformed country rejected',
      not v2.exact_market_is_supported(route,{**cert,'market_country':'ZZZ'},geo))
check('non-geographic site cannot inherit foreign-market certificate',
      not v2.exact_market_is_supported(route,cert,{**geo,
           'wcml_multi_currency':False,'enabled_currencies':['USD']}))
check('base USD single-market remains accepted',
      v2.exact_market_is_supported(route,{**cert,'market_country':'base','currency':'USD'},
              {'wcml_multi_currency':False,'enabled_currencies':['USD'],'base_currency':'USD'}))
check('static page never accepts a geographic dynamic variant',
      not v2.exact_market_is_supported(
          {'type':'page'}, {'dependencies':{'contains_dynamic_listing':False},
           'market_country':'DE','currency':'EUR'},geo))
check('static page exact market accepted independently',
      v2.exact_market_is_supported(
          {'type':'page'}, {'dependencies':{'contains_dynamic_listing':False},
           'market_country':'static','currency':'EUR'},geo))
check('untrusted currency context fail closed',
      not v2.exact_market_is_supported(route,cert,{**geo,'enabled_currencies':[]}))
