<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_posts'] = array();
$GLOBALS['mock_next_id'] = 9;
$GLOBALS['mock_modified'] = 1;
$GLOBALS['mock_user'] = 1;
$GLOBALS['mock_caps'] = array('manage_options','edit_posts','publish_posts','create_posts');
$GLOBALS['mock_registered'] = array();
$GLOBALS['mock_configured'] = false;
$GLOBALS['mock_environment'] = 'staging';

class WP_Error {
    private $code;
    public function __construct($code, $message='', $data=null) {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_option($name,$default=false){return array_key_exists($name,$GLOBALS['mock_options'])?$GLOBALS['mock_options'][$name]:$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['mock_options'][$name]=$value;return true;}
function delete_option($name){unset($GLOBALS['mock_options'][$name]);return true;}
function get_current_user_id(){return $GLOBALS['mock_user'];}
function current_user_can($cap,$id=0){
    if ('edit_post' === $cap) return (int)$id > 0 && isset($GLOBALS['mock_posts'][$id])
        && in_array('edit_posts',$GLOBALS['mock_caps'],true);
    return in_array($cap,$GLOBALS['mock_caps'],true);
}
function is_user_logged_in(){return $GLOBALS['mock_user'] > 0;}
function absint($x){return abs((int)$x);}
function wp_register_ability($name,$args){$GLOBALS['mock_registered'][$name]=$args;return true;}
function wp_has_ability($name){return isset($GLOBALS['mock_registered'][$name]);}
function sanitize_text_field($x){return strip_tags($x);}
function wp_kses_post($x){return strip_tags($x,'<p><em><strong>');}
function wp_insert_post($args,$wp_error=false) {
    $id=$GLOBALS['mock_next_id']++;
    $GLOBALS['mock_posts'][$id]=(object)array(
        'ID'=>$id,'post_type'=>'post','post_title'=>$args['post_title'],
        'post_content'=>$args['post_content'],'post_status'=>$args['post_status'],
        'post_modified_gmt'=>'2026-10-22 00:00:01',
    );
    return $id;
}
function wp_update_post($args,$wp_error=false) {
    $post=$GLOBALS['mock_posts'][$args['ID']]??null;
    if(!$post) return new WP_Error('missing');
    if(isset($args['post_title']))$post->post_title=$args['post_title'];
    if(isset($args['post_content']))$post->post_content=$args['post_content'];
    $post->post_modified_gmt='2026-10-22 00:00:0'.(++$GLOBALS['mock_modified']);
    return $post->ID;
}
function get_post($id){return $GLOBALS['mock_posts'][$id]??null;}
function get_posts($args){return array_values($GLOBALS['mock_posts']);}
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static $bearer=true;
    public static $step_up=true;
    public static function verified_bearer_active(){ return self::$bearer; }
    public static function verified_bearer_has_scope($scope){
        return self::$step_up && $scope==='mad4b:authority:step-up';
    }
}
class MAD4B_SCP_Site_Profile {
    public static function configured(){return $GLOBALS['mock_configured'];}
    public static function current_environment(){return $GLOBALS['mock_environment'];}
}
class MAD4B_SCP_Policy {
    public static function can_connect_user($uid){return 1===$uid;}
}
function expect($ok,$description){if(!$ok)throw new RuntimeException($description);}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-direct-profile.php';
$c='MAD4B_SCP_Direct_Profile';
expect($c::default_mode()==='direct_authenticated','Fresh default must be Direct Authenticated');
$s=$c::state();
expect($s['mode']==='direct_authenticated'&&$s['revision']===0,'Fresh profile not correct');
expect(!array_key_exists($c::OPTION,$GLOBALS['mock_options']),'Read must not persist preferences');
expect($c::can_transport(),'Authenticated WP administrator should enter Direct');
$c::register_abilities();
foreach($c::tools() as $name)expect(isset($GLOBALS['mock_registered'][$name]),'Missing direct ability '.$name);
expect(count($c::tools())===5,'Unexpected direct tool count');
expect($c::SERVER==='mad4b-chatgpt','Direct must keep existing MCP server');
expect(count($c::catalog_tools())===5,'Fresh Direct must expose optional content tools');
$create=$GLOBALS['mock_registered'][$c::CREATE_POST];
expect($create['meta']['annotations']['readonly']===false,'Create ability is not marked write');
expect($create['meta']['annotations']['idempotent']===false,'Create cannot claim idempotency');
expect(empty($create['meta']['mcp']['mad4b_governed_write_authority']),'Direct tool must not inherit a privileged governed grant');
expect($create['permission_callback'](array('title'=>'Draft'))===true,'Permission should allow the WordPress author');
$post=$c::create_post(array('title'=>'Draft','content'=>'<p>hello</p>'));
expect(!is_wp_error($post)&&$post['id']===9&&$post['status']==='draft','Direct create failed');
$old=$post['modified_gmt'];
$stale=$c::update_post(array('post_id'=>9,'expected_modified_gmt'=>'bad','title'=>'Change'));
expect(is_wp_error($stale)&&$stale->get_error_code()==='mad4b_direct_update_stale','Concurrency guard missing');
$updated=$c::update_post(array('post_id'=>9,'expected_modified_gmt'=>$old,'title'=>'New Title'));
expect(!is_wp_error($updated)&&$updated['title']==='New Title','Direct update failed');
$read=$c::get_post(array('post_id'=>9));expect(!is_wp_error($read),'Direct read failed');
$rows=$c::list_posts(array('limit'=>10));expect(count($rows['items'])===1,'Direct list failed');
MAD4B_SCP_OAuth_Resource_Bridge::$step_up=false;
$denied_scope=$c::create_post(array('title'=>'No OAuth write scope'));
expect(is_wp_error($denied_scope) && $denied_scope->get_error_code()==='mad4b_direct_write_scope_required',
    'Read-scoped bearer cannot gain write merely by changing the site profile');
MAD4B_SCP_OAuth_Resource_Bridge::$step_up=true;
$GLOBALS['mock_caps']=array('manage_options','edit_posts','create_posts');
$publish=$c::create_post(array('title'=>'No Publish','status'=>'publish'));
expect(is_wp_error($publish)&&$publish->get_error_code()==='mad4b_direct_publish_denied','Publish capability not enforced');
$GLOBALS['mock_caps']=array('manage_options');
$deny=$c::create_post(array('title'=>'Denied'));
expect(is_wp_error($deny)&&$deny->get_error_code()==='mad4b_direct_content_capability','WP edit capability bypassed');
$GLOBALS['mock_caps']=array('manage_options','edit_posts','create_posts','publish_posts');
$GLOBALS['mock_user']=0;
expect(!$c::can_transport(),'Anonymous transport must not authenticate');
$anonymous=$c::create_post(array('title'=>'Unauthorized'));
expect(is_wp_error($anonymous),'Anonymous direct write must fail');
$GLOBALS['mock_user']=1;
$GLOBALS['mock_configured']=true;
expect($c::default_mode()==='governed','Existing enrolled site must not gain Direct on upgrade');
expect($c::state()['mode']==='governed'&&!$c::can_transport(),'Governed default must prevent direct transport');
expect(count($c::catalog_tools())===5,'Governed and Direct must have identical tool list');
$GLOBALS['mock_options'][$c::OPTION]=array('contract'=>$c::CONTRACT,'mode'=>'direct_authenticated','revision'=>1);
expect($c::state()['mode']==='direct_authenticated'&&$c::can_transport(),'Explicit opt-in must permit direct authenticated transport');
expect(count($c::catalog_tools())===5,'Explicit Direct must expose exactly the bounded direct tool set');
// Direct mutation must never be enabled on Production, regardless of admin
// user privileges and the supplied bearer step-up scope.
$GLOBALS['mock_environment']='production';
$prod=$c::create_post(array('title'=>'Must never publish to Production'));
expect(is_wp_error($prod)&&$prod->get_error_code()==='mad4b_direct_production_write_denied',
    'Direct Production write must be independently denied');
$GLOBALS['mock_environment']='staging';
$GLOBALS['mock_options'][$c::OPTION]=array('contract'=>$c::CONTRACT,'mode'=>'read_only','revision'=>2);
expect($c::can_transport(),'Read only should allow authenticated transport');
expect(count($c::catalog_tools())===5,'A/B tool list must remain stable on read-only mode');
$readonly=$c::create_post(array('title'=>'Denied while read only'));
expect(is_wp_error($readonly)&&$readonly->get_error_code()==='mad4b_direct_read_only','Read-only profile must deny mutation');
$GLOBALS['mock_options'][$c::OPTION]=array('contract'=>$c::CONTRACT,'mode'=>'maintenance_lock','revision'=>3);
expect(!$c::can_transport(),'Maintenance lock must deny Direct tool transport');
$lock_read=$c::list_posts(array('limit'=>2));
expect(is_wp_error($lock_read)&&$lock_read->get_error_code()==='mad4b_direct_connection_denied',
    'Maintenance lock must deny Direct reads, not only writes');
$GLOBALS['mock_options'][$c::OPTION]=array('contract'=>$c::CONTRACT,'mode'=>'governed','revision'=>4);
expect(!$c::can_transport(),'Governed toggle must revoke direct transport');
$GLOBALS['mock_options'][$c::OPTION]=array('contract'=>'invalid','mode'=>'direct_authenticated','revision'=>4);
expect(!$c::state()['valid']&&!$c::can_transport(),'Corrupt setting must not create direct authority');
echo "DIRECT_MCP_RUNTIME: PASS (single MCP server, direct profile, enrolled-governed upgrade, WP caps, CRUD, stale edit, anonymous denial, read-only, corrupt option)\\n";
