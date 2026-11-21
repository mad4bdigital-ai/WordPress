"""v140: trusted PHP renders a selectable exact confirmation before JS runs."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"deploy/host-authority/reconciliation/v136/430-mad4b-host-authority-human-ui.php"
spec=importlib.util.spec_from_file_location("v140_test_fixture",
    ROOT/"tests/host-v136/test_human_ui_contract.py")
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
fixture=mod.HumanUIContractTests()
PHRASE="REQUEST EXACT V3 OWNER STEP-UP"
LOCALES={'en_US':'Copy exact confirmation','ar_EG':'نسخ عبارة التأكيد',
         'fr_FR':'Copier la confirmation','de_DE':'Bestätigung kopieren',
         'es_ES':'Copiar confirmación','it_IT':'Copia conferma'}

class ServerRenderedExactCopyTests(unittest.TestCase):
    def test_server_output_is_selectable_and_visible_without_js(self):
        for locale,label in LOCALES.items():
            with self.subTest(locale=locale):
                html=fixture.php_fixture("existing",locale=locale)["html"]
                ssr=re.search(r'<div id="mad4b-owner-copy-ssr-v140".*?</div>',html,re.S)
                self.assertIsNotNone(ssr)
                chunk=ssr.group(0)
                self.assertIn('data-ui-version="v141"',chunk)
                self.assertIn("hidden role=",chunk)
                self.assertIn('type="text" readonly value="'+PHRASE+'"',chunk)
                self.assertIn('id="mad4b-exact-confirm-select-v140"',chunk)
                self.assertIn(label,chunk)
                self.assertIn('aria-live="polite"',chunk)
                self.assertIn('id="mad4b-owner-copy-btn-v140"',chunk)
                self.assertIn('Your review is already recorded' if locale=="en_US" else '',
                    chunk)
                # Browser can select the readonly field without any
                # script, WordPress REST, or synthetic Owner signing key.
                self.assertLess(html.index('id="mad4b-owner-copy-ssr-v140"'),
                                html.index('id="mad4b-host-v136-human-script"'))

    def test_missing_owner_review_keeps_copy_but_no_duplicate_warning(self):
        h=fixture.php_fixture("empty",locale="en_US")["html"]
        area=h.split('id="mad4b-owner-copy-ssr-v140"',1)[1].split('</div>',1)[0]
        self.assertIn(PHRASE,area)
        self.assertNotIn("Your review is already recorded",area)
        self.assertNotIn("hidden role=",area)

    def test_unprivileged_user_has_no_confirmation_panel(self):
        h=fixture.php_fixture("existing",privileged=False)["html"]
        self.assertEqual(h,"")

    def test_standalone_clipboard_script_parses_and_has_native_select_fallback(self):
        h=fixture.php_fixture("existing",locale="en_US")["html"]
        js=re.search(r'<script id="mad4b-owner-v140-copy-standalone">(.*?)</script>',h,re.S)
        self.assertIsNotNone(js)
        code=js.group(1)
        res=subprocess.run(["node","--check"],input=code,text=True,capture_output=True,timeout=10)
        self.assertEqual(res.returncode,0,res.stderr)
        self.assertIn('target.insertAdjacentElement("afterend",area)',code)
        self.assertIn('navigator.clipboard.writeText(input.value)',code)
        self.assertIn('input.focus()',code)
        self.assertIn('input.select()',code)
        self.assertIn('oldSubmit.hidden=true',code)
        self.assertNotIn('fetch(',code)

    def test_standalone_script_repositions_and_copies_without_main_hydration(self):
        for success in (True,False):
            with self.subTest(clipboard=success):
                h=fixture.php_fixture("existing",locale="en_US")["html"]
                js=re.search(r'<script id="mad4b-owner-v140-copy-standalone">(.*?)</script>',h,re.S)
                self.assertIsNotNone(js)
                case=r"""
const vm=require('node:vm');
const assert=require('node:assert/strict');
class Elem {
 constructor(id){
 this.id=id;this.textContent='';this.attrs={};this.hidden=false;this.style={};
 this.disabled=false;this.listeners={};this.value='';
 }
 addEventListener(ev,fn){this.listeners[ev]=fn;}
 setAttribute(k,v){this.attrs[k]=v;}
 insertAdjacentElement(where,element){this.repositioned={where,element};}
 focus(){this.focused=true;}
 select(){this.selected=true;}
}
const exact=new Elem('v3-confirm'),area=new Elem('mad4b-owner-copy-ssr-v140'),
 input=new Elem('mad4b-exact-confirm-select-v140'),
 btn=new Elem('mad4b-owner-copy-btn-v140'),status=new Elem('mad4b-owner-copy-status-v140'),
 legacy=new Elem('v3-submit');
input.value='REQUEST EXACT V3 OWNER STEP-UP';
const ids=Object.fromEntries([exact,area,input,btn,status,legacy].map(e=>[e.id,e]));
const document={getElementById:k=>ids[k]||null};
const calls=[];
const navigator={clipboard:{writeText:async x=>{
 calls.push(x);if(!__SUCCESS__)throw Error("denied");
}}};
vm.runInNewContext(__JS__,{document,navigator,console});
assert.equal(exact.repositioned.where,'afterend');
assert.equal(exact.repositioned.element,area);
assert.equal(legacy.hidden,true);
assert.equal(legacy.style.display,'none');
assert.equal(legacy.disabled,true);
(async()=>{
 await btn.listeners.click();
 assert.deepEqual(calls,['REQUEST EXACT V3 OWNER STEP-UP']);
 if(__SUCCESS__)assert.ok(status.textContent.includes('copied'));
 else {
   assert.equal(input.focused,true);
   assert.equal(input.selected,true);
   assert.ok(status.textContent.includes('Ctrl+C'));
 }
 console.log('V140_STANDALONE_COPY_PASS');
})().catch(e=>{console.error(e.stack);process.exitCode=1});
""".replace('__SUCCESS__',"true" if success else "false").replace('__JS__',json.dumps(js.group(1)))
                r=subprocess.run(["node"],input=case,text=True,capture_output=True,timeout=12)
                self.assertEqual(r.returncode,0,r.stderr[-1250:])
                self.assertIn("V140_STANDALONE_COPY_PASS",r.stdout)

    def test_existing_v139_hydration_has_deduplication_guard(self):
        s=SRC.read_text()
        self.assertIn('document.getElementById("mad4b-owner-copy-ssr-v140")',s)
        self.assertIn('MAD4B_Host_V3_Owner_Console::CONFIRM',s)
        self.assertNotIn("REQUEST EXACT V3 OWNER STEP-UP",s)
        for banned in ("register_rest_route(","wp_create_user(","update_option(",
                       "shell_exec(","sodium_crypto_sign_detached(","openssl_sign("):
            self.assertNotIn(banned,s)

if __name__=="__main__":unittest.main()
