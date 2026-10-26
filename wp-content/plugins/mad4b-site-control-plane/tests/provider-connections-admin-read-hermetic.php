<?php
/** Hermetic Provider Connections admin UI; no real provider network or credentials. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['admin']=true;$GLOBALS['registry']=[];
$GLOBALS['submenus']=[];$GLOBALS['discovery']=null;$GLOBALS['plans']=[];
$GLOBALS['discovered']=0;$GLOBALS['planned']=0;$GLOBALS['mutations']=0;$GLOBALS['coverage_render']=0;
class WP_Error{private $code;function __construct($code){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return $cap==='manage_options'&&$GLOBALS['admin'];}
function esc_html__($v,$domain=''){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function __($v,$domain=''){return $v;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function wp_die($message){throw new RuntimeException((string)$message);}
function add_submenu_page($parent,$page_title,$menu_title,$capability,$slug,$callback){
 $GLOBALS['submenus'][$slug]=['parent'=>$parent,'cap'=>$capability,'callback'=>$callback];
}
class MAD4B_SCP_Admin_Route_Registry{
 static function register($slug,$cap){$GLOBALS['registry'][]=['slug'=>$slug,'cap'=>$cap];}
 static function schedule_submenu($callback,$priority){$GLOBALS['registry'][]=['menu'=>$callback,'priority'=>$priority];}
}
class MAD4B_SCP_Admin_Workspace{
 static function link($slug,$params=[]){return 'https://example.test/wp-admin/options.php?tab=native-peers&ref=1';}
}
class MAD4B_SCP_Provider_Connection_Center{
 static function discover(){$GLOBALS['discovered']++;return $GLOBALS['discovery'];}
 static function plan($input){$GLOBALS['planned']++;return $GLOBALS['plans'][$input['provider']]??new WP_Error('plan_missing');}
}
class MAD4B_SCP_Provider_Setup_Coverage{
 static function render(){$GLOBALS['coverage_render']++;echo '<div class="provider-setup-fixture">Setup guidance only</div>';}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-connections-admin-ui.php';
$n=0;
function ck($label,$pass){global $n;if(!$pass){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}$n++;}
$ui='MAD4B_SCP_Provider_Connections_Admin_UI';
ck('route registered read only',count($GLOBALS['registry'])===2);
ck('route uses manage_options only',$GLOBALS['registry'][0]['cap']==='manage_options');
$ui::boot();ck('second boot no duplicate registration',count($GLOBALS['registry'])===2);
$ui::menu();
ck('submenu delegated to original Control Plane',
 isset($GLOBALS['submenus'][$ui::PAGE_SLUG])&&$GLOBALS['submenus'][$ui::PAGE_SLUG]['parent']==='mad4b-control-plane');
$GLOBALS['admin']=false;
try{$ui::render();$rejected=false;}catch(RuntimeException $e){$rejected=true;}
ck('unauthorized WP user blocked',$rejected);
ck('denial occurred before any discovery',$GLOBALS['discovered']===0);
$GLOBALS['admin']=true;
$GLOBALS['discovery']=new WP_Error('no_sources');
ob_start();$ui::render();$none=ob_get_clean();
ck('provider error rendered as read-only warning',strpos($none,'No verified provider adapters')!==false);
ck('even empty inventory offers setup only',strpos($none,'provider-setup-fixture')!==false);
ck('no mutation on unavailable provider',$GLOBALS['mutations']===0);
$GLOBALS['discovery']=['providers'=>[
 ['provider'=>'drive','label'=>'Drive <script>alert(1)</script>','health_state'=>'refresh_required',
  'owner_handoff_url'=>'https://example.test/connect?x=1&y=2'],
 ['provider'=>'oauth','label'=>'OAuth provider','health_state'=>'reconnect_required',
  'owner_handoff_url'=>'https://example.test/go'],
 ['provider'=>'revoked','label'=>'Revoked provider','health_state'=>'revocation_pending',
  'owner_handoff_url'=>''],
 ]];
$GLOBALS['plans']=[
 'drive'=>['action'=>'refresh'],
 'oauth'=>['action'=>'oauth_handoff'],
 'revoked'=>['action'=>'resolve_pending_revocation']
];
ob_start();$ui::render();$html=ob_get_clean();
ck('three original providers in read-only table',strpos($html,'Provider Connections')!==false&&$GLOBALS['planned']===3);
ck('untrusted provider title escaped',strpos($html,'<script>alert(1)</script>')===false &&strpos($html,'&lt;script&gt;')!==false);
ck('provider handoff URL safely encoded',strpos($html,'connect?x=1&amp;y=2')!==false);
ck('refresh note requires separate ticket',strpos($html,'separate exact owner approval ticket')!==false);
ck('oauth handoff requires owner browser',strpos($html,'provider browser')!==false);
ck('pending revocation cannot reconnect',strpos($html,'Resolve the pending provider revocation first')!==false);
ck('missing handoff explicitly displayed',strpos($html,'No certified handoff available')!==false);
ck('UI never emits auto-submit buttons for mutations',strpos($html,'<form')===false&&strpos($html,'method="post"')===false);
ck('requires certified providers, no wildcard mutator',strpos($html,'No wildcard provider mutation authority')!==false);
ck('sensitive credential bytes never used',strpos($html,'secret-token')===false&&$GLOBALS['mutations']===0);
ck('original setup coverage remains advisory',$GLOBALS['coverage_render']===2);
echo 'PASS: '.$n.' read-only Provider Connections UI safety cases'.PHP_EOL;
