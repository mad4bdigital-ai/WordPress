const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const candidate=fs.readFileSync(process.env.MAD4B_CANDIDATE_JS_FILE ||
   require('node:path').resolve(__dirname,'../../../wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js'),'utf8');
 assert(candidate.includes('mad4b-rate-filter-row--date-only'));
 const b=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 const p=await b.newPage({viewport:{width:1366,height:900}});
 const errors=[],nativeRequests=[];let intercepted=0;
 p.on('pageerror',e=>errors.push(String(e).slice(0,250)));
 await p.route('**/unified-date-v0.4.3.js*',r=>{intercepted++;return r.fulfill({status:200,contentType:'application/javascript',body:candidate})});
 p.on('request',r=>{
  if(r.url().includes('admin-ajax.php')&&r.method()==='POST'){
    const d=new URLSearchParams(r.postData()||'');
    if(d.get('action')==='jet_smart_filters')
      nativeRequests.push({provider:d.get('provider'),
      clear:d.get('query[mad4b_date_clear]'),dateFrom:d.get('query[mad4b_date_from]')});
  }
 });
 const url='https://tours.dev.mad4b.com/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/?wcmlc=USD&mad4b_date_mode=flex&mad4b_date_from=2026-11-18&mad4b_date_to=2026-11-30';
 await p.goto(url,{waitUntil:'domcontentloaded',timeout:62000});
 await p.locator('.mad4b-ud__trigger:visible').first().waitFor({timeout:16000});
 const snap=()=>p.evaluate(()=>({
  query:location.search,active:document.querySelector('.mad4b-ud-active-date')?.textContent.trim()??null,
  dateButton:[...document.querySelectorAll('.mad4b-ud__trigger')].find(e=>e.getClientRects().length)?.textContent.trim(),
  apply:[...document.querySelectorAll('.mad4b-ud-rate-apply')].filter(e=>e.getClientRects().length).map(e=>({text:e.textContent.trim(),disabled:e.disabled})),
  formDate:document.querySelector('form.fluent_form_6 [name="datetime"]')?.value??null,
  langFr:[...document.querySelectorAll('a.wpml-ls-link')].find(e=>e.href.includes('/fr/'))?.href??null,
  source:document.querySelector('script[src*="unified-date-v0.4.3"]')?.src.split('/').pop()
 }));
 const before=await snap();
 assert.equal(intercepted,1);
 assert(before.apply.length>0,'candidate should create visible Apply');
 await p.locator('.mad4b-ud__trigger:visible').first().click();
 await p.locator('body>.mad4b-ud__panel .mad4b-ud__clear:visible').first().click();
 await p.waitForTimeout(120);
 const staged=await snap();
 assert(new URL('https://tours.dev.mad4b.com/'+staged.query).searchParams.has('mad4b_date_from'),
    'draft clear must not automatically apply date');
 assert(staged.dateButton?.includes('Pending'),'staged clear visible');
 const next=p.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
   new URLSearchParams(r.request().postData()||'').get('query[mad4b_date_clear]')==='1',{timeout:38000});
 await p.locator('.mad4b-ud-rate-apply:visible').first().click();
 await next;
 await p.waitForTimeout(750);
 const after=await snap();
 assert(!new URL('https://tours.dev.mad4b.com/'+after.query).searchParams.has('mad4b_date_from'),'clear removes URL date');
 assert(after.formDate==='','clear removes Form #6 value');
 assert(!after.langFr?.includes('mad4b_date_from'),'clear removes WPML URL date');
 assert(nativeRequests.some(x=>x.clear==='1'),'native JSF clear AJAX sent');
 assert(errors.length===0,'no JS errors');
 console.log(JSON.stringify({state:'PASS',test:'v043_candidate_fallback_apply',
   injectedSource:true,intercepted,before,staged,after,
   nativeClearRequests:nativeRequests.filter(x=>x.clear==='1'),errors},null,2));
 await b.close();
})().catch(e=>{console.error('FAIL '+String(e).slice(0,1350));process.exitCode=1});
