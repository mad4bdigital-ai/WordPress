"""WordPress per-user admin locale; no WPML/site locale override or grant changes."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys
import unittest

ROOT=Path(__file__).resolve().parents[2]
LEGACY=ROOT/"tests/host-v136/test_human_ui_contract.py"
SPEC=importlib.util.spec_from_file_location("v136_human_ui_fixture",LEGACY)
MOD=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MOD)
FIXTURE=MOD.HumanUIContractTests()
PHP=ROOT/"deploy/host-authority/reconciliation/v136/430-mad4b-host-authority-human-ui.php"
LOCALES=["en_US","ar_EG","fr_FR","de_DE","es_ES","it_IT","ja_JP"]
HEADINGS={
 "en":"Persistent write access — Host Authority",
 "ar":"صلاحية الكتابة الدائمة — Host Authority",
 "fr":"Accès en écriture permanent — Host Authority",
 "de":"Dauerhafter Schreibzugriff — Host Authority",
 "es":"Acceso de escritura permanente — Host Authority",
 "it":"Accesso di scrittura permanente — Host Authority",
}

def script_source(html):
    matches=re.findall(
        r'<script id="mad4b-host-v136-human-script">(.*?)</script>',html,re.S
    )
    if len(matches)!=1:raise ValueError("Missing one exact WordPress Admin presentation script")
    return matches[0]

def catalog(script):
    match=re.search(r'const strings = (\{.*?\});\s*const tr = ',script,re.S)
    if not match:raise ValueError("Missing locale message catalog")
    return json.loads(match.group(1))

class WordPressUserLocaleContractTests(unittest.TestCase):
    def test_six_locales_have_identical_nonempty_message_keys(self):
        output=FIXTURE.php_fixture(locale="en_US")
        translated=catalog(script_source(output["html"]))
        self.assertEqual(set(translated),set(HEADINGS))
        keys=set(translated["en"])
        self.assertGreaterEqual(len(keys),49)
        for locale,messages in translated.items():
            self.assertEqual(set(messages),keys,locale)
            self.assertTrue(all(isinstance(x,str) and x.strip()
                for x in messages.values()))
            self.assertEqual(messages["pageHeading"],HEADINGS[locale])
            self.assertNotIn('tr("',json.dumps(messages,ensure_ascii=False))
        self.assertEqual(translated["en"]["requestButton"],"Register review request")
        self.assertEqual(translated["ar"]["requestButton"],"تسجيل طلب مراجعة")

    def test_admin_profile_locale_overrides_site_and_not_wpml(self):
        source=PHP.read_text()
        self.assertIn("'userLocale' => get_user_locale()",source)
        self.assertNotIn("get_locale()",source)
        self.assertNotIn("ICL_LANGUAGE_CODE",source)
        self.assertNotIn("wpml_current_language",source)
        self.assertNotIn('pane.dir = "rtl"',source)
        for loc in LOCALES:
            with self.subTest(user_locale=loc):
                html=FIXTURE.php_fixture(locale=loc)["html"]
                self.assertIn('"userLocale":"'+loc+'"',html)

    def test_english_and_arabic_statuses_are_not_mixed(self):
        html_en=FIXTURE.php_fixture(locale="en_US")["html"]
        html_ar=FIXTURE.php_fixture(locale="ar_EG")["html"]
        s_en=script_source(html_en)
        s_ar=script_source(html_ar)
        self.assertIn('const languageCode = String(settings.userLocale',s_en)
        self.assertIn('const intlLocale',s_en)
        self.assertIn('pane.dir = language === "ar" ? "rtl" : "ltr"',s_en)
        self.assertIn('new Intl.DateTimeFormat(intlLocale',s_en)
        self.assertIn('"currentExpires"',s_en)
        self.assertIn('tr("noDuplicate")',s_en)
        # Same source and catalog, only the signed-in WordPress user's
        # locale varies between sessions.
        self.assertIn('"userLocale":"en_US"',s_en)
        self.assertIn('"userLocale":"ar_EG"',s_ar)

    def test_backend_denial_messages_localized_for_each_locale(self):
        for loc in LOCALES:
            with self.subTest(locale=loc):
                script=script_source(FIXTURE.php_fixture("empty",locale=loc)["html"])
                messages=catalog(script)
                lang=loc[:2] if loc[:2] in HEADINGS else "en"
                data=json.dumps(script,ensure_ascii=False)
                expected=json.dumps(messages[lang]["requestDenied"],ensure_ascii=False)
                prefix=json.dumps(messages[lang]["requestFailed"],ensure_ascii=False)
                test_js=r"""
const assert=require('node:assert/strict');
const vm=require('node:vm');
class Elem{
 constructor(t){this.tag=t;this.className='';this.children=[];this.hidden=false;
   this.textContent='';this.dir='';this.style={};this.attrs={};this.listeners={};
   this.classList={add(){},remove(){}};}
 append(...els){for(const e of els){this.children.push(e);e.parent=this;}}
 prepend(e){this.children.unshift(e);e.parent=this;}
 setAttribute(k,v){this.attrs[k]=v;}
 addEventListener(t,fn){this.listeners[t]=fn;}
 contains(el){return el===this||this.children.some(x=>x.contains(el));}
 get firstChild(){return this.children[0]||null;}
 get lastChild(){return this.children[this.children.length-1]||null;}
}
const main=new Elem('div'),side=new Elem('div'),layout=new Elem('div');
layout.append(main,side);
layout.querySelectorAll=()=>[];
const heading=new Elem('h1');
const page={querySelector(q){
  if(q==='.v3-layout')return layout;
  if(q==='h1')return heading;
  if(q===':scope > p')return null;
  return null;
}};
const document={readyState:'complete',createElement:t=>new Elem(t),
querySelector:q=>q==='.mad4b-host-v3'?page:null,addEventListener(){}};
const calls=[];
const fetch=async(path,opt)=>{
 calls.push(path);
 if(path.endsWith('/review-plan')){
   return {ok:false,status:403,json:async()=>({code:'rest_forbidden',message:'English-only raw REST error'})};
 }
 return {ok:true,status:200,json:async()=>({signed_root_offer_present:false})};
};
vm.runInNewContext(__SOURCE__,{document,fetch,navigator:{clipboard:{writeText:async()=>{}}},
 Intl,Date,Math,console});
const pane=main.children[0];
const actions=pane.children.find(c=>c.className==='human-actions');
const button=actions.children.find(c=>c.tag==='button');
assert.equal(button.hidden,false);
(async()=>{
 await button.listeners.click();
 const status=actions.children.find(c=>c.className==='human-status');
 assert.ok(status.textContent.startsWith(__PREFIX__+__EXPECTED__),status.textContent);
 assert.ok(status.textContent.includes('[rest_forbidden]'));
 assert.ok(!status.textContent.includes('English-only raw REST error'));
 assert.equal(calls.filter(x=>x.endsWith('/remote-requests')).length,0);
 console.log('USER_LOCALE_ERROR_CONTRACT_PASS');
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
""".replace("__SOURCE__",data).replace("__EXPECTED__",expected).replace("__PREFIX__",prefix)
                p=subprocess.run(["node"],input=test_js,text=True,capture_output=True,timeout=12)
                self.assertEqual(p.returncode,0,p.stderr[-1200:])
                self.assertIn("USER_LOCALE_ERROR_CONTRACT_PASS",p.stdout)

if __name__=="__main__":unittest.main()
