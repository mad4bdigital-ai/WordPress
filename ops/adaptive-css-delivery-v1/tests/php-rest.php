<?php
class WP_Error {
    public $code;public $data;
    public function __construct($code,$msg,$data){$this->code=$code;$this->data=$data;}
}
class WP_REST_Request {
    private $payload;
    public function __construct($payload){$this->payload=$payload;}
    public function get_json_params(){return $this->payload;}
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
}
$wpdb=new FakeWPDB();
$GLOBALS['mock_transients']=[];
function get_transient($key){return $GLOBALS['mock_transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['mock_transients'][$key]=$value;}
function nocache_headers(){}
$_SERVER['HTTP_ORIGIN']='https://tours.dev.mad4b.com';
$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
$_SERVER['CONTENT_LENGTH']='260';
$cls='MAD4B_Adaptive_CSS_RUM_V1';
$fields=['source',str_repeat('a',64),str_repeat('b',64),'mobile','14'];
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
echo 'PASS 7 endpoint, integrity and minimum-data contract checks'.PHP_EOL;
?>
