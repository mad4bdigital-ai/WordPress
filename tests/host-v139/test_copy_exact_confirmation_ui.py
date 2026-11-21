"""v139 regression: exact confirmation placeholder was not copyable in v134."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"deploy/host-authority/reconciliation/v136/430-mad4b-host-authority-human-ui.php"
fixture_spec=importlib.util.spec_from_file_location(
    "v136_fixture_copy",ROOT/"tests/host-v136/test_human_ui_contract.py")
mod=importlib.util.module_from_spec(fixture_spec)
fixture_spec.loader.exec_module(mod)
fixture=mod.HumanUIContractTests()
EXACT="REQUEST EXACT V3 OWNER STEP-UP"

class CopyConfirmationUiTests(unittest.TestCase):
    def test_real_v134_owner_confirmation_is_exact_source(self):
        old=(ROOT/"deploy/host-authority/reconciliation/v134/420-mad4b-host-v3-owner-console.php").read_text()
        self.assertIn("const CONFIRM = 'REQUEST EXACT V3 OWNER STEP-UP';",old)
        self.assertIn("esc_attr(self::CONFIRM)", old)

    def test_dom_copy_and_fallback_without_duplicate_approval(self):
        for scenario,success in (("existing",True),("existing",False),("empty",True)):
            with self.subTest(scenario=scenario,clipboard_success=success):
                html=fixture.php_fixture(scenario,locale="en_US")["html"]
                matches=re.findall(r'<script id="mad4b-host-v136-human-script">(.*?)</script>',html,re.S)
                self.assertEqual(len(matches),1)
                js=matches[0]
                test=r'''
const vm=require("node:vm"),assert=require("node:assert/strict");
class El {
 constructor(tag) {
  this.tag=tag;this.className="";this.children=[];this.parentNode=null;this.style={};
  this.textContent="";this.attrs={};this.hidden=false;this.disabled=false;this.listeners={};
  this.classList={add(){},remove(){}};
 }
 append(...els){for(const e of els){if(e && typeof e==="object"){this.children.push(e);e.parentNode=this;}}}
 prepend(e){this.children.unshift(e);e.parentNode=this;}
 insertBefore(e,ref){const idx=this.children.indexOf(ref);
  if(idx<0)this.children.push(e);else this.children.splice(idx,0,e);
  e.parentNode=this;}
 setAttribute(k,v){this.attrs[k]=v;}
 addEventListener(k,cb){this.listeners[k]=cb;}
 contains(e){return e===this||this.children.some(c=>c.contains(e));}
 get firstChild(){return this.children[0]||null;}
 get lastChild(){return this.children[this.children.length-1]||null;}
 get nextSibling(){const p=this.parentNode;if(!p)return null;
  return p.children[p.children.indexOf(this)+1]||null;}
}
const input=new El("input");
input.placeholder=__EXACT__;
input.value="";
input.focus=function(){this.focused=true;};
input.select=function(){this.selected=true;};
const legacyForm=new El("section");
legacyForm.className="v3-card";
legacyForm.append(input);
const submit=new El("button");
submit.textContent="Request independent Owner review";
legacyForm.append(submit);
const main=new El("div"),side=new El("div"),layout=new El("div");
layout.append(main,side);
layout.querySelectorAll=()=>[legacyForm];
const page={querySelector(q){
 if(q===".v3-layout")return layout;
 if(q==="h1")return new El("h1");
 return null;}};
const document={
 readyState:"complete",
 createElement:t=>new El(t),
 querySelector:q=>q===".mad4b-host-v3"?page:null,
 getElementById:id=>id==="v3-confirm"?input:(id==="v3-submit"?submit:null)
};
const copyEvents=[];
const navigator={clipboard:{writeText:async text=>{
 copyEvents.push(text);
 if(!__SUCCESS__)throw Error("blocked");
}}};
vm.runInNewContext(__JS__,{document,Intl,Date,Math,console,navigator,
 fetch:async()=>({ok:true,status:200,json:async()=>({signed_root_offer_present:false})})});
(async()=>{
 const newBox=legacyForm.children.find(x=>x.className==="human-exact-copy");
 assert.ok(newBox,"Copyable confirmation panel is absent");
 const code=newBox.children.find(x=>x.tag==="code");
 const btn=newBox.children.find(x=>x.tag==="button");
 const copyStatus=newBox.children.find(x=>x.className==="human-status");
 assert.equal(code.textContent,__EXACT__);
 assert.equal(code.dir,"ltr");
 assert.ok(btn.textContent.toLowerCase().includes("copy"));
 assert.equal(btn.type,"button");
 await btn.listeners.click();
 assert.deepEqual(copyEvents,[__EXACT__]);
 if(__SUCCESS__){
  assert.ok(copyStatus.textContent.includes("copied"));
  assert.equal(input.value,"");
 } else {
  assert.equal(input.value,__EXACT__);
  assert.equal(input.focused,true);
  assert.equal(input.selected,true);
  assert.ok(copyStatus.textContent.includes("Ctrl+C"));
 }
 if(__EXISTING__){
  assert.equal(submit.hidden,true);
  assert.equal(submit.style.display,"none");
  assert.equal(submit.disabled,true);
  assert.equal(submit.attrs["aria-hidden"],"true");
 }else{
  assert.equal(submit.hidden,false);
 }
 console.log("V139_OWNER_CONFIRMATION_EXACT_COPY_PASS");
})().catch(e=>{console.error(e.stack);process.exitCode=1});
'''.replace("__JS__",json.dumps(js,ensure_ascii=False)).replace("__EXACT__",json.dumps(EXACT)).replace(
    "__SUCCESS__","true" if success else "false").replace("__EXISTING__","true" if scenario!="empty" else "false")
                run=subprocess.run(["node"],input=test,text=True,capture_output=True,timeout=15)
                self.assertEqual(run.returncode,0,run.stderr[-1600:])
                self.assertIn("V139_OWNER_CONFIRMATION_EXACT_COPY_PASS",run.stdout)

    def test_exact_copy_only_in_advanced_no_signer_or_extra_endpoint(self):
        s=SRC.read_text()
        self.assertIn('advancedBody.append(card)',s)
        self.assertIn('exactInput.placeholder',s)
        self.assertIn('copyStatus.setAttribute("aria-live","polite")',s)
        self.assertIn('navigator.clipboard.writeText(exact)',s)
        self.assertIn('exactInput.select()',s)
        self.assertIn('copyArea.append',s)
        self.assertIn('duplicateSubmit.hidden=true',s)
        self.assertNotIn("REQUEST EXACT V3 OWNER STEP-UP",s)
        for forbidden in ("register_rest_route(", "update_option(","delete_option(","sodium_crypto_sign_detached(",
                          "wp_set_current_user(", "wp_create_user(", "exec(", "shell_exec("):
            self.assertNotIn(forbidden,s)

if __name__=="__main__":unittest.main()
