const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const candidate=fs.readFileSync(process.env.MAD4B_CANDIDATE_JS_FILE ||
   require('node:path').resolve(__dirname,'../../../wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js'),'utf8');
 const b=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 const p=await b.newPage({viewport:{width:390,height:820},isMobile:true,deviceScaleFactor:1,hasTouch:true});
 const errors=[];let intercepted=0;
 p.on('pageerror',e=>errors.push(String(e).slice(0,260)));
 await p.route('**/unified-date-v0.4.3.js*',r=>{
    intercepted++;return r.fulfill({status:200,contentType:'application/javascript',body:candidate});
 });
 const u='https://tours.dev.mad4b.com/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/?wcmlc=EGP&mad4b_date_mode=fixed&mad4b_date_from=2026-12-15';
 await p.goto(u,{waitUntil:'domcontentloaded',timeout:60000});
 await p.locator('.mad4b-ud-rate-apply:visible').first().waitFor({timeout:15000});
 const snap=()=>p.evaluate(()=>{
  const r=document.querySelector('.mad4b-ud-rate-select'),a=document.querySelector('.mad4b-ud-rate-apply');
  const rect=e=>{if(!e)return null;const r=e.getBoundingClientRect();return{x:Math.round(r.x),width:Math.round(r.width),right:Math.round(r.right),visible:r.width>0&&r.height>0}};
  return {width:innerWidth,root:rect(r),apply:rect(a),
   fallbackCount:document.querySelectorAll('.mad4b-rate-filter-row--date-only').length,
   applyCount:document.querySelectorAll('.mad4b-ud-rate-apply').length,
   url:location.search,
   formDate:document.querySelector('form.fluent_form_6 [name="datetime"]')?.value??null,
   scripts:[...document.querySelectorAll('script[src*="unified-date-v0.4.3"]')].length};
 });
 const before=await snap();
 assert(before.fallbackCount===1&&before.applyCount===1,'exactly one fallback toolbar');
 assert(before.apply.visible&&before.apply.x>=0&&before.apply.right<=390,'apply fits mobile viewport');
 assert(before.root.visible&&before.root.x>=0&&before.root.right<=390,'date trigger fits mobile viewport');
 await p.locator('.mad4b-ud__trigger:visible').first().click();
 await p.locator('body>.mad4b-ud__panel .mad4b-ud__clear:visible').first().click();
 const response=p.waitForResponse(r=>r.url().includes('admin-ajax.php')&&new URLSearchParams(r.request().postData()||'').get('query[mad4b_date_clear]')==='1',{timeout:40000});
 await p.locator('.mad4b-ud-rate-apply:visible').first().click();
 await response;
 await p.waitForTimeout(900);
 const after=await snap();
 assert(after.applyCount===1,'rate AJAX must not duplicate the Apply button');
 assert(after.fallbackCount===1,'rate AJAX must not duplicate toolbar');
 assert(!after.url.includes('mad4b_date_from'),'date clear must persist');
 assert(after.formDate==='','Form6 date clear must persist');
 assert(errors.length===0,'no page errors');
 console.log(JSON.stringify({state:'PASS',test:'mobile_390_candidate_043_rate_fallback',
  injected:intercepted,before,after,errors},null,2));
 await b.close();
})().catch(e=>{console.error('FAIL '+String(e).slice(0,1350));process.exitCode=1});
