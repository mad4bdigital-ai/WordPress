<?php
/** No database, Woo network, real checkout or mail transport. */
namespace Commerce\Composition {
 class RequestAdvancePayments {
  public static function isAdvance($order):bool {return $order instanceof \WC_Order && $order->advance;}
 }
 class FluentBoardsTaskBridge {
  public static function taskForSubmission(int $fid,int $sid):int {
   return $fid===6&&$sid===313?123:0;
  }
 }
}
namespace FluentBoards\App\Models {
 class Task {
  public int $board_id=7;
  public static function find(int $id){return $id===123?new self():null;}
  public function updateMeta(string $key,array $value):void {
   $GLOBALS['boardUpdates'][]=['key'=>$key,'value'=>$value];
  }
 }
}
namespace {
define('ABSPATH','/offline');
class WP_Error {
 private string $code;
 public function __construct($code,$msg=''){ $this->code=$code; }
 public function get_error_code(){return $this->code;}
}
class WC_Order {
 public bool $advance=true;
 public bool $needsPayment=true;
 public array $meta=[];
 public int $saved=0;
 public $created;
 public $paid=null;
 public array $notes=[];
 public function __construct(public int $id=717){
  $this->created=new \DateTimeImmutable('2026-11-11T05:00:00Z');
  $this->meta=['_request_source_id'=>99,'_request_rate_id'=>101,
               '_request_form_id'=>6,'_request_submission_id'=>313];
 }
 public function get_id(){return $this->id;}
 public function get_meta($k){return $this->meta[$k]??null;}
 public function update_meta_data($k,$v){$this->meta[$k]=$v;}
 public function save(){$this->saved++;}
 public function get_date_created($mode='edit'){return $this->created;}
 public function get_date_paid($mode='edit'){return $this->paid;}
 public function needs_payment(){return $this->needsPayment;}
 public function get_total_refunded(){return $GLOBALS['refunded'];}
 public function get_total(){return 100.00;}
 public function add_order_note($note,$customer=false,$addedBy=false){$this->notes[]=$note;}
}
class MAD4B_Tour_Form6_Submission_Mode_V1 {
 public static function inspect($id){return ['mode'=>$GLOBALS['mode']];}
}
$GLOBALS['mode']='advance_checkout';
$GLOBALS['refunded']=0.0;
$GLOBALS['companySettings']=[];
$GLOBALS['postOverrides']=[];
$GLOBALS['userId']=10;
$GLOBALS['capability']=false;
$GLOBALS['events']=[];
$GLOBALS['boardUpdates']=[];
$GLOBALS['order']=new WC_Order();
class FakeDb {
 public bool $lockAllowed=true;
 public int $locks=0;
 public int $releases=0;
 public function prepare(string $sql,...$args):string{return $sql;}
 public function get_var(string $sql):int {
  if(str_contains($sql,'GET_LOCK')){$this->locks++;return $this->lockAllowed?1:0;}
  if(str_contains($sql,'RELEASE_LOCK')){$this->releases++;return 1;}
  return 0;
 }
}
$GLOBALS['wpdb']=new FakeDb();
function add_filter(...$args){}
function add_action(...$args){}
function get_option($key,$default=null){
 return $key==='mad4b_early_payment_benefits_v1'?$GLOBALS['companySettings']
   :($key==='siteurl'?'https://example.test'
   :($key==='commerce_request_review_board'?['board_id'=>7]:$default));
}
function sanitize_text_field($s){return trim((string)$s);}
function sanitize_textarea_field($s){return trim((string)$s);}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_post_type($id){
 return $id===99?'tours-and-activities':($id===101?'tour-rates':null);
}
function get_post_meta($id,$key,$single=true){return $GLOBALS['postOverrides'][$id][$key]??null;}
function apply_filters($name,$value,...$args){
 if($name==='wpml_default_language')return 'en';
 if($name==='wpml_object_id')return $value;
 return $value;
}
function trailingslashit($s){return rtrim($s,'/').'/';}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE);}
function absint($x){return abs((int)$x);}
function wc_get_order($id){return $id===$GLOBALS['order']->id?$GLOBALS['order']:null;}
function get_current_user_id(){return $GLOBALS['userId'];}
function current_user_can($k){return $GLOBALS['capability'];}
function do_action($name,...$args){$GLOBALS['events'][]=$name;}
function check($ok,$label){if(!$ok){fwrite(STDERR,"FAIL ".$label.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-early-payment-benefits-v1.php';
$cls=\MAD4B_Early_Payment_Benefits_V1::class;
$off=$cls::policy(99,101);
check($off['active']===false,'default disabled');
check($off['enabled']==='no','stored default off');
$advanced=$cls::extendAdvancePolicy([],99,101);
check($advanced['early_payment_benefits']['active']===false,'no benefit active by default');
check($advanced['early_payment_benefits']['monetary_discount']===false,'no monetary discount');
$invalid=$cls::validate(['type'=>'percentage','enabled'=>'yes']);
check(is_wp_error($invalid)&&$invalid->get_error_code()==='early_payment_type_not_supported',
 'unexpected monetary benefit rejected');
$invalid=$cls::validate(['window_hours'=>'721']);
check(is_wp_error($invalid),'window beyond 720 hours rejected');
$invalid=$cls::validate(['enabled'=>'yes']);
check(is_wp_error($invalid)&&$invalid->get_error_code()==='early_payment_english_copy_required',
 'enabled offer without copy denied');
$invalid=$cls::validate(['benefit_id'=>'invalid benefit']);
check(is_wp_error($invalid)&&$invalid->get_error_code()==='early_payment_invalid_benefit_id',
 'benefit code must be safe');
$GLOBALS['postOverrides'][101][$cls::META]=['mode'=>'on'];
check($cls::policy(99,101)['active']===false,
 'Tour/Rate on override cannot bypass company global kill switch');
$GLOBALS['companySettings']=[
 'enabled'=>'yes','short_en'=>'Priority review by your travel specialist',
 'details_en'=>'Our travel team reviews your itinerary sooner.',
 'window_hours'=>48,'type'=>'service','benefit_id'=>'priority_review'
];
$p=$cls::policy(99,101);
check($p['active']===true,'fully configured company service can activate');
check($p['window_hours']===48,'48-hour window survives');
$GLOBALS['postOverrides'][101][$cls::META]=['mode'=>'off'];
check($cls::policy(99,101)['active']===false,'Rate can turn off company benefit');
$GLOBALS['postOverrides'][101][$cls::META]=['mode'=>'inherit'];
check($cls::policy(99,101)['active']===true,'Rate inherit company policy');
$o=$GLOBALS['order'];
$s=$cls::snapshot($o);
check(is_array($s)&&$s['order_id']===717,'advance order snapshot freezes correct order');
check($s['company_origin']==='https://example.test/','WPML-independent tenant origin');
check($s['booking_confirmed']===false,'snapshot is not booking confirmation');
check($s['monetary_discount']===false,'snapshot is not discount');
check($s['expires_at']-$s['issued_at']===48*3600,'expiry determined by order creation');
check(count($s['copy'])===6,'six customer languages available');
check($s['copy']['es']['short']===$s['copy']['en']['short'],
 'unconfigured locale safely uses English company copy');
$cls::created(717,6,313);
check($o->saved===1,'one matched source advance creates immutable benefit');
check($o->get_meta($cls::ORDER_META)['fingerprint']===$s['fingerprint'],
 'immutable order benefit snapshot persisted once');
$cls::created(717,6,313);
check($o->saved===1,'repeated source event cannot create duplicate snapshot');
$offer=$cls::offer($o);
check(is_array($offer)&&$offer['fingerprint']===$s['fingerprint'],
 'verified active source-order offer is rendered without new booking');
$GLOBALS['mode']='quote_only';
check($cls::offer($o)===null,'quote-only mode refuses payment-enabled benefit display');
$GLOBALS['mode']='advance_checkout';
$invalidSnapshot=$o->get_meta($cls::ORDER_META);
$invalidSnapshot['source_id']=202;
$o->update_meta_data($cls::ORDER_META,$invalidSnapshot);
check($cls::offer($o,false)===null,'forged order source cannot unlock benefit');
$o->update_meta_data($cls::ORDER_META,$s);
check($cls::decidePayment($s,$s['issued_at']+100,true)==='qualified_for_service',
 'paying before cutoff qualifies service');
check($cls::decidePayment($s,$s['expires_at']+1,true)==='expired',
 'payment after cutoff does not qualify');
check($cls::decidePayment($s,$s['issued_at']+1,false)==='disabled_before_payment',
 'company disabling benefit before payment cancels qualification');
check($cls::decidePayment($s,0,true)==='payment_unverified',
 'unknown payment must not qualify');
check($cls::refundState(['state'=>'qualified_for_service'],false)==='qualified_for_service',
 'partial refund keeps current state');
check($cls::refundState(['state'=>'qualified_for_service'],true)==='reversed_after_refund',
 'full refund cancels service qualification');
check($cls::refundState(['state'=>'fulfilled'],true)==='reversed_after_refund',
 'full refund after delivery requires reversal review');
check($cls::refundState(['state'=>'expired'],true)==='expired',
 'full refund cannot manufacture new state for expired offer');
$claim=$cls::claimState($o);
check($claim['state']==='offered','new advance starts unqualified');
$o->update_meta_data($cls::STATE_META,['state'=>'qualified_for_service']);
check($cls::claimState($o)['state']==='qualified_for_service','claim separate from immutable offer');
$out=$cls::fulfill(717,10);
check(is_wp_error($out)&&$out->get_error_code()==='early_benefit_fulfilment_permission_denied',
 'fulfilment requires explicit operations permission');
check(count($GLOBALS['events'])===0,'denied fulfilment cannot trigger lifecycle');
$GLOBALS['capability']=true;
$GLOBALS['wpdb']->lockAllowed=false;
$out=$cls::fulfill(717,10);
check(is_wp_error($out)&&$out->get_error_code()==='early_benefit_fulfilment_busy',
 'concurrent fulfilment blocked by nonblocking per-order lock');
check($cls::claimState($o)['state']==='qualified_for_service',
 'busy lock leaves claim unchanged');
$GLOBALS['wpdb']->lockAllowed=true;
$out=$cls::fulfill(717,10);
check(is_wp_error($out)&&$out->get_error_code()==='early_benefit_not_fulfillable',
 'no verified payment prevents manual service fulfilment');
$o->paid=new \DateTimeImmutable('2026-11-11T06:00:00Z');
$o->update_meta_data($cls::STATE_META,[
 'state'=>'qualified_for_service','task_id'=>123,
 'offer_fingerprint'=>$s['fingerprint']]);
$out=$cls::fulfill(717,10);
check(is_array($out)&&($out['status']??'')==='fulfilled',
 'authorized paid advance can fulfil service exactly once');
check($cls::claimState($o)['state']==='fulfilled',
 'claim journal state advanced independently of immutable offer');
check($o->get_meta($cls::ORDER_META)['fingerprint']===$s['fingerprint'],
 'manual service fulfilment never mutates accepted offer terms');
check($GLOBALS['wpdb']->releases===2,
 'successful and denied operations release their advisory locks');
check(count($GLOBALS['boardUpdates'])===1
 && $GLOBALS['boardUpdates'][0]['value']['state']==='fulfilled',
 'already-linked verified native Fluent Boards task updated');
check($GLOBALS['boardUpdates'][0]['value']['booking_confirmed']===false,
 'task metadata cannot confirm booking');
$notes=count($o->notes);
$out=$cls::fulfill(717,10);
check(is_array($out)&&($out['status']??'')==='already_fulfilled'
  && count($o->notes)===$notes && count($GLOBALS['boardUpdates'])===1,
 'duplicate fulfilment does not duplicate note or board update');
$GLOBALS['refunded']=50;
$cls::refunded(717);
check($cls::claimState($o)['state']==='fulfilled',
 'partial refund does not reverse a delivered benefit');
$GLOBALS['refunded']=100;
$cls::refunded(717);
check($cls::claimState($o)['state']==='reversed_after_refund',
 'full refund moves delivered service to reversal review');
check(count($GLOBALS['boardUpdates'])===2
 && $GLOBALS['boardUpdates'][1]['value']['reversal_action']==='review_delivered_service',
 'native board task receives correctly scoped reversal review');
$notes=count($o->notes);
$cls::refunded(717);
check(count($o->notes)===$notes && count($GLOBALS['boardUpdates'])===2,
 'duplicate refund notification does not produce duplicate side effects');
check(in_array('mad4b_early_payment_benefit_fulfilled',$GLOBALS['events'],true)
 && in_array('mad4b_early_payment_benefit_reversed',$GLOBALS['events'],true),
 'only verified in-memory state transitions fire local stubbed lifecycle events');
echo json_encode(['state'=>'PASS','cases'=>51,
 'checkout_authorized'=>false,'monetary_discount'=>false,
 'network_calls'=>0,'wordpress_runtime_loaded'=>false]).PHP_EOL;
}
