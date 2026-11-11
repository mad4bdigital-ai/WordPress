const{chromium}=require('playwright');const assert=require('node:assert/strict');
(async()=>{
const b=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
const p=await b.newPage({viewport:{width:1366,height:900}});
await p.route('**/*',r=>r.request().resourceType()==='image'?r.abort():r.continue());
const errors=[],requests=[];
p.on('pageerror',e=>errors.push(String(e).slice(0,250)));
p.on('request',r=>{if(r.url().includes('admin-ajax.php')&&r.method()==='POST'){const d=new URLSearchParams(r.postData()||'');if(d.get('action')==='jet_smart_filters')requests.push({from:d.get('query[mad4b_date_from]'),to:d.get('query[mad4b_date_to]'),mode:d.get('query[mad4b_date_mode]')})}});
const base='https://tours.dev.mad4b.com/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/';
await p.goto(base+'?wcmlc=EUR&mad4b_date_mode=flex&mad4b_date_from=2026-11-18&mad4b_date_to=2026-11-30',{waitUntil:'domcontentloaded',timeout:65000});await p.waitForTimeout(800);
const state=()=>p.evaluate(()=>({applied:new URL(location.href).searchParams.get('mad4b_date_from'),end:new URL(location.href).searchParams.get('mad4b_date_to'),count:Number(document.querySelector('.jet-engine-query-count.query-310')?.textContent),selected:window.SharedPresentationSelectionV1?.getSnapshot()?.selectedRateId,
summary:document.querySelector('.mad4b-rate-filter-row .mad4b-ud__summary')?.textContent.trim(),
formFrom:document.querySelector('form.fluent_form_6 [name="datetime"]')?.value,formTo:document.querySelector('form.fluent_form_6 [name="datetime_2"]')?.value,formMode:document.querySelector('form.fluent_form_6 [name="tour_date_flexibility"]:checked')?.value,invalid:document.querySelector('form.fluent_form_6')?.dataset.mad4bDateDraftInvalid,
warning:document.querySelector('.mad4b-date-form-review:not([hidden])')?.textContent.trim(),
currency:[...document.querySelectorAll('select.pricing-currency-select')].find(x=>!!x.getClientRects().length)?.value,
chip:document.querySelector('.mad4b-ud-active-date')?.textContent.trim(),applyDisabled:document.querySelector('.mad4b-ud-rate-apply')?.disabled
}));
const checkpoints=[];
await p.locator('.mad4b-rate-header-select[data-rate-id="60830"]').click();
await p.locator('.mad4b-rate-filter-row .mad4b-ud__trigger').click();
await p.locator('body>.mad4b-ud__panel [data-mode="fixed"]').click();
await p.evaluate(()=>document.querySelector('body>.mad4b-ud__panel .mad4b-ud__calendar-input')._flatpickr.setDate('2028-01-20',true,'Y-m-d'));
await p.locator('body>.mad4b-ud__panel .mad4b-ud__close').click();
let r=await state();assert.equal(r.applied,'2026-11-18');assert.equal(r.count,2);assert.equal(r.selected,60830);assert.equal(r.summary.includes('Pending'),false);
checkpoints.push('cancel-does-not-apply');
await p.locator('.mad4b-rate-filter-row .mad4b-ud__trigger').click();
await p.locator('body>.mad4b-ud__panel [data-mode="fixed"]').click();
await p.evaluate(()=>document.querySelector('body>.mad4b-ud__panel .mad4b-ud__calendar-input')._flatpickr.setDate('2028-01-20',true,'Y-m-d'));
await p.locator('body>.mad4b-ud__panel .mad4b-ud__apply').click();
r=await state();assert.equal(r.applied,'2026-11-18');assert.equal(r.formFrom,'18-Nov-26');assert.equal(r.count,2);assert.equal(r.selected,60830);assert.match(r.summary,/Pending/);
checkpoints.push('draft-does-not-mutate-forms-rates-or-selection');
const a=p.waitForResponse(r=>r.url().includes('admin-ajax.php')&&new URLSearchParams(r.request().postData()||'').get('query[mad4b_date_from]')==='2028-01-20',{timeout:42000});
await p.locator('.mad4b-ud-rate-apply').click();await a;await p.waitForFunction(()=>window.SharedPresentationSelectionV1?.getSnapshot()?.selectedRateId===null&&document.querySelector('.jet-engine-query-count.query-310')?.textContent==='0',null,{timeout:12000});await p.waitForTimeout(350);
r=await state();assert.equal(r.applied,'2028-01-20');assert.equal(r.count,0);assert.equal(r.formFrom,'20-Jan-28');assert.equal(r.formTo,'');assert.equal(r.formMode,'false');assert.equal(r.currency,'EUR');assert.equal(r.selected,null);
checkpoints.push('apply-ajax-clears-ineligible-rate-and-prefills-form');
await p.locator('form.fluent_form_6 [name="tour_date_flexibility"][value="true"]').check();
await p.evaluate(()=>document.querySelector('form.fluent_form_6 [name="datetime"]')._flatpickr.setDate('2026-12-15',true,'Y-m-d'));
await p.waitForTimeout(350);
r=await state();assert.equal(r.applied,'2028-01-20');assert.equal(r.invalid,'1');assert.equal(r.applyDisabled,true);assert.ok(r.warning);checkpoints.push('incomplete-flex-prevents-apply');
const bResp=p.waitForResponse(r=>r.url().includes('admin-ajax.php')&&new URLSearchParams(r.request().postData()||'').get('query[mad4b_date_from]')==='2026-12-15',{timeout:42000});
await p.evaluate(()=>document.querySelector('form.fluent_form_6 [name="datetime_2"]')._flatpickr.setDate('2026-12-20',true,'Y-m-d'));
await bResp;await p.waitForTimeout(900);
r=await state();assert.equal(r.applied,'2026-12-15');assert.equal(r.end,'2026-12-20');assert.equal(r.invalid,undefined);assert.equal(r.count,2);assert.equal(r.currency,'EUR');checkpoints.push('fluent-reverse-sync-ajax');
await p.locator('.mad4b-rate-header-select[data-rate-id="60820"]').click();
const cResp=p.waitForResponse(r=>r.url().includes('admin-ajax.php')&&new URLSearchParams(r.request().postData()||'').get('query[mad4b_date_mode]')==='fixed',{timeout:42000});
await p.locator('form.fluent_form_6 [name="tour_date_flexibility"][value="false"]').check();await cResp;await p.waitForTimeout(950);
r=await state();assert.equal(r.formMode,'false');assert.equal(r.formTo,'');assert.equal(r.selected,60820);assert.equal(r.count,2);
checkpoints.push('valid-rate-preserved-and-mode-synced');
const jqDate=await p.evaluate(()=>[...document.querySelectorAll('.mad4b-ud-rate-filter-ready .jet-date-range__control')].some(x=>x.classList.contains('hasDatepicker')));
assert.equal(jqDate,false);assert.equal(errors.length,0);
console.log(JSON.stringify({status:'PASS',checkpoints,requests,errors}));
await b.close()
})().catch(e=>{console.error('FAIL '+String(e).slice(0,1100));process.exitCode=1});
