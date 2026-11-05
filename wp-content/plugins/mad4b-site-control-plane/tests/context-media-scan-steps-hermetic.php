<?php
declare(strict_types=1);
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private string $code;private string $message;private array $data;
    public function __construct(string $code,string $message='',array $data=[]) {
        $this->code=$code;$this->message=$message;$this->data=$data;
    }
    public function get_error_code():string{return $this->code;}
    public function get_error_message():string{return $this->message;}
    public function get_error_data():array{return $this->data;}
}
function is_wp_error($x):bool{return $x instanceof WP_Error;}
function add_action(...$args):void{}
function current_user_can(string $cap):bool{return in_array($cap,['manage_options','upload_files'],true);}
function get_current_user_id():int{return $GLOBALS['test_user']??1;}
function home_url(string $path=''):string{return 'https://staging.example.test'.$path;}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$ttl=0):bool{$GLOBALS['transients'][$key]=$value;return true;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
if(!defined('MINUTE_IN_SECONDS'))define('MINUTE_IN_SECONDS',60);
class MAD4B_SCP_Context_Authority {
    public static function upsert_source($args){return ['source_id'=>str_repeat('a',64)];}
}
class MAD4B_SCP_Policy{public static function can_connect_user($id):bool{return $id===1;}}
class MAD4B_Test_FakeDB {
    public function prepare($query,$value){return str_replace('%s',"'".$value."'",$query);}
    public function get_var($q):int{return 1;}
}
$wpdb=new MAD4B_Test_FakeDB();
class MAD4B_SCP_Context_Source_Pipeline {
    public static array $calls=[];
    public static function scan_complete($source_id,$input) {
        $mime=$input['mime_types'][0]??'';
        self::$calls[]=$mime;
        if($mime==='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            return new WP_Error('mad4b_media_file_unavailable','Unreadable attachment',['attachment_id'=>66375]);
        return ['asset_count'=>$mime==='text/plain'?5:33,'complete'=>true,'content_complete'=>true,'assets'=>[]];
    }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-wordpress-media-context.php';
$checks=[];
$types=['text/plain','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/csv'];
$start=MAD4B_SCP_WordPress_Media_Context::begin_scan($types);
$checks['bounded_begin']=is_array($start)&&$start['total']===3;
$id=(string)$start['scan_session'];
$step1=MAD4B_SCP_WordPress_Media_Context::scan_step($id,0);
$step2=MAD4B_SCP_WordPress_Media_Context::scan_step($id,1);
$step3=MAD4B_SCP_WordPress_Media_Context::scan_step($id,2);
$checks['first_complete']=is_array($step1)&&($step1['last']['ok']??false)===true&&$step1['last']['count']===5;
$checks['failed_file_isolated']=is_array($step2)&&empty($step2['last']['ok'])&&$step2['last']['attachment_id']===66375;
$checks['third_continues']=is_array($step3)&&($step3['last']['ok']??false)===true&&$step3['last']['count']===33;
$checks['no_global_complete_on_error']=is_array($step3)&&empty($step3['complete'])&&!empty($step3['finished']);
$terminal=MAD4B_SCP_WordPress_Media_Context::scan_step($id,3);
$checks['completed_session_preserves_error_state']=is_array($terminal)
 && empty($terminal['complete']) && !empty($terminal['finished']) && !empty($terminal['has_errors']);
$checks['all_three_scopes_attempted']=MAD4B_SCP_Context_Source_Pipeline::$calls===$types;
$session=MAD4B_SCP_WordPress_Media_Context::scan_session($id);
$checks['checkpoint_has_errors']=is_array($session)&&$session['state']==='COMPLETE_WITH_ERRORS'&&count($session['results'])===3;
$retry=MAD4B_SCP_WordPress_Media_Context::scan_step($id,1);
$checks['stale_replay_refused']=is_wp_error($retry)&&$retry->get_error_code()==='mad4b_media_scan_step_stale';
$GLOBALS['test_user']=2;
$checks['cross_operator_refused']=MAD4B_SCP_WordPress_Media_Context::scan_session($id)===null;
$GLOBALS['test_user']=1;
$bad=MAD4B_SCP_WordPress_Media_Context::begin_scan(['image/jpeg']);
$checks['unsupported_mime_refused']=is_wp_error($bad)&&$bad->get_error_code()==='mad4b_media_inventory_scope_invalid';
foreach($checks as $n=>$ok)echo ($ok?'PASS':'FAIL').' '.$n."\n";
echo 'RESULT '.count(array_filter($checks)).'/'.count($checks)."\n";
exit(count(array_filter($checks))===count($checks)?0:1);
