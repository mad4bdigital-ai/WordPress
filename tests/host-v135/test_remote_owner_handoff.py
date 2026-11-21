"""Live PHP 8.5 contract: remote review + independent signed owner proof only."""
import base64
import os
from pathlib import Path
import shlex
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
PHP=ROOT/"deploy/host-authority/reconciliation/v135/425-mad4b-original305-remote-handoff.php"

class RemoteOwnerHandoffPHP(unittest.TestCase):
    def test_php_real_ed25519_independent_offer_and_intent(self):
        cmd=shlex.split(os.environ.get("MAD4B_TEST_PHP","php"))
        src=base64.b64encode(PHP.read_bytes()).decode()
        php=r'''<?php
error_reporting(E_ALL);
define("ABSPATH","/tmp");
define("LOGGED_IN_COOKIE","test_logged_in");
define("WPMU_PLUGIN_DIR",sys_get_temp_dir()."/v135-remote-".bin2hex(random_bytes(8)));
mkdir(WPMU_PLUGIN_DIR,0700,true);
$global_options=[];$global_transients=[];
$routes=[];$hooks=[];$uid=12;$logged=true;$caps=true;
class WP_Error {
 public $code;public $message;public $data;
 public function __construct($a,$b,$c){$this->code=$a;$this->message=$b;$this->data=$c;}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Server {const READABLE="GET";const CREATABLE="POST";}
class TestResponse {
 public $data;public $headers=[];
 function __construct($d){$this->data=$d;}
 function header($k,$v){$this->headers[$k]=$v;}
}
class TestRequest {
 public $body;public $query;
 function __construct($b=[],$q=[]){$this->body=$b;$this->query=$q;}
 function get_json_params(){return $this->body;}
 function get_param($key){return $this->query[$key]??null;}
}
class MAD4B_Host_V3_Owner_Console {
 const SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a";
 const PAGE="mad4b-host-v3-owner-review";
 public static function site_binding_valid(){return true;}
 public static function view_permission(){return true;}
 public static function plan_data(){
 return ["plan_sha256"=>str_repeat("b",64),
  "can_request_review"=>true,"can_issue_signed_approval"=>false,
  "can_enable_persistent_write"=>false,
  "plan"=>["site_uuid"=>self::SITE,"environment"=>"staging",
       "action"=>"REQUEST_INDEPENDENT_OWNER_STEPUP","write_authorized"=>false,
       "source_head"=>str_repeat("e",40)]];
 }
}
function add_action($x,$y){global $hooks;$hooks[]=$x;}
function register_rest_route($ns,$path,$data){global $routes;$routes[$path]=$data;}
function is_user_logged_in(){global $logged;return $logged;}
function current_user_can($cap){global $caps;return $caps;}
function get_current_user_id(){global $uid;return $uid;}
function wp_get_session_token(){return "active-session";}
function wp_validate_auth_cookie($x,$scheme){return $x==="valid-cookie"?12:0;}
function wp_verify_nonce($x,$action){return $x==="valid-nonce"&&$action==="wp_rest";}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags|JSON_THROW_ON_ERROR);}
function get_option($key,$default=false){global $global_options;return $global_options[$key]??$default;}
function add_option($key,$value,$obsolete="",$autoload="yes"){global $global_options;
 if(array_key_exists($key,$global_options))return false;$global_options[$key]=$value;return true;}
function get_transient($key){global $global_transients;return $global_transients[$key]??false;}
function set_transient($key,$value,$ttl){global $global_transients;$global_transients[$key]=$value;return true;}
function rest_ensure_response($data){return new TestResponse($data);}
function rest_url($path){return "https://tours.dev.mad4b.com/wp-json/".$path;}
function is_admin(){return true;}
function wp_create_nonce($x){return "valid-nonce";}
define("MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64",base64_encode(str_repeat(chr(3),32)));
eval("?>".base64_decode("__SOURCE__"));
function expect($ok,$msg){if(!$ok){fwrite(STDERR,$msg."\n");exit(8);}}
function sign_doc($row,$domain,$priv){
  ksort($row,SORT_STRING);
  $data=$domain.json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  return base64_encode(sodium_crypto_sign_detached($data,$priv));
}
$c="MAD4B_Host_V3_Remote_Handoff";
$c::routes();
expect(count($routes)===4,"four remote routes required");
foreach(["/remote-status","/remote-request","/remote-requests","/remote-signed-proof"] as $key)
 expect(isset($routes[$key]),"route missing ".$key);
$_COOKIE[LOGGED_IN_COOKIE]="valid-cookie";
$_SERVER["HTTP_X_WP_NONCE"]="valid-nonce";
$plan=MAD4B_Host_V3_Owner_Console::plan_data();
$payload=["expected_plan_sha256"=>str_repeat("b",64),"confirmation"=>$c::CONFIRM];
$id=str_repeat("a",32);
$rec=$c::create_record_for_test($payload,$plan,true,12,1800000000,$id);
expect(is_array($rec)&&$rec["expires_epoch"]===1800001800,"remote request creation invalid");
expect($rec["state"]===$c::PHASE,"remote state incorrectly approved");
expect($rec["original305_approval_signed"]===false && $rec["standing_host_write_authorized"]===false,"remote grants write");
expect(is_wp_error($c::create_record_for_test($payload,$plan,false,12,1800000000,$id)),"nonbrowser accepted");
$bad=$payload;$bad["host_write_authorized"]=true;
expect(is_wp_error($c::create_record_for_test($bad,$plan,true,12,1800000000,$id)),"client extra host write field accepted");
$plan2=$plan;$plan2["plan"]["site_uuid"]="foreign";
expect(is_wp_error($c::create_record_for_test($payload,$plan2,true,12,1800000000,$id)),"cross-site plan accepted");
$k="mad4b_v3_remote_req_".$id;
$global_options[$k]=$rec;
$before=$c::remote_request(new TestRequest([],
 ["request_id"=>$id]))->data;
expect($before["independent_signed_offer_ready"]===false,"missing signed Root offer treated as valid");
expect($before["owner_approval_issued"]===false,"review treated as signed approval");
$key313=sodium_crypto_sign_keypair();
$priv313=sodium_crypto_sign_secretkey($key313);
$pub313=sodium_crypto_sign_publickey($key313);
define("MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64",base64_encode($pub313));
$missing=$c::receive_signed_proof(new TestRequest([
 "request_id"=>$id,"signed_intent"=>[]]));
expect(is_wp_error($missing)&&$missing->code==="mad4b_remote_independent_offer_absent","proof accepted absent Root predecessor");
$keyOwner=sodium_crypto_sign_keypair();
$privOwner=sodium_crypto_sign_secretkey($keyOwner);
$pubOwner=sodium_crypto_sign_publickey($keyOwner);
$issuer305=sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
$now=time();
$offer=[
 "contract"=>$c::HANDOFF,"company"=>"mad4b-central",
 "environment"=>"staging","site_uuid"=>MAD4B_Host_V3_Owner_Console::SITE,
 "target_host_id"=>"hostinger-primary","request_id"=>$id,
 "plan_sha256"=>str_repeat("b",64),
 "predecessor_sha256"=>str_repeat("c",64),
 "enrollment_sha256"=>str_repeat("d",64),
 "source_commit_sha"=>str_repeat("e",40),
 "owner_stepup_public_b64"=>base64_encode($pubOwner),
 "owner_stepup_enrollment_sha256"=>str_repeat("f",64),
 "original305_issuer_public_b64"=>base64_encode($issuer305),
 "original305_issuer_enrollment_sha256"=>str_repeat("6",64),
 "identity_authority_confirmed"=>true,
 "monotonic_predecessor_verified"=>true,
 "issued_epoch"=>$now,"expires_epoch"=>$now+300,
 "state"=>"READY_FOR_OWNER_STEPUP"];
$offer["signature"]=sign_doc($offer,$c::HANDOFF_DOMAIN,$priv313);
$global_options[$c::OFFER_OPTION]=$offer;
$good=$c::remote_request(new TestRequest([],
 ["request_id"=>$id]))->data;
expect($good["independent_signed_offer_ready"]===true,"real Root313 signed offer not accepted: ".($good["offer_state"]??"unknown"));
$intent=[
 "contract"=>$c::INTENT,"company"=>"mad4b-central",
 "environment"=>"staging","site_uuid"=>MAD4B_Host_V3_Owner_Console::SITE,
 "target_host_id"=>"hostinger-primary",
 "enrollment_sha256"=>str_repeat("d",64),
 "predecessor_sha256"=>str_repeat("c",64),
 "source_commit_sha"=>str_repeat("e",40),
 "operation_id"=>"company.host-authority.enrollment.v3.issue",
 "request_id"=>$id,"actor_digest_sha256"=>str_repeat("1",64),
 "intent_nonce"=>str_repeat("2",32),
 "decision"=>"APPROVE","issued_epoch"=>$now,"expires_epoch"=>$now+100];
$intent["signature"]=sign_doc($intent,$c::INTENT_DOMAIN,$privOwner);
$quarantine=$c::validate_proof_for_test($rec,$offer,$intent,$now);
expect(is_array($quarantine),"independently signed owner proof rejected");
expect($quarantine["root_independent_reverification_required"]===true,"Root re-verification bypassed");
expect($quarantine["original305_approval_signed"]===false,"WordPress faked Original305 approval");
expect($quarantine["host_write_authorized"]===false,"WordPress granted write");
$submission=new TestRequest(["request_id"=>$id,"signed_intent"=>$intent]);
$result=$c::receive_signed_proof($submission);
expect($result instanceof TestResponse && $result->data["result"]==="SIGNED_PROOF_QUARANTINED_NOT_AN_APPROVAL","proof not quarantined");
expect($result->data["write_authorized"]===false,"write flag from proof intake");
$again=$c::receive_signed_proof($submission);
expect($again instanceof TestResponse && $again->data["result"]==="EXACT_SIGNED_PROOF_ALREADY_IN_QUARANTINE","idempotent replay failed");
$bad=$intent;$bad["decision"]="DENY";
expect(is_wp_error($c::validate_proof_for_test($rec,$offer,$bad,$now)),"tampered decision accepted");
$bad=$intent;$bad["source_commit_sha"]=str_repeat("a",40);
expect(is_wp_error($c::validate_proof_for_test($rec,$offer,$bad,$now)),"wrong source accepted");
$bad=$intent;$bad["request_id"]=str_repeat("4",32);
expect(is_wp_error($c::validate_proof_for_test($rec,$offer,$bad,$now)),"cross-request accepted");
$bad=$intent;$bad["signature"]=base64_encode(str_repeat("X",64));
expect(is_wp_error($c::validate_proof_for_test($rec,$offer,$bad,$now)),"fake signature accepted");
$bad=$offer;$bad["site_uuid"]="foreign";
$global_options[$c::OFFER_OPTION]=$bad;
expect(is_wp_error($c::receive_signed_proof($submission)),"Root offer mutation accepted");
$global_options[$c::OFFER_OPTION]=$offer;
$bad=$offer;$bad["expires_epoch"]=$now-1;
$bad["signature"]=sign_doc($bad,$c::HANDOFF_DOMAIN,$priv313);
$global_options[$c::OFFER_OPTION]=$bad;
expect(is_wp_error($c::receive_signed_proof($submission)),"expired signed Root offer accepted");
rmdir(WPMU_PLUGIN_DIR);
echo "V135_REAL_PHP_REMOTE_SIGNED_OWNER_PROOF_31_ASSERTIONS_PASS\n";
'''.replace("__SOURCE__",src)
        result=subprocess.run(cmd,input=php,text=True,capture_output=True,timeout=26)
        self.assertEqual(result.returncode,0,result.stdout[-1000:]+"\n"+result.stderr[-2600:])
        self.assertIn("V135_REAL_PHP_REMOTE_SIGNED_OWNER_PROOF_31_ASSERTIONS_PASS",result.stdout)

    def test_no_private_key_loading_or_root_mutations(self):
        src=PHP.read_text()
        for forbidden in ("sodium_crypto_sign_detached(", "openssl_sign(",
                          "shell_exec(", "proc_open(", "system(", "exec(",
                          "wp_create_user(", "wp_set_current_user(",
                          "update_option('mad4b_host_v3_signed_enrollment",
                          "password_verify("):
            self.assertNotIn(forbidden,src)
        self.assertIn("sodium_crypto_sign_verify_detached(",src)
        self.assertIn("MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64",src)
        self.assertIn("'host_write_authorized'=>false",src)
        self.assertIn("'original305_approval_signed'=>false",src)
        self.assertIn("original305.remote-handoff-status.v1",src)
        self.assertIn("add_option($key,$proof",src)
        self.assertIn("root_independent_reverification_required",src)

if __name__=="__main__":
    unittest.main()
