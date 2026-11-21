<?php
class WP_Error {
    public $code;public $data;
    public function __construct($code,$msg,$data){$this->code=$code;$this->data=$data;}
}
class WP_REST_Request {
    private $payload;
    public function __construct($payload){$this->payload=$payload;}
    public function get_json_params(){return $this->payload;}
    public function get_param($key){return $this->payload[$key]??null;}
}
class WP_REST_Response {
    public $data;public $status;
    public function __construct($data,$status){$this->data=$data;$this->status=$status;}
}
class FakeWPDB {
    public $prefix='wp_';public $writes=[];
    public function prepare($sql,...$args){
        $this->writes[]=['query'=>$sql,'args'=>$args];
        return $sql;
    }
    public function query($sql){return 1;}
    public function get_results($sql,$format=null){
      if (str_contains($sql,'WHERE site_id=%s AND route_sha=%s')) {
         return [
          ['device'=>'mobile','arm'=>'native','metric'=>'lcp','bucket'=>4,'total'=>50],
          ['device'=>'mobile','arm'=>'native','metric'=>'inp','bucket'=>3,'total'=>20],
          ['device'=>'mobile','arm'=>'native','metric'=>'cls','bucket'=>4,'total'=>50],
          ['device'=>'mobile','arm'=>'optimized','metric'=>'lcp','bucket'=>3,'total'=>60],
          ['device'=>'mobile','arm'=>'optimized','metric'=>'css','bucket'=>1,'total'=>60]];
      }
      return [
       ['arm'=>'optimized','metric'=>'lcp','bucket'=>4,'total'=>20],
       ['arm'=>'optimized','metric'=>'lcp','bucket'=>5,'total'=>5],
       ['arm'=>'optimized','metric'=>'inp','bucket'=>2,'total'=>25],
       ['arm'=>'optimized','metric'=>'cls','bucket'=>4,'total'=>25],
       ['arm'=>'optimized','metric'=>'css','bucket'=>0,'total'=>1],
       ['arm'=>'optimized','metric'=>'css','bucket'=>1,'total'=>24]
      ];
    }
}
$wpdb=new FakeWPDB();
$GLOBALS['mock_transients']=[];
function get_transient($key){return $GLOBALS['mock_transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['mock_transients'][$key]=$value;}
function nocache_headers(){}
function current_user_can($cap){return !empty($GLOBALS['mock_admin_auth']);}
$_SERVER['HTTP_ORIGIN']='https://tours.dev.mad4b.com';
$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
$_SERVER['CONTENT_LENGTH']='260';
$cls='MAD4B_Adaptive_CSS_RUM_V1';
$fields=['source',str_repeat('a',64),str_repeat('b',64),'mobile','14','0','0','control'];
$token=$cls::signed_proof($fields,time());
$valid=['contract'=>$cls::CONTRACT,'proof'=>$token,'arm'=>'optimized',
        'metrics'=>['lcp'=>2500,'inp'=>130,'cls'=>37],'cssRestored'=>true];
$response=$cls::record(new WP_REST_Request($valid));
check($response instanceof WP_REST_Response&&$response->status===202,
      'valid source-signed, same-origin metrics accepted into aggregate');
check(count($wpdb->writes)===4,'three vitals and CSS restoration aggregated');
check($wpdb->writes[0]['args'][1]===str_repeat('a',64),
      'no raw path or person can enter the aggregate scope');
$forged=$valid;$forged['proof']=$token.'X';
$bad=$cls::record(new WP_REST_Request($forged));
check($bad instanceof WP_Error && $bad->code==='rum_proof',
      'tampered token rejected before DB write');
$bad=$valid;$bad['metrics']['cls']=-3;
$result=$cls::record(new WP_REST_Request($bad));
check($result instanceof WP_Error && $result->code==='rum_metric',
      'malformed or negative data rejected');
for($i=0;$i<24;$i++)$cls::record(new WP_REST_Request($valid));
$result=$cls::record(new WP_REST_Request($valid));
check($result instanceof WP_Error && $result->code==='rum_throttled',
      'rapid repeated signed token throttled');
$_SERVER['HTTP_ORIGIN']='https://malicious.example';
$result=$cls::record(new WP_REST_Request($valid));
check($result instanceof WP_Error && $result->code==='rum_wrong_origin',
      'cross-site POST rejected regardless of token');
$cls::retention();
$health=$GLOBALS['capturedOptionValue']??null;
check(($GLOBALS['capturedOptionKey']??'')==='mad4b_css_rum_health_v1'
  &&($health['health_status']??null)==='shadow_monitoring',
  'site-owned scheduled RUM health persists without public CSS changes');
check(($health['vitals']['optimized']['lcp']['p75_upper']??null)===2500
  &&($health['vitals']['optimized']['lcp']['samples']??null)===25,
  'p75 and 25 total LCP samples read correctly from histogram rows');
check(($health['public_rollout_percent_changed']??null)===false
  &&($health['performance_warning_is_not_automatic_certificate_authority']??null)===true,
  'site RUM cannot automatically issue or promote unqualified CSS');
$req=new WP_REST_Request(['route_sha'=>str_repeat('a',64),
                           'variant_sha'=>str_repeat('b',64)]);
$GLOBALS['mock_admin_auth']=false;
$r=$cls::cohort_readback($req);
check($r instanceof WP_Error && $r->code==='rum_forbidden',
      'per-cohort RUM readback requires an actual authorized manager');
$GLOBALS['mock_admin_auth']=true;
$r=$cls::cohort_readback($req);
check($r instanceof WP_REST_Response && $r->status===200
  &&($r->data['data']['mobile']['native']['lcp']['p75_upper']??null)===2500
  &&($r->data['data']['mobile']['optimized']['full_css_restored']['passed']??null)===60,
  'admin gets exact source-device arm histogram p75 without PII');
check(($r->data['eligible_to_promote_css']??true)===false
  &&($r->data['arm_source']??null)==='client_dom_observed_not_randomized',
  'observational client arm is not treated as randomized canary evidence');
$r=$cls::cohort_readback(new WP_REST_Request(['route_sha'=>'bad','variant_sha'=>str_repeat('b',64)]));
check($r instanceof WP_Error && $r->code==='rum_bad_cohort',
      'invalid source variants rejected before SQL');
echo 'PASS 14 endpoint, health and admin-cohort contracts'.PHP_EOL;
?>
