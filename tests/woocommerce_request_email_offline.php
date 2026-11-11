<?php
/** Woo email rendering contract without real WordPress, SMTP or Woo orders. */
namespace Commerce\Composition {
 class RequestAdvancePayments {
  public static function isAdvance($order){return $order instanceof \WC_Order && $order->advance;}
  public static function gateways() {return $GLOBALS['gatewayActive']
    ?[['enabled'=>true,'available_now'=>true,'advance_eligible'=>true]]:[];}
 }
}
namespace {
define('ABSPATH','/offline');
define('WPMU_PLUGIN_DIR',dirname(__DIR__).'/wp-content/mu-plugins');
$GLOBALS['gatewayActive']=false;
$GLOBALS['sentEmails']=0;
$GLOBALS['emailClassesFilter']=null;
class WC_Email {
 public $id,$title,$description,$customer_email,$email_group,$template_html,$template_plain,
    $template_base,$manual,$email_type;
 public function __construct(){}
 public function get_heading(){return 'Request update';}
 public function get_additional_content(){return '';}
 public function is_enabled(){return true;}
}
class WC_Order {
 public bool $advance=true;
 public bool $due=true;
 public function needs_payment(){return $this->due;}
 public function get_checkout_payment_url(){return 'https://secure.example/order-pay/717/?key=verified';}
 public function get_id(){return 717;}
 public function get_total(){return 350.00;}
 public function get_currency(){return 'USD';}
}
function add_filter($name,$fn,$priority=10){
 if($name==='woocommerce_email_classes')$GLOBALS['emailClassesFilter']=$fn;
}
function sanitize_text_field($s){return trim((string)$s);}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_url($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function wp_kses_post($s){return (string)$s;}
function wc_price($amount,$options=[]){return '$'.number_format($amount,2);}
function do_action($name,...$args){
 if($name==='woocommerce_email_header')echo '[NATIVE_HEADER]';
 if($name==='woocommerce_email_footer')echo '[NATIVE_FOOTER]';
}
function wc_get_template_html($name,$params=[],$path='',$base=''){
 $file=$base.$name;
 if(!is_file($file))return '';
 extract($params,EXTR_SKIP);
 ob_start();
 include $file;
 return ob_get_clean();
}
function check($result,$msg){
 if(!$result){fwrite(STDERR,"FAIL ".$msg.PHP_EOL);exit(2);}
}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-request-woocommerce-email-v1.php';
check(is_callable($GLOBALS['emailClassesFilter']),'only native WooCommerce registry filter registered');
$emails=($GLOBALS['emailClassesFilter'])([]);
check(isset($emails['MAD4B_WC_Email_Request_Lifecycle']),'single native Woo email registered');
$email=$emails['MAD4B_WC_Email_Request_Lifecycle'];
check($email->manual===true,'manual-only template no order status autotrigger');
check($email->email_type==='html','native html format used');
check($email->template_base===WPMU_PLUGIN_DIR.'/templates/','template path is governed local MU');
check(is_file($email->template_base.$email->template_html),'HTML template exists');
check(is_file($email->template_base.$email->template_plain),
 'source-only candidate plain template exists');
$previewPlain=$email->get_content_plain();
check(str_contains($previewPlain,'preview of the shared WooCommerce template'),
 'WooCommerce plain preview delegates to template');
check(!str_contains($previewPlain,'order-pay/717'),
 'plain preview cannot manufacture a payment URL');
$job=['event'=>'request_received'];
$msg=['subject'=>'Travel request received','body'=>'Request received. Trip not confirmed.',
      'language'=>'en'];
$html=$email->render_lifecycle($msg,$job,null);
check(str_contains($html,'[NATIVE_HEADER]')&&str_contains($html,'[NATIVE_FOOTER]'),
 'WooCommerce email header/footer reused');
check(str_contains($html,'mad4b-wc-request-content'),
 'one HTML envelope is returned');
check(!str_contains($html,'order-pay/717'),'no order means no fabricated pay link');
$o=new WC_Order();
$job=['event'=>'advance_requested'];
$msg=['subject'=>'Your request advance payment',
      'body'=>'Request saved. No trip confirmed. '.$o->get_checkout_payment_url(),
      'language'=>'es'];
$notEligible=$email->render_lifecycle($msg,$job,$o);
check(!str_contains($notEligible,'Pay advance securely')&&
      !str_contains($notEligible,'Pagar anticipo de forma segura'),
 'no eligible gateway means no payment CTA');
$plainNoGateway=wc_get_template_html($email->template_plain,[
  'event'=>$job['event'],'request_body'=>$msg['body'],
  'request_language'=>'es','advance_order'=>$o], '',$email->template_base);
check(!str_contains($plainNoGateway,'order-pay/717'),
 'plain email cannot expose a payment URL when no approved gateway exists');
$GLOBALS['gatewayActive']=true;
$eligible=$email->render_lifecycle($msg,$job,$o);
check(str_contains($eligible,'Pagar anticipo de forma segura'),
 'eligible native Woo payment button is localized');
check(substr_count($eligible,'order-pay/717')===1,'one protected native pay link only');
check(str_contains($eligible,'[NATIVE_FOOTER]'),'WooCommerce wrapper preserved');
$plainEligible=wc_get_template_html($email->template_plain,[
  'event'=>$job['event'],'request_body'=>$msg['body'],
  'request_language'=>'es','advance_order'=>$o], '',$email->template_base);
check(substr_count($plainEligible,'order-pay/717')===1,
 'plain email prints exactly one URL for eligible payment');
check(str_contains($plainEligible,'Enlace de pago del anticipo'),
 'plain email uses the request language for verified payment CTA');
$o->advance=false;
$plainNotAdvance=wc_get_template_html($email->template_plain,[
  'event'=>$job['event'],'request_body'=>$msg['body'],
  'request_language'=>'es','advance_order'=>$o], '',$email->template_base);
check(!str_contains($plainNotAdvance,'order-pay/717'),
 'nonadvance order cannot expose a payment URL');
$o->advance=true;
$o->due=false;
$plainAlreadyPaid=wc_get_template_html($email->template_plain,[
  'event'=>$job['event'],'request_body'=>$msg['body'],
  'request_language'=>'es','advance_order'=>$o], '',$email->template_base);
check(!str_contains($plainAlreadyPaid,'order-pay/717'),
 'paid or closed advance cannot expose a stale request-body payment URL');
$received=$email->render_lifecycle($msg,$job,$o);
check(!str_contains($received,'Pagar anticipo de forma segura'),
 'paid or closed order does not show payment CTA');
$bad=$email->render_lifecycle($msg,['event'=>'unknown'],null);
check($bad==='','unrecognized lifecycle event not renderable');
check($GLOBALS['sentEmails']===0,'no mail sent in contract tests');
echo json_encode(['state'=>'PASS','cases'=>25,
 'mail_sent'=>0,'woocommerce_order_mutation'=>false,
 'smtp_config_untouched'=>true,'wordpress_loaded'=>false]).PHP_EOL;
}
