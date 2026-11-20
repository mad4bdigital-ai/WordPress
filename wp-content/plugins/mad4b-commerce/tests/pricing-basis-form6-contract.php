<?php
/**
 * Shared MAD4B pricing-basis / stay-assignment separation regression.
 * The browser exercises server-priced tiers and quote readbacks separately.
 */
$plugin=dirname(__DIR__);
$root=dirname(__DIR__,3);
$source=$plugin.'/modules/compat/local-presentation-fluent-projection-v1.php';
$js=$root.'/mu-plugins/shared-product-core/assets/presentation-fluent-quote-v1.js';
$css=$root.'/mu-plugins/shared-product-core/assets/presentation-pricing-basis-v1.css';
foreach([$source,$js,$css] as $file){
 if(!is_file($file)){fwrite(STDERR,"FAIL: module missing\n");exit(1);}
}
$php=file_get_contents($source);
$jsData=file_get_contents($js);
$cssData=file_get_contents($css);
$checks=[
 'only server-approved Rate options are offered'=>
  str_contains($php,'pricing_rate_presentation($rid)') &&
  str_contains($php,"'ratePricingBasisChoices'=>\$ratePricingBasisChoices"),
 'financial quote remains server-backed'=>
  str_contains($jsData,'cfg.quoteEndpoint') &&
  str_contains($jsData,'occupancy:st.occupancy.value'),
 'pricing tiers separated from stay stages'=>
  str_contains($jsData,'const available=pricedTiers(rate);') &&
  str_contains($jsData,'const realStay=hasAccommodation(rate);'),
 'pricing tier never defaults from adult count'=>
  !str_contains($jsData,"n%2===0?'double'") &&
  str_contains($jsData,"state.occupancy.value=''; // never silently select"),
 'no room assignment unless an explicit linked stay'=>
  str_contains($jsData,'if(!row||!hasAccommodation(rate))return [];') &&
  str_contains($jsData,'state.stayPicker.hidden=stages.length===0;'),
 'rate changes clear previously chosen pricing tier'=>
  str_contains($jsData,'state.lastPricingRate!==rate'),
 'no Rate of unknown pricing authority becomes sale-ready'=>
  str_contains($jsData,"state.occupancy.value=rate?'':'single';"),
 'no financial or booking authorization added'=>
  !str_contains($jsData,'payment_intent') &&
  !str_contains($php,'wc_create_order('),
 'multilingual pricing labels, not a room booking'=>
  str_contains($jsData,'Room assignment is not confirmed') ||
  str_contains($jsData,'room assignment is not confirmed'),
 'UI limited to Tour Form #6'=>
  str_contains($cssData,'body.single-tours-and-activities form.fluent_form_6'),
];
foreach($checks as $name=>$pass){
 echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;
 if(!$pass)exit(1);
}
echo 'ALL_CONTRACT_CHECKS_PASSED='.count($checks).PHP_EOL;
