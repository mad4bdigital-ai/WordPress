<?php
error_reporting(E_ALL);
define('ABSPATH','/');
define('WPMU_PLUGIN_DIR',__DIR__.'/fixtures');
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function home_url($path='/'){return 'https://tours.dev.mad4b.com/';}
function add_filter(...$args){return true;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function is_preview(){return false;}
function is_customize_preview(){return false;}
require dirname(__DIR__).'/source-416-mad4b-tour-css-delivery.php';
$host='tours.dev.mad4b.com';
$cases=[
 ["https://$host/wp-content/plugins/mad4b-age-occupancy-registry/includes/../assets/fluent-ages-20261103-r2.css",
  "https://$host/wp-content/plugins/mad4b-age-occupancy-registry/assets/fluent-ages-20261103-r2.css"],
 ["//$host/wp-content/plugins/woocommerce-multilingual/templates/currency-switchers/legacy-dropdown/style.css",
  "https://$host/wp-content/plugins/woocommerce-multilingual/templates/currency-switchers/legacy-dropdown/style.css"],
 ["https://$host/wp-content/plugins/jet-search/assets/css/jet-search.css",
  "https://$host/wp-content/plugins/jet-search/assets/css/jet-search.css"],
 ["https://evil.invalid/wp-content/plugins/mad4b-age-occupancy-registry/includes/../assets/a.css",
  "https://evil.invalid/wp-content/plugins/mad4b-age-occupancy-registry/includes/../assets/a.css"]
];
foreach($cases as [$actual,$expected]){
 if(MAD4B_Tour_CSS_Delivery_V1::version($actual)!==$expected) throw new Exception('failed '. $actual);
}
echo 'WORDPRESS_STYLE_LOADER_NORMALIZER_INTEGRATION_PASS '.count($cases).PHP_EOL;
$checks=[
 ['//tours.dev.mad4b.com/wp-content/plugins/a/%2e%2e/assets/a.css',
  '//tours.dev.mad4b.com/wp-content/plugins/a/%2e%2e/assets/a.css'],
 ['https://evil.invalid/wp-content/plugins/a/includes/../assets/a.css',
  'https://evil.invalid/wp-content/plugins/a/includes/../assets/a.css'],
 ['//tours.dev.mad4b.com.evil.invalid/wp-content/plugins/foo/a.css',
  '//tours.dev.mad4b.com.evil.invalid/wp-content/plugins/foo/a.css'],
 ['https://tours.dev.mad4b.com:444/wp-content/plugins/foo/a.css',
  'https://tours.dev.mad4b.com:444/wp-content/plugins/foo/a.css'],
 ['https://tours.dev.mad4b.com/wp-content/plugins/foo/../../etc/password.css',
  'https://tours.dev.mad4b.com/wp-content/plugins/foo/../../etc/password.css']
];
foreach($checks as [$input,$expected]){
 if(MAD4B_Tour_CSS_Delivery_V1::version($input)!==$expected)
   throw new Exception('unsafe normalization: '.$input);
}
echo 'SOURCE_CSS_NORMALIZATION_FAIL_CLOSED_PASS '.count($checks).PHP_EOL;
