<?php
/** Hermetic, source-only tests. No database, network, or authority registration. */
define('ABSPATH', '/test/');
$GLOBALS['actions'] = array();
$GLOBALS['abilities'] = array();
function add_action($tag,$callback,$priority) {
    $GLOBALS['actions'][$tag][] = compact('callback','priority');
}
function wp_has_ability($name) {
    return isset($GLOBALS['abilities'][$name]);
}
function wp_register_ability($name,$args) {
    $GLOBALS['abilities'][$name] = $args;
}
function check($cond,$msg) {
    if(!$cond) {fwrite(STDERR, "FAIL {$msg}\n"); exit(1);}
    echo "PASS {$msg}\n";
}
require dirname(__DIR__, 3).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-code-release-readiness.php';
$class='MAD4B_SCP_Company_Code_Release_Readiness';
check(count($GLOBALS['actions']['wp_abilities_api_init'])===1,'registered read lifecycle boot hook');
$class::register_ability();
$row=$GLOBALS['abilities'][$class::ABILITY]??null;
check(is_array($row),'registered dedicated read ability');
check(($row['category']??'')==='mad4b-read','ability has read surface only');
check(($row['meta']['annotations']['readonly']??null)===true,'readonly metadata enforced');
check(($row['meta']['show_in_rest']??null)===false,'not public in REST');
check(($row['permission_callback']??null)===array('MAD4B_SCP_Policy','can_read'),'read permission is governed');
$input=$class::status(array());
check(($input['dispatch_ability_registered']??true)===false,'no unregistered dispatch claim');
check(($input['production_release_admitted']??true)===false &&
      ($input['broker_grant_verified']??true)===false &&
      ($input['end_to_end_complete']??true)===false,
      'no production or broker grant inferred');
check(($input['mutation_performed']??true)===false &&
      ($input['authority_created']??true)===false,'no mutation or authority');
$invalid=$class::status(array('authorize'=>true));
check(($invalid['input_valid']??true)===false &&
      ($invalid['production_release_admitted']??true)===false,
      'unexpected input cannot create authorization');
$GLOBALS['abilities'][$class::DISPATCH]=array();
$exists=$class::status(array());
check(($exists['dispatch_ability_registered']??false)===true &&
      ($exists['production_release_admitted']??true)===false &&
      ($exists['original_313_receipt_verified']??true)===false,
      'dispatch existence alone cannot claim live release authority');
check(count($GLOBALS['abilities'])===2,'readability test did not create dispatch');
echo "ALL_COMPANY_RELEASE_READINESS_TESTS_PASS\n";
