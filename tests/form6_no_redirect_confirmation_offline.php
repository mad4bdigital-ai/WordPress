<?php
/** No WordPress service boot, no mail, no payment, no stored submission writes. */
namespace Commerce\Composition {
 final class RequestAdvancePayments {
  public static function submissionContext(int $fid,int $sid):array|\WP_Error {
   if($fid!==6||$sid!==313||!$GLOBALS['sourceValid'])
    return new \WP_Error('original_source_unavailable');
   return ['source_id'=>99,'rate_id'=>101];
  }
  public static function findBySource(int $fid,int $sid,int $limit=3):array {
   return $GLOBALS['orders'];
  }
  public static function isAdvance($o):bool {return $o instanceof \WC_Order && $o->advance;}
  public static function gateways():array {
   return $GLOBALS['gateway']
    ?[['enabled'=>true,'available_now'=>true,'advance_eligible'=>true]]:[];
  }
 }
 final class SingleFluentPaymentRouter {
  public static function active(int $tour,int $fid):bool {
   return $GLOBALS['router'] && $tour===99 && $fid===6;
  }
  public static function rates(int $tour):array {
   return $GLOBALS['rates'] ? [101] : [];
  }
 }
}
namespace {
define('ABSPATH','/offline');
class WP_Error {
 public function __construct(public string $code){}
}
class WC_Order {
 public bool $advance=true;
 public bool $due=true;
 public string $url='https://example.test/order-pay/717/?key=woo-native-key';
 public array $meta=[
  '_request_form_id'=>6,'_request_submission_id'=>313,
  '_request_source_id'=>99,
  '_request_collection_state'=>'awaiting_payment'
 ];
 public function get_meta($key){return $this->meta[$key]??null;}
 public function get_id(){return 717;}
 public function needs_payment():bool{return $this->due;}
 public function get_checkout_payment_url(){return $this->url;}
 public function get_total(){return 355;}
 public function get_currency(){return 'USD';}
}
$GLOBALS['router']=true;
$GLOBALS['rates']=true;
$GLOBALS['gateway']=true;
$GLOBALS['orders']=[];
$GLOBALS['sourceValid']=true;
$GLOBALS['eventWrites']=0;
function add_action(...$args){}
function add_filter(...$args){}
function get_post_type($id){return $id===99?'tours-and-activities':null;}
function get_post_status($id){return $id===99?'publish':'draft';}
function get_post_field($field,$id){return $field==='post_password'?'':'';}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function apply_filters($tag,$value,...$args){
 if($tag==='wpml_current_language')return 'en';
 if($tag==='wpml_element_language_code')return $GLOBALS['language']??'es';
 return $value;
}
function wp_parse_url($v,$component=-1){return parse_url($v,$component);}
function home_url(){return 'https://example.test';}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_url($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function wp_kses_post($html){return $html;}
function wc_price($value,$args=[]){return '$'.number_format((float)$value,2);}
function check(bool $passed,string $why):void {
 if(!$passed){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(2);}
}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-tour-form6-submission-mode-v1.php';
$cls=MAD4B_Tour_Form6_Submission_Mode_V1::class;
$form=(object)['id'=>6];
$base=['success'=>true,'redirectUrl'=>'https://example.test/old-automatic-redirect','message'=>'original'];
$quote=$cls::confirmation($base,$form,null,313);
check(!isset($quote['redirectUrl']),'confirmation never redirects after writing request');
check(str_contains($quote['message'],'mad4b-request-confirmation'),'stable thank-you marker enables optional loyalty hook');
check(str_contains($quote['message'],'FF-313'),'without advance order identify original submission');
check(!str_contains($quote['message'],'order-pay/717'),'no order cannot manufacture payment URL');
$advance=new WC_Order();
$GLOBALS['orders']=[$advance];
$ok=$cls::confirmation($base,$form,null,313);
check(!isset($ok['redirectUrl']),'advance does not force checkout redirect');
check(substr_count($ok['message'],'order-pay/717')===1,'one Woo-native payment CTA');
check(str_contains($ok['message'],'Pagar anticipo de forma segura'),'source post WPML Spanish is used for pay CTA');
check(str_contains($ok['message'],'$355.00'),'advance amount not hidden in payment panel');
check(str_contains($ok['message'],'La reserva no está confirmada'),'trip not confirmed in Spanish thank you');
$GLOBALS['gateway']=false;
$disabled=$cls::confirmation($base,$form,null,313);
check(!str_contains($disabled['message'],'order-pay/717'),'gateway disabled suppresses action even for existing order');
$GLOBALS['gateway']=true;
$GLOBALS['router']=false;
$quoteMode=$cls::confirmation($base,$form,null,313);
check(!str_contains($quoteMode['message'],'order-pay/717'),'quote-only cannot offer advance URL');
$GLOBALS['router']=true;
$GLOBALS['rates']=false;
$noRates=$cls::confirmation($base,$form,null,313);
check(!str_contains($noRates['message'],'order-pay/717'),'rate readiness must be true');
$GLOBALS['rates']=true;
$advance->due=false;
$paid=$cls::confirmation($base,$form,null,313);
check(!str_contains($paid['message'],'order-pay/717'),'paid order should not show payment CTA');
$advance->due=true;
$advance->meta['_request_collection_state']='received';
$received=$cls::confirmation($base,$form,null,313);
check(!str_contains($received['message'],'order-pay/717'),'received advance state must not expose a second payment URL');
$advance->meta['_request_collection_state']='awaiting_payment';
$advance->meta['_request_submission_id']=312;
$mismatch=$cls::confirmation($base,$form,null,313);
check(!str_contains($mismatch['message'],'order-pay/717'),'wrong source submission must be denied');
$advance->meta['_request_submission_id']=313;
$advance->meta['_request_source_id']=100;
$mismatch=$cls::confirmation($base,$form,null,313);
check(!str_contains($mismatch['message'],'order-pay/717'),'wrong parent Tour must not expose payment URL');
$advance->meta['_request_source_id']=99;
$advance->url='https://attacker.example/pay/717/?key=other';
$cross=$cls::confirmation($base,$form,null,313);
check(!str_contains($cross['message'],'attacker.example'),'foreign pay URL never displayed');
$advance->url='https://example.test/order-pay/717/?key=woo-native-key';
$GLOBALS['orders']=[$advance,clone $advance];
$multiple=$cls::confirmation($base,$form,null,313);
check(!str_contains($multiple['message'],'order-pay/717'),'multiple advance receipts never arbitrarily chosen');
$GLOBALS['orders']=[$advance];
$wrongForm=$cls::confirmation($base,(object)['id'=>5],null,313);
check($wrongForm===$base,'unrelated form confirmation left untouched');
$fail=$cls::confirmation(['success'=>false,'message'=>'failure'],$form,null,313);
check($fail['message']==='failure','failed submission is untouched');
$GLOBALS['sourceValid']=false;
$invalid=$cls::confirmation($base,$form,null,313);
check($invalid===$base,'unverified source cannot be rewritten');
check($GLOBALS['eventWrites']===0,'no WordPress write or payment action issued');
echo json_encode(['state'=>'PASS','cases'=>22,'payment_created'=>false,
 'submitted_requests_mutated'=>false,'redirect_issued'=>false,
 'live_browser_tested'=>false]).PHP_EOL;
}
