"""v138 bug regression: WordPress .button overrides UA [hidden], no duplicate review."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"deploy/host-authority/reconciliation/v136/430-mad4b-host-authority-human-ui.php"
spec=importlib.util.spec_from_file_location(
    "v136_fixture_remote_review",ROOT/"tests/host-v136/test_human_ui_contract.py")
fixture_module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture_module)
fixture=fixture_module.HumanUIContractTests()

def scripts_for(scenario="existing",locale="en_US"):
    html=fixture.php_fixture(scenario,locale=locale)["html"]
    found=re.findall(r'<script id="mad4b-host-v136-human-script">(.*?)</script>',html,re.S)
    assert len(found)==1
    return found[0]

class OwnerReviewButtonCSSRegressionTests(unittest.TestCase):
    def test_css_author_rule_overrides_wordpress_button_display(self):
        source=SRC.read_text()
        self.assertRegex(source,r'(?s)\.mad4b-host-v3 \.mad4b-human \[hidden\]\s*\{\s*display\s*:\s*none\s*!important')
        self.assertIn('primary.style.display=show?"inline-flex":"none"',source)
        self.assertIn('primary.hidden=!show',source)
        self.assertIn('primary.disabled=!show',source)
        self.assertIn('primary.textContent=show?label:""',source)
        self.assertIn('primary.setAttribute("aria-hidden",show?"false":"true")',source)
        self.assertIn('secondary.style.marginInlineStart=show?"8px":"0"',source)
        self.assertIn('} finally {if (!primary.hidden) primary.disabled=false;}',source)

    def test_historical_request_does_not_trigger_another_post(self):
        for scenario in ("existing","expired"):
            for locale in ("en_US","ar_EG"):
                with self.subTest(scenario=scenario,locale=locale):
                    script=scripts_for(scenario,locale)
                    js=r"""
const vm=require("node:vm"),assert=require("node:assert/strict");
class El {
 constructor(tag) {
   this.tag=tag;this.className="";this.children=[];this.style={};this.textContent="";
   this.hidden=false;this.disabled=false;this.attrs={};this.listeners={};
   this.classList={add(){},remove(){}};}
 append(...els){for(const el of els){this.children.push(el);el.parent=this;}}
 prepend(el){this.children.unshift(el);el.parent=this;}
 setAttribute(k,v){this.attrs[k]=v;}
 contains(el){return this===el||this.children.some(e=>e.contains(el));}
 get firstChild(){return this.children[0]||null}
 get lastChild(){return this.children[this.children.length-1]||null}
 addEventListener(k,cb){this.listeners[k]=cb}
}
const main=new El("div"),side=new El("div"),layout=new El("div");
layout.append(main,side);
layout.querySelectorAll=()=>[];
const heading=new El("h1");
const page={querySelector(q) {
  if(q===".v3-layout")return layout;
  if(q==="h1")return heading;
  return null;
}};
const document={readyState:"complete",createElement:x=>new El(x),
 querySelector:q=>q===".mad4b-host-v3"?page:null};
const calls=[];
const fetch=async (url,options)=>{
 calls.push({url,method:options.method});
 return {ok:true,status:200,json:async()=>({signed_root_offer_present:false})};
};
vm.runInNewContext(__SCRIPT__,{document,fetch,Intl,Date,Math,console,
 navigator:{clipboard:{writeText:async()=>{}}}});
(async()=>{
 await Promise.resolve();
 const pane=main.children[0];
 const actions=pane.children.find(x=>x.className==="human-actions");
 assert.ok(actions,"The owner action section must render");
 const btns=actions.children.filter(x=>x.tag==="button");
 assert.equal(btns.length,2);
 const primary=btns[0],copy=btns[1];
 assert.equal(primary.hidden,true);
 assert.equal(primary.style.display,"none");
 assert.equal(primary.disabled,true);
 assert.equal(primary.textContent,"");
 assert.equal(primary.attrs["aria-hidden"],"true");
 assert.equal(copy.hidden,false);
 assert.equal(copy.style.display,"inline-flex");
 assert.equal(copy.style.marginInlineStart,"0");
 assert.ok(copy.textContent.length>3);
 assert.ok(!actions.children.find(x=>x.className==="human-status").textContent.includes("Register review request"));
 assert.equal(calls.filter(x=>x.method==="POST").length,0,"Do not create a duplicate request");
 const steps=pane.children.find(x=>x.tag==="ol");
 assert.equal(steps.children[1].children[0].textContent,"✓","Retain the recorded review milestone");
 console.log("V138_WORDPRESS_HIDDEN_BUTTON_AND_REVIEW_GATE_PASS");
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
""".replace("__SCRIPT__",json.dumps(script,ensure_ascii=False))
                    p=subprocess.run(["node"],input=js,text=True,capture_output=True,timeout=14)
                    self.assertEqual(p.returncode,0,p.stdout[-1000:]+"\n"+p.stderr[-1500:])
                    self.assertIn("V138_WORDPRESS_HIDDEN_BUTTON_AND_REVIEW_GATE_PASS",p.stdout)

    def test_single_primary_action_for_no_review(self):
        script=scripts_for("empty","en_US")
        self.assertIn('showPrimary(true,hasReview?tr("renewButton"):tr("requestButton"))',script)
        self.assertIn('primary.style.display=show?"inline-flex":"none"',script)
        self.assertIn('secondary.style.display=show?"inline-flex":"none"',script)

    def test_six_locales_cover_review_expired_pending(self):
        script=scripts_for("expired","en_US")
        match=re.search(r'const strings = (\{.*?\});\s*const tr = ',script,re.S)
        self.assertIsNotNone(match)
        strings=json.loads(match.group(1))
        for locale in ("en","ar","fr","de","es","it"):
            with self.subTest(locale=locale):
                for key in ("reviewExpiryWaitingDesc","reviewReceivedTitle",
                            "reviewExpiredWaiting","technicalPendingHelp"):
                    self.assertGreater(len(strings[locale][key].strip()),20)
        self.assertIn("reviewStep.label.textContent=tr(\"reviewRecorded\")",script)
        self.assertIn("status.textContent=tr(\"reviewExpiredWaiting\")",script)

    def test_root_trust_unchanged_and_no_new_server_post(self):
        source=SRC.read_text()
        for forbidden in (
            "register_rest_route(", "update_option(", "delete_option(",
            "wp_set_current_user(", "wp_create_user(", "proc_open(",
            "sodium_crypto_sign_detached(", "openssl_sign(", "shell_exec(",
            "wp_remote_post("
        ):
            self.assertNotIn(forbidden,source)
        self.assertIn("MAD4B_Host_V3_Owner_Console::site_binding_valid()",source)
        self.assertIn('confirmation:"REQUEST REMOTE V3 OWNER REVIEW"',source)

if __name__=="__main__":unittest.main()
