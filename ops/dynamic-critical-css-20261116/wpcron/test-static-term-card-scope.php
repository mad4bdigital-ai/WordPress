<?php
define('ABSPATH','/');
require_once __DIR__.'/../../../wp-content/mu-plugins/mad4b-shared-render/public-term-cards.php';
$base='<div class="jet-listing-grid__item" data-post-id="123"><article class="destination-card"><h3>Luxor</h3><a href="/destinations/luxor/">Discover Luxor and famous monuments</a><img src="/wp-content/uploads/luxor.webp" alt="Luxor temple"></article></div>';
$checks=[
  'same destination card is price free'=>[$base,true],
  'price-bearing tour cannot be reused'=>[$base.'<span class="woocommerce-Price-amount">$ 70.00</span>',false],
  'Woo currency markup denied'=>[$base.'<div class="wcml_currency_switcher"></div>',false],
  'country-dependent market selectors denied'=>[$base.'<div data-market="EG"></div>',false],
  'booking form denied'=>[$base.'<form action="/checkout/"></form>',false],
  'query market parameters denied'=>[$base.'<a href="/foo?market=EG">Market</a>',false],
  'escaped market query parameters denied'=>[$base.'<a href="/foo?a=1&amp;currency=USD">A</a>',false],
  'JS personalization denied'=>[$base.'<script>let x=1</script>',false],
  'nonce output denied'=>[$base.'<input name="nonce" value="abc">',false],
  'encoded euro prices denied'=>[$base.'&#8364; 13.50',false],
];
foreach($checks as $name=>$test) {
  $got=mad4b_public_term_market_invariant_html($test[0]);
  if($got!==$test[1]){fwrite(STDERR,'FAIL '.$name.'\n');exit(3);}
  echo 'PASS '.$name.'\n';
}
echo 'STATIC_TERM_CARD_PRICE_SAFE '.count($checks).'\n';
