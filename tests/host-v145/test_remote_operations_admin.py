"""v145 read-only WP aggregate + original endpoints-only Owner UI contract."""
from pathlib import Path
import json
import os
import re
import shlex
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "deploy/host-authority/reconciliation/v136/431-mad4b-remote-operations-admin.php"
PHP_COMMAND = shlex.split(os.environ.get("MAD4B_TEST_PHP",
    "docker exec -i php-wkgtkzta35wkkgouwt6ruljc php"))

def php_eval(body):
    script = ('''<?php
define("ABSPATH","/tmp/");
function add_action(...$a){}
function wp_is_uuid($value){return (bool)preg_match("/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D",$value);}
function is_user_logged_in(){return ($GLOBALS["mock_auth"]??false);}
function current_user_can($cap){return ($GLOBALS["mock_auth"]??false) && $cap==="manage_options";}
function get_current_user_id(){return 42;}
function get_option($key,$fallback=false){return $GLOBALS["mock_options"][$key]??$fallback;}
function rest_ensure_response($value){return $value;}
function register_rest_route($ns,$path,$args){$GLOBALS["routes"][]=["ns"=>$ns,"path"=>$path,"method"=>$args["methods"]];}
function wp_create_nonce($name){return "test-nonce";}
function esc_url($s){return $s;}function esc_html($s){return $s;}
function admin_url($s){return "https://tours.dev.mad4b.com/wp-admin/".$s;}
function rest_url($s){return "https://tours.dev.mad4b.com/wp-json/".$s;}
class MAD4B_Central_Dual_Lane_Registry_V1{public static function allowed(){return $GLOBALS["mock_auth"]??false;}}
class MAD4B_Host_V3_Owner_Console{
  public static function site_binding_valid(){return true;}
  public static function status_data(){return ["current"=>["state"=>"MISSING_OR_INVALID","write_authorized"=>false,"signature_verified"=>false,"readback_verified"=>false]];}
}
class MockWPDB{
 public $options="wp_options";
 public function esc_like($x){return $x;}
 public function prepare($sql,...$values){return (string)$values[0];}
 public function get_col($q){$names=[];foreach($GLOBALS["mock_options"]??[] as $key=>$value){if(str_starts_with((string)$key,rtrim($q,"%"))) $names[]=$key;}return array_reverse($names);}
}
$wpdb=new MockWPDB();
$GLOBALS["mock_auth"]=true;
$GLOBALS["mock_options"]=[];
require "php://stdin";
''')
    # Using php://stdin read would reconsume the process input. Instead
    # prepend the plugin source as PHP text and then execute our checks.
    source=SRC.read_text()
    base=script.replace('require "php://stdin";','')
    return base+source.replace("<?php", "", 1)+"\n"+body+"\n"

def run_php(code):
    p=subprocess.run(PHP_COMMAND,
                     input=code,text=True,capture_output=True,timeout=24)
    if p.returncode:
        raise AssertionError(f"PHP fixture returned {p.returncode}:\n{p.stderr[-1300:]}\n{p.stdout[-300:]}")
    return p.stdout.strip()

