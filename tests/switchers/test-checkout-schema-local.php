<?php
/** Read-only Store API OPTIONS contract: ensures the real Checkout POST accepts traveller fields. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(57);
$fail=0;
$test=static function($name,$yes)use(&$fail){echo "$name=".($yes?'PASS':'FAIL').PHP_EOL;if(!$yes)$fail++;};
$resp=rest_do_request(new WP_REST_Request('OPTIONS','/wc/store/v1/checkout'));
$test('CHECKOUT_OPTIONS_200',$resp->get_status()===200);
$schema=$resp->get_data()['schema']['properties']['extensions']??[];
$namespace='travel-booking-traveller-nationality';
$ns=$schema['properties'][$namespace]??null;
$test('CHECKOUT_EXTENSION_NAMESPACE_REGISTERED',is_array($ns));
$test('TRAVELLERS_IN_CHECKOUT_POST_SCHEMA',($ns['properties']['travellers']['type']??null)==='object');
$test('CONFIRMED_IN_CHECKOUT_POST_SCHEMA',($ns['properties']['confirmed']['type']??null)==='object');
$sample=[$namespace=>[
 'travellers'=>['cart-42'=>['EG','US','SA']],
 'confirmed'=>['cart-42'=>true],
]];
$valid=rest_validate_value_from_schema($sample,$schema,'extensions');
$clean=rest_sanitize_value_from_schema($sample,$schema,'extensions');
$test('CHECKOUT_EXTENSION_FIELDS_ACCEPTED',$valid===true);
$test('CHECKOUT_EXTENSION_REVIEW_NOT_STRIPPED',
 !is_wp_error($clean)&&($clean[$namespace]['confirmed']['cart-42']??null)===true);
$test('CHECKOUT_EXTENSION_NATIONALITIES_NOT_STRIPPED',
 !is_wp_error($clean)&&($clean[$namespace]['travellers']['cart-42']??null)===['EG','US','SA']);
$wrong=$sample;$wrong[$namespace]['travellers']['cart-42'][1]='NOT-A-COUNTRY';
$test('INVALID_COUNTRY_SHAPE_REJECTED',is_wp_error(rest_validate_value_from_schema($wrong,$schema,'extensions')));
$wrong=$sample;$wrong[$namespace]['confirmed']['cart-42']='yes';
$test('NON_BOOLEAN_REVIEW_REJECTED',is_wp_error(rest_validate_value_from_schema($wrong,$schema,'extensions')));
echo "CHECKOUT_SCHEMA_FAILURES=$fail\n";
exit($fail?1:0);
