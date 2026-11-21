"""Python first-party Root Ed25519 offers and Owner signatures verify in PHP."""
import base64
import copy
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import time
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
sys.path.insert(0,str(ROOT/"tests/host-v135"))
import test_original305_remote_root_intake as fixtures
from persistent_enrollment import canonical
import original305_remote_intake_v135 as remote
from original305_v3_owner_provider import INTENT_CONTRACT,INTENT_FIELDS

PHP=ROOT/"deploy/host-authority/reconciliation/v135/425-mad4b-original305-remote-handoff.php"

def pub(key):
    from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
    return key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)

def sign_dict(row,key,fields,contract):
    payload={k:v for k,v in row.items() if k!="signature"}
    payload["signature"]=base64.b64encode(key.sign(canonical(
        payload,fields,contract))).decode()
    return payload

class CrossLanguageOwnerRemoteParityTests(unittest.TestCase):
    def test_python_signed_original313_offer_and_owner_intent_in_live_php_sodium(self):
        fixture=fixtures.Original305RemoteRootIntakeTests(
            "test_valid_both_signatures_do_not_issue_approval")
        fixture.setUp()
        now=int(time.time())
        fixture.review["created_epoch"]=now-30
        fixture.review["expires_epoch"]=now+800
        offer=dict(fixture.offer)
        offer["issued_epoch"]=now-10
        offer["expires_epoch"]=now+280
        fixture.offer=sign_dict(
            offer,fixture.independent313,remote.HANDOFF_FIELDS,
            remote.HANDOFF_CONTRACT)
        intent=dict(fixture.intent)
        intent["issued_epoch"]=now-5
        intent["expires_epoch"]=now+140
        fixture.intent=sign_dict(intent,fixture.stepup,INTENT_FIELDS,INTENT_CONTRACT)
        self.assertFalse(remote.verify_owner_remote_transport(
            review=fixture.review,original313_offer=fixture.offer,
            signed_owner_intent=fixture.intent,
            firstparty_index=fixture.index,
            firstparty_master_policy=fixture.policy,
            now=now,expected_source_commit=fixture.source,
            expected_plan_sha256=fixture.plan)["host_write_authorized"])
        payload={
            "review":fixture.review,"offer":fixture.offer,"intent":fixture.intent,
            "public313":base64.b64encode(pub(fixture.independent313)).decode(),
            "public312":base64.b64encode(pub(fixture.resource312)).decode()
        }
        cmd=shlex.split(os.getenv("MAD4B_TEST_PHP","php"))
        encoded=base64.b64encode(PHP.read_bytes()).decode()
        case=base64.b64encode(json.dumps(payload,separators=(",",":"),ensure_ascii=True).encode()).decode()
        php=r'''<?php
error_reporting(E_ALL);
define("ABSPATH","/tmp");
class WP_Error {
 public $code;public $message;
 public function __construct($code,$msg,$info){$this->code=$code;$this->message=$msg;}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Server {const READABLE="GET";const CREATABLE="POST";}
class WP_REST_Response {
 public $data;public $headers=[];
 public function __construct($d){$this->data=$d;}
 public function header($key,$value){$this->headers[$key]=$value;}
}
class TestRequest {
 public $params;public function __construct($p){$this->params=$p;}
 public function get_param($k){return $this->params[$k]??null;}
}
class MAD4B_Host_V3_Owner_Console {
 const SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a";
 const PAGE="mad4b-host-v3-owner-review";
 public static function plan_data(){return [
  "plan_sha256"=>str_repeat("b",64),
  "plan"=>["source_head"=>str_repeat("e",40)],
  "can_request_review"=>true];}
 public static function view_permission(){return true;}
}
function add_action($x,$cb){}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags|JSON_THROW_ON_ERROR);}
function rest_ensure_response($x){return new WP_REST_Response($x);}
function is_wp_error($x){return $x instanceof WP_Error;}
$payload=json_decode(base64_decode("__CASE__"),true,512,JSON_THROW_ON_ERROR);
define("MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64",$payload["public313"]);
define("MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64",$payload["public312"]);
function get_option($key,$default=false) {
 global $payload;
 $r=$payload["review"];
 if($key==="mad4b_v3_remote_req_".$r["request_id"])return $r;
 if($key==="mad4b_original313_v3_signed_owner_offer_v1")return $payload["offer"];
 return $default;
}
function expect($ok,$why){if(!$ok){fwrite(STDERR,$why."\n");exit(19);}}
eval("?>".base64_decode("__SOURCE__"));
$c="MAD4B_Host_V3_Remote_Handoff";
$handoff=$c::remote_request(new TestRequest(["request_id"=>$payload["review"]["request_id"]]));
expect($handoff instanceof WP_REST_Response,"No response");
expect($handoff->data["independent_signed_offer_ready"]===true,
 "Root313 Python signature did not verify in PHP: ".$handoff->data["offer_state"]);
$checked=$c::validate_proof_for_test($payload["review"],$payload["offer"],$payload["intent"],time());
expect(is_array($checked),"Python Owner signature did not verify in PHP");
expect($checked["root_independent_reverification_required"]===true,"Root verification bypass");
expect($checked["host_write_authorized"]===false,"Unexpected Host Write");
$bad=$payload["intent"];$bad["intent_nonce"]=str_repeat("0",32);
expect(is_wp_error($c::validate_proof_for_test($payload["review"],$payload["offer"],$bad,time())),
 "Signed Owner mutation accepted");
$bad=$payload["offer"];$bad["enrollment_sha256"]=str_repeat("0",64);
$payload["offer"]=$bad;
$blocked=$c::remote_request(new TestRequest(["request_id"=>$payload["review"]["request_id"]]));
expect($blocked->data["independent_signed_offer_ready"]===false,
 "Original313 signed Root offer mutation accepted");
echo "PYTHON_ED25519_SIGNED_PROOF_VERIFIED_IN_PHP_AND_MUTATION_REJECTED\n";
'''.replace("__CASE__",case).replace("__SOURCE__",encoded)
        run=subprocess.run(cmd,input=php,text=True,capture_output=True,timeout=22)
        self.assertEqual(run.returncode,0,run.stdout[-300:]+"\n"+run.stderr[-1200:])
        self.assertIn("PYTHON_ED25519_SIGNED_PROOF_VERIFIED_IN_PHP_AND_MUTATION_REJECTED",run.stdout)

if __name__=="__main__":unittest.main()
