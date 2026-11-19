<?php
declare(strict_types=1);
define('ABSPATH','/');
$cache=[];
function add_action($name,$cb,$prio=10){ return true; }
function wp_cache_get($key,$group) {
 global $cache;
 return $cache[$group][$key]??false;
}
function wp_cache_set($key,$value,$group,$ttl=0) {
 global $cache;
 if ($ttl!==300) throw new Exception('expected bounded 300-second cache');
 $cache[$group][$key]=$value;
 return true;
}
require_once '/var/www/html/wp-content/mu-plugins/mad4b-shared-render/public-term-cards.php';
require_once '/var/www/html/wp-content/mu-plugins/429-mad4b-signed-home-destination-card-cache-v1.php';
$adapter='MAD4B_Signed_Home_Card_Cache_V1';
$scopeUS=str_repeat('a',64);
$scopeDE=str_repeat('b',64);
$scopeEG=str_repeat('c',64);
$key=str_repeat('d',64);
$us='<div class="jet-listing-grid__item"><article class="public-destination">Luxor heritage temples and valleys are a timeless cultural destination in Upper Egypt.</article></div>';
$de=$us;
$eg='<div class="jet-listing-grid__item"><article class="public-destination">Visit Luxor and its magnificent monuments on the Nile in Upper Egypt.</article></div>';
function check_case($label,$result) {
 if (!$result) {fwrite(STDERR,'FAIL: '.$label.'\n');exit(2);}
 echo 'PASS '.$label.'\n';
}
check_case('unknown country has no cached response',
    $adapter::read_verified_fragment($scopeDE,$key)===false);
check_case('US first source HTML inserted',
    $adapter::write_verified_fragment($scopeUS,$key,$us));
check_case('US verified repeat is exact cache hit',
    $adapter::read_verified_fragment($scopeUS,$key)===$us);
check_case('DE cannot receive US HTML before DE independently renders',
    $adapter::read_verified_fragment($scopeDE,$key)===false);
check_case('DE independently rendered same bytes',
    $adapter::write_verified_fragment($scopeDE,$key,$de));
check_case('same HTML now independently admitted for US and DE',
    $adapter::read_verified_fragment($scopeUS,$key)===$adapter::read_verified_fragment($scopeDE,$key));
check_case('EG distinct HTML independently stored',
    $adapter::write_verified_fragment($scopeEG,$key,$eg));
check_case('EG has its exact HTML, no US/DE bleed',
    $adapter::read_verified_fragment($scopeEG,$key)===$eg
    && $adapter::read_verified_fragment($scopeUS,$key)===$us);
$keys=array_keys($cache['mad4b_verified_dest_cards_v3']??[]);
check_case('same bytes deduplicated across US/DE; differing EG kept distinct',
    count($keys)===5); // 3 market pointers + 2 content bodies.
$bad=$us.'<div class="woocommerce-Price-amount">$ 70.00</div>';
check_case('price-bearing HTML never enters public fragment cache',
    !$adapter::write_verified_fragment($scopeEG,$key,$bad));
$bad2=$us.'<script>window.currency="EGP"</script>';
check_case('dynamic script-bearing HTML never enters public fragment cache',
    !$adapter::write_verified_fragment($scopeEG,$key,$bad2));
$marketKey=hash('sha256','market:'.$scopeUS.'|'.$key);
$cache['mad4b_verified_dest_cards_v3'][$marketKey]=str_repeat('f',64);
check_case('forged/tampered pointer cannot access another market body',
    $adapter::read_verified_fragment($scopeUS,$key)===false);
check_case('untrusted scope rejected',!$adapter::write_verified_fragment('user-country',$key,$us));
check_case('old SHA content cannot be served under a changed key',
    $adapter::read_verified_fragment($scopeDE,str_repeat('e',64))===false);
echo 'MARKET_RENDERED_CARD_FRAGMENT_REGRESSIONS 14\n';
