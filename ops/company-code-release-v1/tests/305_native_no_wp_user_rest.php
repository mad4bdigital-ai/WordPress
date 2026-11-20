<?php
error_reporting(E_ALL);
define('ABSPATH','/tmp/');
define('MAD4B_MU_MODULAR_LOADER_BOOTED',true);
define('WP_CLI',false);
$GLOBALS['logged_user']=1;
function get_current_user_id(){return $GLOBALS['logged_user'];}
function wp_verify_nonce($nonce,$action){return $nonce==='test-good-nonce'&&$action==='wp_rest';}
function add_action($name,$handler,$priority=10){}
class WP_REST_Request {
    private $headers=[];
    public function get_header($name){return $this->headers[strtolower($name)]??'';}
    public function set_header($name,$value){$this->headers[strtolower($name)]=$value;}
}
class MAD4B_Central_Dual_Lane_Registry_V1 {
    public static function allowed(){return true;}
}
class MAD4B_Company_Manager_Unified_Administrator_Principal {
    public static function verified(){return true;}
}
require getenv('MAD4B_305_TEST_SOURCE')?:'/tmp/mad4b-305-native-no-user-candidate.php';
$c='MAD4B_Central_SSH_Command_Intake_V1';
$r=new WP_REST_Request();
if(!$c::permitted($r)){echo 'ORIGINAL_REST_OWNER_FALLBACK_BROKEN';exit(2);}
$r->set_header('X-WP-Nonce','test-invalid-nonce');
if($c::permitted($r)){echo 'INVALID_REST_NONCE_ACCEPTED';exit(3);}
$r->set_header('X-WP-Nonce','test-good-nonce');
if(!$c::permitted($r)){echo 'VALID_REST_NONCE_REJECTED';exit(4);}
$GLOBALS['logged_user']=0;
if($c::permitted($r)){echo 'UNAUTHENTICATED_REST_NONCE_ACCEPTED';exit(5);}
echo json_encode(['original_rest_owner_path_preserved'=>true,
 'invalid_nonce_denied'=>true,'no_wp_login_denied'=>true]);
