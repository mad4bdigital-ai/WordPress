"""v141: already-completed WordPress review must not surface repeat approvals."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"deploy/host-authority/reconciliation/v136/430-mad4b-host-authority-human-ui.php"
spec=importlib.util.spec_from_file_location("v141_v136_fixture",ROOT/"tests/host-v136/test_human_ui_contract.py")
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
fixture=mod.HumanUIContractTests()

class ReviewDedupTests(unittest.TestCase):
    def test_existing_review_hides_v134_confirmation_and_v140_copy_server_side(self):
        for locale in ("en_US","ar_EG","fr_FR","de_DE","es_ES","it_IT"):
            with self.subTest(locale=locale):
                html=fixture.php_fixture("expired",locale=locale)["html"]
                self.assertIn('.mad4b-host-v3 section.v3-card[aria-labelledby="v3-review"] {display:none !important}',html)
                block=re.search(r'<div id="mad4b-owner-copy-ssr-v140".*?</div>',html,re.S)
                self.assertIsNotNone(block)
                self.assertIn('hidden role=',block.group(0))
                self.assertIn('data-ui-version="v141"',block.group(0))

    def test_no_review_keeps_original_technical_copy_available(self):
        html=fixture.php_fixture("empty",locale="en_US")["html"]
        self.assertNotIn('.mad4b-host-v3 section.v3-card[aria-labelledby="v3-review"] {display:none !important}',html)
        block=re.search(r'<div id="mad4b-owner-copy-ssr-v140".*?</div>',html,re.S)
        self.assertIsNotNone(block)
        self.assertNotIn('hidden role=',block.group(0))

    def test_v135_remote_review_dedupe_without_blocking_signed_proof(self):
        for signed_offer in (False,True):
            with self.subTest(signed_offer=signed_offer):
                html=fixture.php_fixture("expired",locale="en_US")["html"]
                scripts=re.findall(r'<script id="mad4b-host-v136-human-script">(.*?)</script>',html,re.S)
                self.assertEqual(len(scripts),1)
                test=r"""
const vm=require("node:vm"),assert=require("node:assert/strict");
class E {
 constructor(tag) {
  this.tag=tag;this.className="";this.children=[];this.style={};
  this.hidden=false;this.disabled=false;this.textContent="";
  this.attrs={};this.listeners={};this.classList={add(){},remove(){}};
 }
 append(...es){for(const e of es){if(typeof e==='object')this.children.push(e);}}
 prepend(e){this.children.unshift(e);}
 setAttribute(k,v){this.attrs[k]=v;}
 get firstChild(){return this.children[0]||null;}
 get lastChild(){return this.children[this.children.length-1]||null;}
 contains(x){return this===x||this.children.some(y=>y.contains(x));}
 addEventListener(k,cb){this.listeners[k]=cb;}
}
const remote=new E("section"),h2=new E("h2");
h2.textContent="Remote Owner Step-up";
const buttons=[new E("button"),new E("button"),new E("button")];
remote.querySelector=q=>q==="h2"?h2:null;
remote.querySelectorAll=q=>q==="button"?buttons:[];
const main=new E("div"),side=new E("div"),layout=new E("div");
layout.append(main,side);layout.querySelectorAll=()=>[remote];
const page={querySelector(q){return q===".v3-layout"?layout:(q==="h1"?new E("h1"):null);}};
const document={readyState:"complete",createElement:tag=>new E(tag),
 querySelector:q=>q===".mad4b-host-v3"?page:null};
const calls=[];
const fetch=async (url,opts)=>{
 calls.push({url,method:opts.method});
 return {ok:true,status:200,json:async()=>({signed_root_offer_present:__OFFER__})};
};
vm.runInNewContext(__SCRIPT__,{document,fetch,Intl,Date,Math,console,
 navigator:{clipboard:{writeText:async()=>{}}}});
(async()=>{
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(remote.hidden,!__OFFER__);
 assert.equal(remote.style.display,__OFFER__?"block":"none");
 for(let i=0;i<2;i++){
  assert.equal(buttons[i].hidden,true);
  assert.equal(buttons[i].disabled,true);
  assert.equal(buttons[i].style.display,"none");
  assert.equal(buttons[i].attrs["aria-hidden"],"true");
 }
 assert.equal(buttons[2].disabled,false);
 assert.equal(buttons[2].hidden,false);
 assert.equal(calls.filter(c=>c.method==="POST").length,0);
 console.log("V141_REVIEW_DEDUP_AND_SIGNED_PROOF_PRESERVED_PASS");
})().catch(e=>{console.error(e.stack);process.exitCode=1});
""".replace("__SCRIPT__",json.dumps(scripts[0],ensure_ascii=False)).replace("__OFFER__","true" if signed_offer else "false")
                p=subprocess.run(["node"],input=test,text=True,capture_output=True,timeout=12)
                self.assertEqual(p.returncode,0,p.stderr[-1600:])
                self.assertIn("V141_REVIEW_DEDUP_AND_SIGNED_PROOF_PRESERVED_PASS",p.stdout)

    def test_no_server_mutations_or_authority_change(self):
        source=SRC.read_text()
        self.assertIn("MAD4B_Host_V3_Owner_Console::site_binding_valid()",source)
        self.assertIn('remoteCard.style.display=rootOffer?"block":"none"',source)
        for forbidden in ("register_rest_route(", "update_option(", "delete_option(",
                          "sodium_crypto_sign_detached(", "wp_set_current_user(",
                          "shell_exec(", "wp_remote_post("):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":unittest.main()
