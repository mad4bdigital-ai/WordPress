<?php
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/en/tours-and-activities/demo/';
$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']='mobile';
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function is_feed(){return false;}
function is_404(){return false;}
function is_search(){return false;}
function is_preview(){return false;}
function is_checkout(){return false;}
function is_cart(){return false;}
function is_account_page(){return false;}
function is_singular($types){return true;}
function get_queried_object_id(){return 123;}
function get_post($id){return (object)['post_status'=>'publish','post_password'=>''];}
function get_post_field($field,$id){return '2026-11-21 09:30:00';}
function apply_filters($name,$default){return 'en';}
function get_woocommerce_currency(){return 'USD';}
function mad4b_render_query_market_identity(){
  return ['country'=>$GLOBALS['current_market']??'EG','currency'=>'USD'];
}
$scope=(new ReflectionClass('MAD4B_Adaptive_CSS_RUM_V1'))->getMethod('scope');
$GLOBALS['genGlobal']=10;$GLOBALS['genListing']=1436;
$a=$scope->invoke(null);
check(is_array($a)&&$a[0]==='source'&&$a[3]==='mobile','public route tracked by first-party device and site');
$GLOBALS['genListing']=1437;
$b=$scope->invoke(null);
check($a[2]!==$b[2],'JetEngine listing generation drift isolates RUM source cohorts');
$GLOBALS['genGlobal']=11;
$c=$scope->invoke(null);
check($b[2]!==$c[2],'global Elementor generation drift isolates cohorts');
$GLOBALS['current_market']='US';
$d=$scope->invoke(null);
check($c[2]!==$d[2]&&$c[1]!==$d[1],'WCML market identity cannot be aggregated across markets');
$_SERVER['MAD4B_CSS_ROLLOUT_COHORT']='canary';
$_SERVER['MAD4B_CSS_ROLLOUT_ORIGIN_ATTESTED']='0';
$untrusted=$scope->invoke(null);
check($untrusted[7]==='control' && $untrusted[2]===$d[2],
      'spoofable canary marker cannot select signed cohort');
$_SERVER['MAD4B_CSS_ROLLOUT_ORIGIN_ATTESTED']='1';
$trusted=$scope->invoke(null);
check($trusted[7]==='canary' && $trusted[2]!==$d[2],
      'origin FastCGI canary is segregated from control variant');
$cls='MAD4B_Adaptive_CSS_RUM_V1';
$h=$cls::signed_proof($trusted,time());
check(($cls::verify_proof($h)['edge']??null)==='canary',
      'canary identity cryptographically bound to public-page proof');
$_SERVER['REQUEST_URI']='/en/tours-and-activities/demo/?sort=price';
$_SERVER['QUERY_STRING']='sort=price';
check($scope->invoke(null)===null,'query-driven personalized pages excluded from RUM');
echo 'PASS 8 site/market/template/edge and source-safety invariants'.PHP_EOL;
?>
