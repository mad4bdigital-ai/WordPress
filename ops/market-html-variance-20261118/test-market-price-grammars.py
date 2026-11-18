#!/usr/bin/env python3
"""Both original Wayfatours public price presentations must be verified."""
import importlib.util
from pathlib import Path
from importlib.machinery import SourceFileLoader
p=Path(__file__).with_name('capture-live-route-variance.py')
spec=importlib.util.spec_from_loader('html_market_price_grammar',SourceFileLoader('html_market_price_grammar',str(p)))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def check(name,fragment,language,expected):
    parser=m.PublicPage();parser.feed(fragment)
    actual=parser.displayed_prices(language)
    assert actual==expected,(name,actual,expected)
    print('PASS',name)
check('Spanish real dynamic field price/currency siblings',
      '<div class="jet-listing-dynamic-field__content">Desde 2,310.00</div>'
      '<div class="jet-listing-dynamic-field__content">USD</div>'
      '<span class="price-on-request">Precio a consultar</span>'
      '<div class="jet-listing-dynamic-field__content">Desde 70.00</div>'
      '<div class="jet-listing-dynamic-field__content">USD</div>'
      '<div class="jet-listing-dynamic-field__content">Desde 120.00</div>'
      '<div class="jet-listing-dynamic-field__content">USD</div>',
      'es',(['USD Desde 2,310.00','USD Desde 70.00',
             'USD Desde 120.00','QUOTE_ONLY'],'USD'))
check('Spanish missing independent currency sibling denied',
      '<div class="jet-listing-dynamic-field__content">Desde 70.00</div>'
      '<div class="jet-listing-dynamic-field__content">Other content</div>',
      'es',None)
check('Spanish conflicting currencies denied',
      '<div class="jet-listing-dynamic-field__content">Desde 70.00</div>'
      '<div class="jet-listing-dynamic-field__content">USD</div>'
      '<div class="jet-listing-dynamic-field__content">Desde 120.00</div>'
      '<div class="jet-listing-dynamic-field__content">EUR</div>'
      '<div class="jet-listing-dynamic-field__content">Desde 90.00</div>'
      '<div class="jet-listing-dynamic-field__content">USD</div>',
      'es',None)
check('Spanish amount without field provenance denied',
      '<h2>Desde 70.00 USD</h2>','es',None)
check('Spanish price on request without numeric provenance denied',
      '<span class="price-on-request">Precio a consultar</span>',
      'es',None)
en=''.join('<span class="woocommerce-Price-amount"><bdi>$ '+str(n)+'.00</bdi></span>'
           for n in range(10,19))
p=m.PublicPage();p.feed(en)
assert p.displayed_prices('en')==(['$ '+str(n)+'.00' for n in range(10,19)],'USD')
print('PASS exact English Woo source markup')
en_bad=en.replace('$ 10.00','EUR 10.00')
p=m.PublicPage();p.feed(en_bad)
assert p.displayed_prices('en') is None
print('PASS unrecognized English currency presentation held')
print('MARKET_PRICE_PRESENTATION_REGRESSIONS 7')
