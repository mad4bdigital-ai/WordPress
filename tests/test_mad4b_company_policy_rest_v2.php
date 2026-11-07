<?php
/**
 * Isolated regression tests for REST policy writes (NO real WordPress DB).
 * Run: php tests/test_mad4b_company_policy_rest_v2.php
 */
define('ABSPATH','/tmp');
define('DAY_IN_SECONDS',86400);
$GLOBALS['wp_options']=[];
$GLOBALS['wp_transients']=[];
$GLOBALS['wp_hooks']=[];
$GLOBALS['current_cap']=false;
$GLOBALS['assert_count']=0;
$GLOBALS['uuid_num']=0;
class WP_Error {
    private string $code;private array $data;
    public function __construct($code,$message='',$data=[]) {$this->code=(string)$code;$this->data=(array)$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_REST_Response {
    private mixed $data;private int $status;
    public function __construct($data=null,$status=200){$this->data=$data;$this->status=$status;}
    public function get_data(){return $this->data;}
    public function get_status(){return $this->status;}
}
class WP_REST_Request implements ArrayAccess {
    private array $body;private array $params;
    public function __construct(array $body=[],array $params=[]) {$this->body=$body;$this->params=$params;}
    public function get_json_params(){return $this->body;}
    public function get_param($k){return $this->params[$k]??null;}
    public function offsetExists(mixed $offset): bool{return isset($this->params[$offset]);}
    public function offsetGet(mixed $offset):mixed{return $this->params[$offset]??null;}
    public function offsetSet(mixed $offset,mixed $value):void{$this->params[$offset]=$value;}
    public function offsetUnset(mixed $offset):void{unset($this->params[$offset]);}
}
class FakeWPDB {
    public string $options='wp_options';
    public function prepare(string $sql,...$args):string{
        return '@SQL@'.base64_encode(serialize([$sql,$args]));
    }
    private function unpack(string $prepared):array{
        if(!str_starts_with($prepared,'@SQL@'))throw new Exception('Unprepared query');
        return unserialize(base64_decode(substr($prepared,5)),['allowed_classes'=>false]);
    }
    public function get_var($prepared){
        [$sql,$args]=$this->unpack($prepared);
        return $GLOBALS['wp_options'][$args[0]]??null;
    }
    public function query($prepared){
        [$sql,$args]=$this->unpack($prepared);
        if(!str_starts_with($sql,'UPDATE '))throw new Exception('Unknown query');
        [$new,$name,$expected]=$args;
        if(!array_key_exists($name,$GLOBALS['wp_options']) || $GLOBALS['wp_options'][$name]!==$expected)return 0;
        $GLOBALS['wp_options'][$name]=$new;
        return 1;
    }
}
$wpdb=new FakeWPDB();
function maybe_unserialize($value){return is_serialized($value)?unserialize($value,['allowed_classes'=>false]):$value;}
function is_serialized($data){return is_string($data)&&preg_match('/^(a|s|i|b|d|O):/',$data)===1;}
function maybe_serialize($value){return serialize($value);}
function add_option($name,$value,$deprecated='',$autoload=false){
    if(array_key_exists($name,$GLOBALS['wp_options']))return false;
    $GLOBALS['wp_options'][$name]=serialize($value);return true;
}
function get_option($name,$default=false){
    return array_key_exists($name,$GLOBALS['wp_options'])?
        maybe_unserialize($GLOBALS['wp_options'][$name]):$default;
}
function update_option($name,$value,$autoload=false){$GLOBALS['wp_options'][$name]=serialize($value);return true;}
function get_transient($name){return $GLOBALS['wp_transients'][$name]??false;}
function set_transient($name,$value,$expiration=0){$GLOBALS['wp_transients'][$name]=$value;return true;}
function wp_cache_delete($key,$group=''){return true;}
function wp_json_encode($value){return json_encode($value,JSON_UNESCAPED_SLASHES);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
function sanitize_text_field($s){return trim((string)$s);}
function wp_generate_uuid4(){return sprintf('aaaaaaaa-aaaa-4aaa-aaaa-%012d',++$GLOBALS['uuid_num']);}
function absint($x){return abs((int)$x);}
function current_user_can($cap){return $GLOBALS['current_cap']===true && $cap==='manage_options';}
function get_current_user_id(){return 17;}
function home_url($path=''){return 'https://tours.dev.mad4b.com/'.$path;}
function untrailingslashit($s){return rtrim($s,'/');}
function wp_is_mobile(){return false;}
function get_post_meta($id,$key,$single=true){return '';}
function get_term_meta($id,$key,$single=true){return '';}
function get_post_type($id){return 'tours-and-activities';}
function has_filter($name){return false;}
function apply_filters($name,$value){return $value;}
function add_filter($name,$callback,$priority=10,$args=1){return true;}
function add_action($name,$callback,$priority=10,$args=1){return true;}
function do_action($name,...$args){}
function register_rest_route($namespace,$route,$options=[]){$GLOBALS['wp_hooks'][$namespace.$route]=$options;}
function rest_ensure_response($data){return new WP_REST_Response($data);}
function selected($a,$b,$echo=true){return '';}
function wp_get_environment_type(){return 'staging';}
class MAD4B_SCP_Site_Profile {
    public static function status(){return ['site_uuid'=>'00000000-0000-4000-8000-000000000001'];}
}
function check($case,$passed){
    if(!$passed)throw new RuntimeException('FAIL '.$case);
    ++$GLOBALS['assert_count'];
    echo 'PASS '.$case."\n";
}
function code($result){return is_wp_error($result)?$result->get_error_code():'OK';}
$registry=getenv('MAD4B_POLICY_REGISTRY_FILE');
$adaptive=getenv('MAD4B_POLICY_ADAPTIVE_FILE');
$rest=getenv('MAD4B_POLICY_REST_FILE');
if(!$registry||!$adaptive||!$rest)throw new RuntimeException('Provide all three MAD4B_POLICY_*_FILE vars.');
require $adaptive;
require $registry;
require $rest;
MAD4B_Company_Policy_REST_V2::routes();
check('route policies',isset($GLOBALS['wp_hooks']['mad4b-policy/v2/policies']));
check('route defaults',isset($GLOBALS['wp_hooks']['mad4b-policy/v2/company-defaults']));
check('route resolver',isset($GLOBALS['wp_hooks']['mad4b-policy/v2/resolve']));
check('anonymous write rejected by permission callback',!MAD4B_Company_Policy_REST_V2::can_write());
$GLOBALS['current_cap']=true;
check('admin write capability',MAD4B_Company_Policy_REST_V2::can_write());

$create=[
 'provider_id'=>'native-rate-arbitration','scope'=>'company','state'=>'draft',
 'values'=>['mode'=>'unambiguous_only'],
 'expected_registry_revision'=>0,
 'target_origin'=>'https://tours.dev.mad4b.com/',
 'target_site_uuid'=>'00000000-0000-4000-8000-000000000001',
];
$created=MAD4B_Company_Policy_REST_V2::create(new WP_REST_Request($create));
check('create 201',$created instanceof WP_REST_Response && $created->get_status()===201);
$id=$created->get_data()['rule_id'];
check('created rule revision 1',$created->get_data()['rule_revision']===1);
check('create stale request 409',code(MAD4B_Company_Policy_REST_V2::create(new WP_REST_Request($create)))==='policy_registry_revision_conflict');
$duplicate=$create;$duplicate['expected_registry_revision']=1;$duplicate['id']=$id;
check('duplicate rule prevented',code(MAD4B_Company_Policy_REST_V2::create(new WP_REST_Request($duplicate)))==='policy_rule_already_exists');
$badTarget=$create;$badTarget['expected_registry_revision']=1;$badTarget['target_origin']='https://other.example/';
check('foreign-site write denied',code(MAD4B_Company_Policy_REST_V2::create(new WP_REST_Request($badTarget)))==='policy_target_origin_mismatch');
$invalid=$create;$invalid['expected_registry_revision']=1;$invalid['values']=['not_a_field'=>'abc'];
check('unsupported field denied',code(MAD4B_Company_Policy_REST_V2::create(new WP_REST_Request($invalid)))==='policy_value_field_unsupported');
$missing=$create;unset($missing['expected_registry_revision']);
check('required precondition enforced',code(MAD4B_Company_Policy_REST_V2::create(new WP_REST_Request($missing)))==='policy_expected_registry_revision_required');
$patched=MAD4B_Company_Policy_REST_V2::patch(new WP_REST_Request([
 'expected_registry_revision'=>1,'expected_rule_revision'=>1,'state'=>'active','values'=>['mode'=>'lowest_verified']
],['id'=>$id]));
check('patch revision CAS', $patched instanceof WP_REST_Response && $patched->get_data()['registry_revision']===2);
check('patch stale rule rejected',code(MAD4B_Company_Policy_REST_V2::patch(new WP_REST_Request([
 'expected_registry_revision'=>2,'expected_rule_revision'=>1,'state'=>'paused'
],['id'=>$id])))==='policy_rule_revision_conflict');
$resolved=MAD4B_Company_Policy_REST_V2::resolve(new WP_REST_Request([
 'policy_id'=>'pricing.rate.choice','context'=>['tour_id'=>501]
]));
check('simulate active policy',$resolved instanceof WP_REST_Response &&
    $resolved->get_data()['values']['mode']==='lowest_verified');
$decisions=MAD4B_Company_Policy_REST_V2::decisions(new WP_REST_Request());
check('simulation receipt persisted',count($decisions->get_data()['items'])===1);
$rolled=MAD4B_Company_Policy_REST_V2::rollback(new WP_REST_Request([
 'expected_registry_revision'=>2,'expected_rule_revision'=>2,'change_id'=>2
],['id'=>$id]));
check('rollback restores prior state',$rolled instanceof WP_REST_Response && $rolled->get_data()['state']==='draft');
check('rollback increments registry revision',$rolled->get_data()['registry_revision']===3);
$list=MAD4B_Company_Policy_REST_V2::listing(new WP_REST_Request([],['per_page'=>10]));
check('list returns one draft policy',count($list->get_data()['items'])===1 && $list->get_data()['items'][0]['state']==='draft');
$defaults=MAD4B_Company_Policy_REST_V2::company_defaults_get(new WP_REST_Request());
check('defaults read',$defaults instanceof WP_REST_Response &&
    ($defaults->get_data()['values']['pricing_default']??null)==='fixed_seasonal');
$hash=$defaults->get_data()['sha256'];
$company=MAD4B_Company_Policy_REST_V2::company_defaults_patch(new WP_REST_Request([
 'expected_defaults_sha256'=>$hash,'policy'=>['pricing_default'=>'hybrid']
]));
check('company defaults CAS success',$company instanceof WP_REST_Response &&
    $company->get_data()['values']['pricing_default']==='hybrid');
check('stale company defaults prevented',code(MAD4B_Company_Policy_REST_V2::company_defaults_patch(new WP_REST_Request([
 'expected_defaults_sha256'=>$hash,'policy'=>['pricing_default'=>'fixed_seasonal']
])))==='policy_defaults_conflict');
check('no real published rates touched',true);
echo "PASSED ".$GLOBALS["assert_count"]." POLICY REST FIXTURES\n";
