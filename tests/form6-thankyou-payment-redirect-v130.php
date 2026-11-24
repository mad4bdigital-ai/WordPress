<?php
declare(strict_types=1);
namespace {
define('ABSPATH','/fixture');
function add_filter(...$args){return true;}
function add_action(...$args){return true;}
function get_post_type($id){return 'tours-and-activities';}
$GLOBALS['redirect_mode']=['mode'=>'stay','seconds'=>15];
function get_option($name,$fallback=[]){return $name==='mad4b_form6_thankyou_redirect_v1'?$GLOBALS['redirect_mode']:$fallback;}
function get_post_status($id){return 'publish';}
function get_post_field($name,$id){return '';}
function get_post_meta($id,$key,$single=true){return '';}
function apply_filters($name,$value,...$args){return $value;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function home_url($path=''){return 'https://wayfatours.com'.$path;}
function is_wp_error($x){return false;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function wp_kses_post($s){return $s;}
function wc_price($amount,$opts=[]){return '$'.number_format((float)$amount,2);}
class WC_Order {
 public function get_id(){return 69842;}
 public function get_meta($k){
  return ['_request_submission_id'=>10,'_request_form_id'=>6,
  '_request_source_id'=>60629,'_request_collection_state'=>'awaiting_payment',
  '_request_payment_schedule'=>'standard'][$k]??'';
 }
 public function needs_payment(){return true;}
 public function get_checkout_payment_url(){return 'https://wayfatours.com/checkout/order-pay/69842/?key=wc_order_validfixture';}
 public function get_currency(){return 'USD';}
 public function get_total(){return 2730;}
}
}
namespace Commerce\Composition {
 final class RequestAdvancePayments {
  public static $hasOrder=true;
  public static $gateway=true;
  public static $fullDue=false;
  public static function isFullDueNow($o){return self::$fullDue;}
  public static function receiptPercentage($o){return self::$fullDue?100.0:50.0;}
  public static function safePaymentUrl($o){return self::$gateway && self::$hasOrder ? $o->get_checkout_payment_url():null;}
  public static function submissionContext($f,$s){return ['source_id'=>60629];}
  public static function findBySource($f,$s,$limit){return self::$hasOrder?[new \WC_Order()]:[];}
  public static function isAdvance($o){return $o instanceof \WC_Order;}
  public static function gateways(){return [
   ['id'=>'stripe','enabled'=>self::$gateway,'available_now'=>self::$gateway,'advance_eligible'=>true]
  ];}
 }
 final class SingleFluentPaymentRouter {
  public static function active($s,$f){return true;}
  public static function rates($s){return [];}
  public static function directSourceCandidate($s){return true;}
  public static function submissionWindow($c){return ['reason'=>'standard_policy'];}
 }
}
namespace {
 $file=$argv[1]??dirname(__DIR__).'/wp-content/mu-plugins/mad4b-tour-form6-submission-mode-v1.php';
 if(!is_file($file))throw new \RuntimeException('Exact Form 6 MU source not found');
 require $file;
 $class='MAD4B_Tour_Form6_Submission_Mode_V1';
 $cases=[];
 foreach([
  ['stay',15,true,true,false,'stay_link'],
  ['countdown',15,true,true,false,'countdown_15'],
  ['countdown',1,true,true,false,'countdown_1'],
  ['countdown',999,true,true,false,'countdown_clamped_15'],
  ['countdown',15,false,true,false,'no_order_no_redirect'],
  ['countdown',15,true,false,false,'gateway_disabled'],
  ['countdown',15,true,true,true,'full_due_countdown'],
 ] as [$mode,$seconds,$hasOrder,$gateway,$isFull,$kind]){
  $GLOBALS['redirect_mode']=['mode'=>$mode,'seconds'=>$seconds];
  \Commerce\Composition\RequestAdvancePayments::$hasOrder=$hasOrder;
  \Commerce\Composition\RequestAdvancePayments::$gateway=$gateway;
  \Commerce\Composition\RequestAdvancePayments::$fullDue=$isFull;
  $response=$class::confirmation(
   ['success'=>true,'redirectUrl'=>'https://example.invalid/force-pay','message'=>'sent'],
   (object)['id'=>6],null,10,null);
  $html=(string)($response['message']??'');
  $link=str_contains($html,'class="mad4b-request-pay-link"');
  $countdown=str_contains($html,'class="mad4b-form6-payment-countdown"');
  $saved=str_contains($html,'Request received');
  $noRedirect=!isset($response['redirectUrl']);
  $expectedLink=$hasOrder&&$gateway;
  $expectedCount=$mode==='countdown'&&$expectedLink;
  if($link!==$expectedLink||$countdown!==$expectedCount||!$saved||!$noRedirect)
     throw new \RuntimeException('Form 6 confirmation contract violated: '.$kind);
  if($expectedCount){
    $actual=min(15,max(1,$seconds));
    if(!str_contains($html,'data-mad4b-redirect-seconds="'.$actual.'"')||
       !str_contains($html,'class="mad4b-form6-countdown-cancel"')||
       !str_contains($html,'class="mad4b-form6-countdown-cancelled"'))
       throw new \RuntimeException('Invalid timer / cancel controls: '.$kind);
  }
  if($expectedLink&&!$isFull&&!str_contains($html,'(50%)'))
    throw new \RuntimeException('Missing saved 50 percent amount');
  if($expectedLink&&$isFull&&!str_contains($html,'(100%)'))
    throw new \RuntimeException('Missing saved 100 percent amount');
  $cases[]=['case'=>$kind,'saved_before_payment'=>true,'payment_link'=>$link,
    'countdown'=>$countdown,'no_automatic_PHP_redirect'=>$noRedirect];
 }
 echo 'PASS: Form 6 confirmed-submission redirect contract ('.count($cases).' cases)'."\n";
 echo json_encode(['status'=>'PASS','cases'=>$cases],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}