class RemoteOperationsSourceTests(unittest.TestCase):
    def test_php_lint(self):
        p=subprocess.run(PHP_COMMAND+["-l"],
                         input=SRC.read_text(),text=True,capture_output=True,timeout=15)
        self.assertEqual(p.returncode,0,p.stderr)
        self.assertIn("No syntax errors",p.stdout)

    def test_all_public_records_are_projected_without_secrets(self):
        uuid="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
        body=f'''
$companies=["tours-central"=>["staging"=>["site_uuid"=>"{uuid}","origin"=>"https://tours.dev.mad4b.com"]]];
$raw=["company"=>"tours-central","environment"=>"staging","site_uuid"=>"{uuid}",
"grant_id"=>str_repeat("a",32),"state"=>"CENTRAL_OPERATION_SCOPE_GRANTED",
"operation"=>"maintenance.shell","target_host_id"=>"srv2030908","central_principal_id"=>42,
"grant_mode"=>"persistent","transport_id"=>str_repeat("b",32),
"sealed_payload"=>"PRIVATE_SHELL_BYTES","credential_ref"=>"vault:very-secret",
"owner_private_key"=>"NEVER_SHARE","signature"=>"NEVER_DISPLAY"];
$project=MAD4B_Remote_Operations_Admin_V145::project_record("grant",$raw,$companies);
$missing=$raw;$missing["site_uuid"]="00000000-0000-0000-0000-000000000000";
echo json_encode(["project"=>$project,
  "wrong_uuid"=>MAD4B_Remote_Operations_Admin_V145::project_record("grant",$missing,$companies),
  "wrong_company"=>MAD4B_Remote_Operations_Admin_V145::project_record("grant",array_merge($raw,["company"=>"unknown"]),$companies)]);
'''
        out=json.loads(run_php(php_eval(body)))
        self.assertEqual(out["project"]["mode"],"persistent")
        self.assertEqual(out["project"]["id"],"a"*32)
        self.assertFalse(out["project"]["write_authorized"])
        self.assertNotIn("sealed_payload",out["project"])
        self.assertNotIn("credential_ref",out["project"])
        self.assertNotIn("owner_private_key",out["project"])
        self.assertNotIn("signature",out["project"])
        self.assertIsNone(out["wrong_uuid"])
        self.assertIsNone(out["wrong_company"])

    def test_routes_are_read_only_and_guest_denied(self):
        body='''MAD4B_Remote_Operations_Admin_V145::routes();
$routes=$GLOBALS["routes"];
$GLOBALS["mock_auth"]=false;
echo json_encode(["routes"=>$routes,
"guest"=>MAD4B_Remote_Operations_Admin_V145::authorized(),
"guest_status"=>MAD4B_Remote_Operations_Admin_V145::overview()]);'''
        out=json.loads(run_php(php_eval(body)))
        self.assertEqual(out["routes"],[{"ns":"mad4b-remote-operations/v1","path":"/overview","method":"GET"}])
        self.assertFalse(out["guest"])
        self.assertFalse(out["guest_status"]["write_authorized"])
        self.assertFalse(out["guest_status"]["authorized"])

    def test_reviewed_grants_and_jobs_no_fabricated_write(self):
        uuid="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
        body=f'''
$GLOBALS["mock_options"]["mad4b_company_environment_registry_v2"]=["companies"=>[
 "tours-central"=>["environments"=>["staging"=>["site_uuid"=>"{uuid}",
 "canonical_origin"=>"https://tours.dev.mad4b.com"]]]]];
$GLOBALS["mock_options"]["mad4b_central_ssh_grant_v1_".str_repeat("a",32)]=[
  "company"=>"tours-central","environment"=>"staging",
  "site_uuid"=>"{uuid}","grant_id"=>str_repeat("a",32),
  "grant_mode"=>"persistent","state"=>"CENTRAL_OPERATION_SCOPE_GRANTED",
  "central_principal_id"=>42,"operation"=>"maintenance.shell",
  "credential_ref"=>"vault:secret", "execution_authorized"=>true];
$GLOBALS["mock_options"]["mad4b_central_broker_job_v1_".str_repeat("b",32)]=[
 "company"=>"tours-central","environment"=>"staging","site_uuid"=>"{uuid}",
 "job_id"=>str_repeat("b",32),"state"=>"QUEUED_FOR_VPS_BROKER_CLAIM",
 "sealed_payload"=>"very-secret-data"];
echo json_encode(MAD4B_Remote_Operations_Admin_V145::overview());
'''
        out=json.loads(run_php(php_eval(body)))
        self.assertEqual(len(out["grants"]),1)
        self.assertEqual(len(out["jobs"]),1)
        self.assertEqual(out["grants"][0]["write_authorized"],False)
        self.assertEqual(out["v3"]["write_authorized"],False)
        self.assertEqual(out["jobs"][0]["independent_readback_verified"],False)
        encoded=json.dumps(out)
        self.assertNotIn("vault:secret",encoded)
        self.assertNotIn("very-secret-data",encoded)
        self.assertFalse(out["operation_submission"]["new_write_authority_from_this_page"])

    def test_js_syntax_and_bound_original_endpoints_only(self):
        source=SRC.read_text()
        scripts=re.findall(r"<<<'JS'\n(.*?)\nJS;",source,re.S)
        self.assertEqual(len(scripts),1)
        p=subprocess.run(["node","--check"],input=scripts[0],capture_output=True,text=True,timeout=12)
        self.assertEqual(p.returncode,0,p.stderr)
        js=scripts[0]
        self.assertIn('"/grants"',js)
        self.assertIn('"/broker-dispatch"',js)
        self.assertIn('X-WP-Nonce',js)
        self.assertIn('window.confirm',js)
        self.assertIn('maintenance.shell',js)
        self.assertNotIn('shell_exec',js)
        self.assertNotIn('credential_ref',js)
        self.assertNotIn('new_private',js)

    def test_frontend_fetches_only_read_on_initial_render(self):
        js=re.search(r"<<<'JS'\n(.*?)\nJS;",SRC.read_text(),re.S).group(1)
        program=r'''
const vm=require("node:vm"),assert=require("node:assert/strict");
class Node {
  constructor(tag){this.tagName=tag;this.children=[];this.textContent="";this.style={};
    this.listeners={};this.value="";this.disabled=false;this.className="";}
  append(...items){this.children.push(...items);}
  removeChild(el){this.children.splice(this.children.indexOf(el),1);}
  get firstChild(){return this.children[0]||null;}
  get lastChild(){return this.children[this.children.length-1]||null;}
  addEventListener(k,f){this.listeners[k]=f;}
  querySelector(selector){return nodes.get(selector.slice(1))||null;}
}
const nodes=new Map();
for(const id of ["m-status","m-authority","m-transport","m-grant","m-grant-note",
 "m-grants","m-requests","m-jobs","m-mode","m-reason","m-refresh"])
 nodes.set(id,new Node(id));
nodes.get("m-mode").value="one_time";
nodes.get("m-reason").value="Specific controlled maintenance";
const root=new Node("main");
root.querySelector=selector=>nodes.get(selector.slice(1))||null;
const document={getElementById:id=>id==="mad4b-remote-operations-v145"?root:null,
 createElement:tag=>new Node(tag)};
const calls=[];
const overview={contract:"mad4b.remote-operations-owner-console.v145",
 authorized:true,companies:{},grants:[],transports:[],requests:[],jobs:[],
 v3:{state:"MISSING_OR_INVALID",write_authorized:false,signature_verified:false,readback_verified:false}};
const fetch=async (url,options)=>{calls.push({url,options});return {ok:true,status:200,json:async()=>overview};};
const window={MAD4BRemoteOperationsV145:{overview:"/wp-json/mad4b-remote-operations/v1/overview",
root:"/wp-json/mad4b/v1/central-execution-lanes/",nonce:"wpnonce",actor:42},
confirm(){throw Error("Should not ask for confirmation on load")}};
vm.runInNewContext(__JS__,{window,document,fetch,Date,Math,Number,Object,String,Error});
setImmediate(()=>{
 assert.equal(calls.length,1);
 assert.equal(calls[0].options.method,"GET");
 assert.equal(calls[0].options.headers["X-WP-Nonce"],"wpnonce");
 assert.equal(nodes.get("m-grant").disabled,true);
 assert.match(nodes.get("m-status").textContent,/Loaded 0 grants/);
 console.log("V145_UI_INITIAL_RENDER_READ_ONLY_PASS");
});
'''.replace("__JS__",json.dumps(js))
        p=subprocess.run(["node"],input=program,capture_output=True,text=True,timeout=13)
        self.assertEqual(p.returncode,0,p.stderr[-1400:])
        self.assertIn("V145_UI_INITIAL_RENDER_READ_ONLY_PASS",p.stdout)

if __name__=="__main__":
    unittest.main()
