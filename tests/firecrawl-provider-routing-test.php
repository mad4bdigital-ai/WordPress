<?php
define('ABSPATH','/tmp');
$GLOBALS['admin']=false;
$GLOBALS['abilities']=array();
$GLOBALS['actions']=array();
class WP_Error { private $code; function __construct($c){$this->code=$c;} function get_error_code(){return $this->code;} }
class MAD4B_Research_Firecrawl_Bridge {
    static function permitted(){return $GLOBALS['admin'];}
    static function status($input){return array('configured'=>true,'healthy'=>true);}
    static function verify_candidate_credentials($username,$password){return false;}
}
class MAD4B_SCP_Policy { static function can_read(){return true;} }
class MAD4B_SCP_Search_Provider_Connections { static function status($id){return array('connection_state'=>'NOT_CONFIGURED');} }
class MAD4B_SCP_Search_Providers { static function adapters(){return array();} }
function add_action($h,$c,$p=null){$GLOBALS['actions'][$h]=$c;}
function current_user_can($c){return $GLOBALS['admin'];}
function wp_register_ability($id,$a){$GLOBALS['abilities'][$id]=$a;}
function wp_has_ability($id){return isset($GLOBALS['abilities'][$id]);}
function is_wp_error($r){return $r instanceof WP_Error;}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-research-provider-connection.php';
$cl='MAD4B_Firecrawl_Provider_Connection';
call_user_func($GLOBALS['actions']['wp_abilities_api_init']);
assert(isset($GLOBALS['abilities']['mad4b/research-provider-route-plan']));
assert(isset($GLOBALS['actions']['admin_menu']));
assert(isset($GLOBALS['actions']['admin_post_mad4b_firecrawl_provider_credentials']));
assert(is_wp_error($cl::route(array('intent'=>'page_content'))));
$GLOBALS['admin']=true;
assert(is_wp_error($cl::route(array('intent'=>'evil'))));
assert(is_wp_error($cl::route(array('intent'=>'page_content','url'=>'https://example.com'))));
$p=$cl::route(array('intent'=>'page_content'));
assert($p['preferred_provider']==='firecrawl'&&$p['execution_ready']===true&&$p['execution_performed']===false);
$p=$cl::route(array('intent'=>'competitor_pricing'));
assert($p['preferred_provider']==='firecrawl'&&$p['price_changes_authorized']===false);
$p=$cl::route(array('intent'=>'google_serp'));
assert($p['preferred_provider']==='serpapi'&&$p['execution_ready']===false&&$p['fallback_to_firecrawl_ranking']===false);
echo "firecrawl-provider-routing-and-credentials-form-contract: PASS\n";
