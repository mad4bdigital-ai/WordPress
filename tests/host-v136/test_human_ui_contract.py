"""Human-readable MAD4B Host v136 Admin UI, no authority writes."""
import base64
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "deploy/host-authority/reconciliation/v136/430-mad4b-host-authority-human-ui.php"

class HumanUIContractTests(unittest.TestCase):
    def php_fixture(self, scenario="existing", privileged=True, locale="ar_EG"):
        raw = base64.b64encode(SOURCE.read_bytes()).decode()
        script = r'''<?php
error_reporting(E_ALL);
define("ABSPATH", "/tmp");
$cases="__SCENARIO__";
$privileged=__PRIV__;
class MAD4B_Host_V3_Owner_Console {
    const SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a";
    public static function site_binding_valid(){return true;}
}
class MAD4B_Host_V3_Remote_Handoff {}
class FakeWpdb {
    public $options="wp_options";
    function esc_like($s){return $s;}
    function prepare($s,$arg){return str_replace("%s", "'".$arg."'", $s);}
    function get_col($sql){
        global $cases;
        if($cases==="empty")return [];
        return ["mad4b_v3_remote_req_".str_repeat("a",32),
                "mad4b_v3_remote_req_".str_repeat("b",32)];
    }
}
$wpdb=new FakeWpdb();
function is_admin(){return true;}
function current_user_can($cap){global $privileged;return $privileged;}
function get_current_user_id(){return 42;}
function get_user_locale(){return "__LOCALE__";}
function add_action($hook,$cb,$priority=10){}
function rest_url($path){return "https://tours.dev.mad4b.com/wp-json/".$path;}
function wp_create_nonce($action){return "testing-rest-nonce";}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_THROW_ON_ERROR);}
function get_option($name,$default=false){
    global $cases;
    $user= $name==="mad4b_v3_remote_req_".str_repeat("a",32)?43:42;
    $expires=$cases==="expired"?time()-100:time()+500;
    $record=[
      "contract"=>"mad4b.original305.remote-review-request.v1",
      "site_uuid"=>MAD4B_Host_V3_Owner_Console::SITE,
      "company"=>"mad4b-central","environment"=>"staging",
      "request_id"=>$name==="mad4b_v3_remote_req_".str_repeat("a",32)?str_repeat("a",32):str_repeat("b",32),
      "requested_by_user_id"=>$user,
      "created_epoch"=>time()-20,"expires_epoch"=>$expires,
      "state"=>"AWAITING_INDEPENDENT_ORIGINAL313_PREDECESSOR"
    ];
    return $record;
}
$_GET["page"]="mad4b-host-v3-owner-review";
eval("?>".base64_decode("__SOURCE__"));
$cls="MAD4B_Host_V3_Human_UI";
$latest=$cls::latest_owner_review();
ob_start();$cls::render();$html=ob_get_clean();
echo json_encode(["latest"=>$latest,"html"=>$html],JSON_THROW_ON_ERROR);
'''.replace("__SOURCE__", raw).replace("__SCENARIO__",scenario).replace("__PRIV__","true" if privileged else "false").replace("__LOCALE__",locale)
        cmd=shlex.split(os.getenv("MAD4B_TEST_PHP","php"))
        p=subprocess.run(cmd,input=script,text=True,capture_output=True,timeout=25)
        self.assertEqual(p.returncode,0,p.stderr[-1600:])
        return json.loads(p.stdout)

    def test_existing_request_shown_without_new_request(self):
        output=self.php_fixture()
        review=output["latest"]
        self.assertEqual(review["request_id"],"b"*32)
        self.assertEqual(review["state"],"AWAITING_INDEPENDENT_ORIGINAL313_PREDECESSOR")
        self.assertFalse(review["expired"])
        self.assertIn("تم تسجيل طلب المراجعة",output["html"])
        self.assertIn("لا تحتاج إلى إنشاء طلب جديد الآن",output["html"])
        self.assertIn("تفاصيل فنية للمسؤول التقني",output["html"])
        self.assertIn("النظام ينتظر استكمال سجل الاعتمادات", output["html"])

    def test_only_own_request_visible(self):
        output=self.php_fixture()
        self.assertNotIn("a"*32,output["html"])
        self.assertIn("b"*32,output["html"])

    def test_expired_review_is_not_active(self):
        output=self.php_fixture("expired")
        self.assertTrue(output["latest"]["expired"])
        self.assertIn('"renewHeading":"تجديد طلب المراجعة"',output["html"])

    def test_missing_review_shows_one_plain_button(self):
        output=self.php_fixture("empty")
        self.assertIsNone(output["latest"])
        self.assertIn('"requestButton":"تسجيل طلب مراجعة"',output["html"])

    def test_unprivileged_admin_not_rendered(self):
        output=self.php_fixture(privileged=False)
        self.assertIsNone(output["latest"])
        self.assertEqual(output["html"],"")

    def test_embedded_js_parses_with_real_json_bootstrap(self):
        output=self.php_fixture()
        scripts=re.findall(
            r'<script id="mad4b-host-v136-human-script">(.*?)</script>',
            output["html"],re.S
        )
        self.assertEqual(len(scripts),1)
        node=subprocess.run(["node","--check"],input=scripts[0],text=True,
                            capture_output=True,timeout=10)
        self.assertEqual(node.returncode,0,node.stderr)
        self.assertIn('credentials:"same-origin"', scripts[0])
        self.assertIn('"X-WP-Nonce":settings.restNonce',scripts[0])
        self.assertIn('confirmation:"REQUEST REMOTE V3 OWNER REVIEW"', scripts[0])

    def test_mock_dom_renders_four_steps_and_hides_duplicate_forms(self):
        for locale,scenario,should_hide_button in (
            ("ar_EG","existing",True),("en_US","existing",True),
            ("en_US","expired",True),("ar_EG","empty",False),
            ("fr_FR","existing",True),("de_DE","existing",True),
            ("es_ES","existing",True),("it_IT","existing",True),
            ("ja","existing",True)
        ):
            with self.subTest(locale=locale,scenario=scenario):
                output=self.php_fixture(scenario,locale=locale)
                scripts=re.findall(
                    r'<script id="mad4b-host-v136-human-script">(.*?)</script>',
                    output["html"],re.S
                )
                script=scripts[0]
                case=json.dumps(script)
                js=r"""
const assert=require('node:assert/strict');
const vm=require('node:vm');
class Element {
 constructor(tag) {
   this.tag=tag;this.className='';this.children=[];this.style={};
   this.hidden=false;this.textContent='';this.dir='';this.attrs={};this.listeners={};
   this.classList={add:()=>{},remove:()=>{}};
 }
 append(...els){for(const e of els){if(typeof e==='object'){this.children.push(e);e.parent=this;}}}
 prepend(e){this.children.unshift(e);e.parent=this;}
 setAttribute(k,v){this.attrs[k]=v;}
 get firstChild(){return this.children[0]||null;}
 get lastChild(){return this.children[this.children.length-1]||null;}
 contains(el){return this===el||this.children.some(c=>c.contains(el));}
 addEventListener(k,fn){this.listeners[k]=fn;}
}
const main=new Element('div'), side=new Element('div');
const old=[
 new Element('section'),new Element('section'),new Element('section')];
const layout=new Element('div');layout.append(main,side);
layout.querySelectorAll=()=>old;
const heading=new Element('h1'),intro=new Element('p');
const page={
 querySelector(q){
   if(q==='.v3-layout')return layout;
   if(q==='h1')return heading;
   if(q===':scope > p')return intro;
   return null;
 }
};
const document={
 readyState:'complete',createElement:tag=>new Element(tag),
 querySelector:q=>q==='.mad4b-host-v3'?page:null,
 addEventListener:()=>{throw Error('Unexpected listener')}
};
const fetchCalls=[];
const fetch=async(path,opts)=>{
 fetchCalls.push({path,method:opts.method});
 return {ok:true,json:async()=>({signed_root_offer_present:false})};
};
vm.runInNewContext(__JS__,{document,fetch,navigator:{clipboard:{writeText:async()=>{}}},
 Intl,Date,Math,console});
const pane=main.children[0];
assert.equal(pane.className,'mad4b-human');
assert.equal(pane.dir,__DIRECTION__);
const steps=pane.children.find(x=>x.tag==='ol');
assert.equal(steps.children.length,4);
const actions=pane.children.find(x=>x.className==='human-actions');
assert.ok(actions);
const primary=actions.children.find(x=>x.tag==='button');
assert.equal(primary.hidden,__HIDE__);
assert.equal(primary.style.display,__HIDE__?'none':'inline-flex');
assert.equal(primary.disabled,__HIDE__);
if(__HIDE__){assert.equal(primary.textContent,'');assert.equal(primary.attrs['aria-hidden'],'true');}
const secondary=actions.children.filter(x=>x.tag==='button')[1];
if(__HIDE__){assert.equal(secondary.hidden,false);assert.equal(secondary.style.marginInlineStart,'0');}
const advanced=pane.children.find(x=>x.tag==='details');
assert.ok(advanced);
assert.equal(advanced.children[1].children.length,4);
assert.equal(advanced.attrs.open,undefined);
assert.equal(layout.style.display,'block');
assert.equal(heading.textContent,__HEADING__);
assert.ok(main.children.length>=1);
assert.ok(!('signature' in pane));
console.log('V136_DOM_RENDER_OK');
""".replace("__JS__",case).replace("__HIDE__","true" if should_hide_button else "false").replace(
                    "__DIRECTION__",json.dumps("rtl" if locale.startswith("ar") else "ltr")
                ).replace("__HEADING__",json.dumps({
                    "ar":"صلاحية الكتابة الدائمة — Host Authority",
                    "en":"Persistent write access — Host Authority",
                    "fr":"Accès en écriture permanent — Host Authority",
                    "de":"Dauerhafter Schreibzugriff — Host Authority",
                    "es":"Acceso de escritura permanente — Host Authority",
                    "it":"Accesso di scrittura permanente — Host Authority",
                }.get(locale[:2],"Persistent write access — Host Authority"),ensure_ascii=False))
                run=subprocess.run(["node"],input=js,text=True,capture_output=True,timeout=15)
                self.assertEqual(run.returncode,0,run.stderr[-1500:])

    def test_user_locale_from_wordpress_not_site_or_wpml(self):
        for loc in ("en_US","ar_EG","fr_FR","de_DE","es_ES","it_IT","ja_JP"):
            with self.subTest(locale=loc):
                out=self.php_fixture(locale=loc)
                self.assertIn('"userLocale":"'+loc+'"',out["html"])
                self.assertNotIn('get_locale()',SOURCE.read_text())
                self.assertNotIn('ICL_LANGUAGE_CODE',SOURCE.read_text())
        self.assertIn('get_user_locale()',SOURCE.read_text())

    def test_no_legacy_mutation_or_signing(self):
        source=SOURCE.read_text()
        self.assertIn("latest_owner_review()",source)
        self.assertIn("requested_by_user_id",source)
        self.assertIn("MAD4B_Host_V3_Owner_Console::site_binding_valid()",source)
        self.assertIn('layout.style.display="block"',source)
        self.assertIn('advancedBody.append(card)',source)
        self.assertIn('"requestButton":"تسجيل طلب مراجعة"',source)
        self.assertIn('"copyId":"نسخ رقم الطلب"',source)
        self.assertIn('"advancedDesc":"البيانات الموقّعة وطلبات REST مخصصة لفريق التشغيل فقط',source)
        for bad in ("wp_set_current_user(","wp_create_user(","update_option(",
                    "delete_option(","sodium_crypto_sign_detached(",
                    "shell_exec(","proc_open(","wp_remote_post("):
            self.assertNotIn(bad,source)

if __name__=="__main__":unittest.main()
