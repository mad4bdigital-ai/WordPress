<?php
define('ABSPATH','/tmp/');
class WP_Error {public function __construct(public $code='',public $message=''){}}
function wp_get_environment_type(){return 'staging';}
function add_action(...$args){}
function add_filter(...$args){}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function register_post_type($type,$args){$GLOBALS['registered'][$type]=$args;}
function check($v,$name){if(!$v)throw new Exception($name);}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-fixture-drafts.php';
$c='MAD4B_SCP_Staging_Fixture_Drafts';
$c::registerPostTypes();
check(count($GLOBALS['registered'])===2,'expected two private synthetic types');
foreach($GLOBALS['registered'] as $type=>$config){
 check($config['public']===false&&$config['show_in_rest']===false&&$config['exclude_from_search']===true,'test fixture exposed publicly');
}
$x=$c::guardPostLifecycle(['post_type'=>$c::SOURCE,'post_status'=>'publish'],[]);
check($x['post_status']==='draft'&&$x['comment_status']==='closed','test draft published');
$GLOBALS['posts'][7]=(object)['post_type'=>$c::ASSET];
$x=$c::guardPostLifecycle(['post_type'=>'post','post_status'=>'publish'],['ID'=>7]);
check($x['post_type']===$c::ASSET&&$x['post_status']==='draft','test draft converted to public post');
check($c::guardMetadataUpdate(null,7,$c::META_SITE,'bad',null)===false,'protected fixture metadata mutated');
check($c::guardMetadataDelete(null,7,$c::META_LOCK,'',false)===false,'test fixture lock removed');
check($c::guardMetadataAdd(null,7,'unrestricted_meta','safe',false)===null,'unrestricted unrelated metadata blocked');
echo "STAGING_FIXTURE_RUNTIME PASS private draft, protected meta and public post conversion denial\n";
