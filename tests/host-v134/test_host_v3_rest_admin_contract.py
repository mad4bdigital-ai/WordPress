"""Native PHP REST/Admin component tests, exact-source and without WordPress."""
import base64
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
PHP=ROOT/"deploy/host-authority/reconciliation/v134/420-mad4b-host-v3-owner-console.php"

class HostV3AdminRestTests(unittest.TestCase):
    def test_rest_admin_review_contract_in_php(self):
        source=PHP.read_bytes()
        cmd=shlex.split(os.getenv("MAD4B_TEST_PHP","php"))
        if not cmd or not shutil.which(cmd[0]):
            if os.environ.get("MAD4B_REQUIRE_PHP")=="1":
                self.fail("PHP required for the security contract")
            self.skipTest("No PHP interpreter available")
        encoded=base64.b64encode(source).decode("ascii")
        script=r'''<?php
define("ABSPATH","/tmp");
define("WPMU_PLUGIN_DIR","/tmp");
class WP_Error {public $code;public $data;
  function __construct($a,$b,$c){$this->code=$a;$this->data=$c;}}
$actions=array();
function add_action($name,$callback,$priority=10){global $actions;$actions[]=$name;}
function is_user_logged_in(){return false;}
function current_user_can($cap){return false;}
function get_current_user_id(){return 17;}
function get_option($k,$v=false){return $v;}
function wp_get_environment_type(){return "staging";}
function home_url($v){return "https://tours.dev.mad4b.com/";}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
eval("?>".base64_decode("__SOURCE__"));
function verify($ok,$why){if(!$ok){fwrite(STDERR,$why."\n");exit(7);}}
$cl="MAD4B_Host_V3_Owner_Console";
verify(class_exists($cl),"class missing");
verify(in_array("rest_api_init",$actions,true),"rest route hook missing");
verify(in_array("admin_menu",$actions,true),"admin menu missing");
verify($cl::site_binding_valid()===false,"untrusted site allowed");
$permission=$cl::view_permission();
verify($permission instanceof WP_Error && $permission->code==="mad4b_v3_admin_required","unauthenticated permission failure");
$plan=array("plan_sha256"=>str_repeat("a",64),
    "can_request_review"=>true,"can_issue_signed_approval"=>false,
    "can_enable_persistent_write"=>false);
$payload=array("confirmation"=>$cl::CONFIRM,"request_id"=>str_repeat("b",32),
 "expected_plan_sha256"=>str_repeat("a",64));
$r=$cl::validate_request_for_test($payload,$plan,true,1800000000);
verify(is_array($r),"valid request must produce an unsigned review record");
verify($r["request_id"]===str_repeat("b",32),"exact request ID missing");
verify($r["state"]==="PENDING_INDEPENDENT_OWNER_STEPUP","review mistaken for approval");
verify($r["requested_by_user_id"]===17,"owner user id drift");
verify($r["expires_epoch"]===1800000900,"review ttl incorrect");
foreach(array("original305_approval_signed","original312_enrollment_signed",
   "original313_witness_signed","host_write_authorized") as $flag){
 verify($r[$flag]===false,"unsigned workflow incorrectly authorizes: ".$flag);
}
verify(!array_key_exists("signature",$r),"review creates synthetic signature");
$bad=$cl::validate_request_for_test($payload,$plan,false,1800000000);
verify($bad instanceof WP_Error && $bad->code==="mad4b_v3_browser_stepup_required",
  "non-browser review accepted");
$wrong=$payload;$wrong["confirmation"]="AUTHORIZE WRITE";
verify($cl::validate_request_for_test($wrong,$plan,true,1800000000) instanceof WP_Error,
 "arbitrary confirmation accepted");
$wrong=$payload;$wrong["expected_plan_sha256"]=str_repeat("c",64);
verify($cl::validate_request_for_test($wrong,$plan,true,1800000000) instanceof WP_Error,
 "stale plan accepted");
$wrong=$payload;$wrong["request_id"]="not-unique";
verify($cl::validate_request_for_test($wrong,$plan,true,1800000000) instanceof WP_Error,
 "invalid ID accepted");
$wrong=$payload;$wrong["approved"]=true;
verify($cl::validate_request_for_test($wrong,$plan,true,1800000000) instanceof WP_Error,
 "client-supplied approval accepted");
$wrong=$payload;$wrong["signature"]="fake";
verify($cl::validate_request_for_test($wrong,$plan,true,1800000000) instanceof WP_Error,
 "client-supplied signature accepted");
$blocked=$plan;$blocked["can_request_review"]=false;
verify($cl::validate_request_for_test($payload,$blocked,true,1800000000) instanceof WP_Error,
 "closed review gate bypassed");
$blocked=$plan;$blocked["can_enable_persistent_write"]=true;
verify($cl::validate_request_for_test($payload,$blocked,true,1800000000) instanceof WP_Error,
 "write gate mixed with review accepted");
$blocked=$plan;$blocked["can_issue_signed_approval"]=true;
verify($cl::validate_request_for_test($payload,$blocked,true,1800000000) instanceof WP_Error,
 "approval issue gate mixed with review accepted");
echo "PHP_REST_ADMIN_17_ASSERTIONS_OK\n";
'''.replace("__SOURCE__",encoded)
        out=subprocess.run(cmd,input=script,text=True,capture_output=True,timeout=22)
        self.assertEqual(out.returncode,0,out.stderr[-1200:])
        self.assertIn("PHP_REST_ADMIN_17_ASSERTIONS_OK",out.stdout)

    def test_rest_scope_and_no_signer_side_effects(self):
        text=PHP.read_text()
        self.assertIn("register_rest_route(self::API, '/status'",text)
        self.assertIn("register_rest_route(self::API, '/review-plan'",text)
        self.assertIn("register_rest_route(self::API, '/history'",text)
        self.assertIn("register_rest_route(self::API, '/review-requests'",text)
        self.assertIn("add_submenu_page(",text)
        self.assertIn("wp_validate_auth_cookie(",text)
        self.assertIn("wp_verify_nonce(",text)
        self.assertIn("current_user_can('manage_options')",text)
        self.assertIn("add_option($name,$record,'','no')",text)
        self.assertIn("if (!self::browser_owner_session())",text)
        self.assertIn("MAD4B_Original318_Host_V3_Readback::current",text)
        for danger in ("shell_exec(", "proc_open(", "system(", "exec(",
                       "sodium_crypto_sign_detached(","openssl_sign(",
                       "wp_create_user(", "wp_set_current_user(",
                       "update_option('mad4b_host_v3_signed_enrollment_",
                       "update_option('mad4b_host_v3_signed_checkpoint_"):
            self.assertNotIn(danger,text)

    def test_api_and_admin_use_same_plan(self):
        src=PHP.read_text()
        self.assertIn("public static function plan_endpoint($request) { return self::response(self::plan_data()); }",src)
        self.assertIn("$plan=self::plan_data();",src)
        self.assertIn("public static function history_endpoint($request)",src)
        self.assertIn("'current_original313_v3_predecessor'=>'NOT_ESTABLISHED'",src)
        self.assertIn("const CONFIRM = 'REQUEST EXACT V3 OWNER STEP-UP';",src)
        self.assertIn("'write_authorization_from_this_console'=>false",src)
        self.assertIn("'original313_complete_predecessor'=>'UNVERIFIED_IN_WORDPRESS'",src)

if __name__=="__main__":unittest.main()
