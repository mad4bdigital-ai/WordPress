<?php
/** Hermetic Provider Connections admin UI; no real provider network or credentials. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['admin']=true;$GLOBALS['registry']=[];
$GLOBALS['submenus']=[];$GLOBALS['discovery']=null;$GLOBALS['plans']=[];
$GLOBALS['discovered']=0;$GLOBALS['planned']=0;$GLOBALS['mutations']=0;$GLOBALS['coverage_render']=0;
class WP_Error{private $code;function __construct($code){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($hook,$cb,$priority=10,$n=1){$GLOBALS['wp_hooks'][]=$hook;}
function add_filter($hook,$cb,$priority=10,$n=1){$GLOBALS['wp_hooks'][]=$hook;}
function current_user_can($cap){return $cap==='manage_options'?$GLOBALS['admin']:false;}
function esc_html__($v,$domain=''){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function __($v,$domain=''){return $v;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function wp_die($message){throw new RuntimeException((string)$message);}
function get_option($name,$fallback=[]){return $fallback;}
function admin_url($uri=''){return 'https://example.test/wp-admin/'.$uri;}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function selected($a,$b,$echo=true){return $a===$b?'selected="selected"':'';}
function wp_nonce_field($name){echo '<input type="hidden" name="_wpnonce" value="test-fixture-nonce">';}
function submit_button($label){echo '<button type="submit">'.esc_html($label).'</button>';}
function wp_json_encode($data,$flags=0){return json_encode($data,$flags);}

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
 static function discover(){
  $GLOBALS['discovered']++;
  if(!empty($GLOBALS['discovery_exception']))throw new RuntimeException('private-provider-discovery-throw');
  return $GLOBALS['discovery'];
 }
 static function plan($input){
  $GLOBALS['planned']++;
  if(!empty($GLOBALS['plan_exception']))throw new RuntimeException('private-provider-plan-throw');
  return $GLOBALS['plans'][$input['provider']]??new WP_Error('plan_missing');
 }
}
class MAD4B_SCP_Provider_Setup_Coverage{
 static function render(){$GLOBALS['coverage_render']++;echo '<div class="provider-setup-fixture">Setup guidance only</div>';}
 static function render_custom_registration(){$GLOBALS['custom_render']=($GLOBALS['custom_render']??0)+1;echo '<div class="custom-provider-setup-fixture">Custom provider registration guidance only</div>';}
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
ck('original Firecrawl connection uses explicit owner POST with nonce, not automatic provider discovery mutation',
 strpos($html,'mad4b_firecrawl_save')!==false
 &&strpos($html,'_wpnonce')!==false
 &&strpos($html,'admin-post.php')!==false
 &&strpos($html,'<form')!==false
 &&$GLOBALS['mutations']===0);
ck('requires certified providers, no wildcard mutator',strpos($html,'No wildcard provider mutation authority')!==false);
ck('sensitive credential bytes never used',strpos($html,'secret-token')===false&&$GLOBALS['mutations']===0);
ck('original setup coverage remains advisory',$GLOBALS['coverage_render']===2);
$GLOBALS['admin']=new WP_Error('administrator_unverified');
$before=$GLOBALS['discovered'];
try { ob_start();$ui::render();ob_end_clean();$denied=false; }
catch(RuntimeException $e){if(ob_get_level()>0)ob_end_clean();$denied=true;}
ck('error-shaped WordPress administrator capability denies discovery and Firecrawl account controls',
 $denied&&$GLOBALS['discovered']===$before);
$GLOBALS['admin']=true;
$GLOBALS['discovery_exception']=true;
ob_start();$ui::render();$warn=ob_get_clean();
ck('original provider discovery exception cannot crash the page or imply an empty authorized catalog',
 strpos($warn,'No verified provider adapters')!==false);
$GLOBALS['discovery_exception']=false;
$GLOBALS['discovery']=['providers'=>[
 new WP_Error('malformed_row'),
 ['provider'=>new WP_Error('unknown_provider'),'label'=>'Unverified row','health_state'=>'ready'],
 ['provider'=>'valid','label'=>'Registered provider','health_state'=>'ready','owner_handoff_url'=>''],
 ['provider'=>'bad_type','label'=>new WP_Error('bad_label'),'health_state'=>'ready']
]];
$GLOBALS['plans']['valid']=['action'=>'refresh'];
ob_start();$ui::render();$rows=ob_get_clean();
ck('bad rows and WP_Error fields cannot crash the table or become eligible provider names',
 strpos($rows,'Registered provider')!==false
 &&strpos($rows,'Unverified row')===false
 &&strpos($rows,'bad_type')===false);
$GLOBALS['discovery']=['providers'=>[
 ['provider'=>'valid','label'=>'Registered provider','health_state'=>'ready','owner_handoff_url'=>'']
]];
$GLOBALS['plan_exception']=true;
ob_start();$ui::render();$plan_warning=ob_get_clean();
ck('unavailable native plan is reported as unavailable rather than crashing or fabricating action',
 strpos($plan_warning,'unavailable')!==false
 &&strpos($plan_warning,'Registered provider')!==false);
$GLOBALS['plan_exception']=false;
$GLOBALS['plans']['valid']=['action'=>new WP_Error('unknown_action')];
$GLOBALS['discovery']=['providers'=>[
 ['provider'=>'valid','label'=>'Registered provider','health_state'=>'needs_review',
 'owner_handoff_url'=>new WP_Error('unverified_handoff') ]
]];
ob_start();$ui::render();$plan_unknown=ob_get_clean();
ck('error-shaped provider plan and owner handoff never become clickable or report a verified action',
 strpos($plan_unknown,'unavailable')!==false
 &&strpos($plan_unknown,'No certified handoff available')!==false
 &&strpos($plan_unknown,'Registered provider')!==false);
$GLOBALS['plans']['valid']=['action'=>'refresh'];
$GLOBALS['discovery']=['providers'=>new WP_Error('malformed_list')];
ob_start();$ui::render();$warning=ob_get_clean();
ck('provider list error is a read-only warning, never a PHP foreach fatal',
 strpos($warning,'No verified provider adapters')!==false);
echo 'PASS: '.$n.' read-only Provider Connections UI safety cases'.PHP_EOL;
