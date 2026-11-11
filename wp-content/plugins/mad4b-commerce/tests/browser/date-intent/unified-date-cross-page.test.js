const {chromium}=require('playwright');const assert=require('node:assert/strict');
(async()=>{const b=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});const results=[];const errors=[];
const tour='https://tours.dev.mad4b.com/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/';
{
const p=await b.newPage({viewport:{width:390,height:844}});await p.route('**/*',r=>r.request().resourceType()==='image'?r.abort():r.continue());p.on('pageerror',e=>errors.push('mobile:'+String(e)));
await p.goto(tour+'?wcmlc=EGP&mad4b_date_mode=flex&mad4b_date_from=2026-11-18&mad4b_date_to=2026-11-30',{waitUntil:'domcontentloaded',timeout:65000});await p.waitForTimeout(800);
await p.locator('.mad4b-rate-filter-row .mad4b-ud__trigger').click();const mobile=await p.evaluate(()=>{const modal=document.querySelector('body > .mad4b-ud__panel:not([hidden])')?.getBoundingClientRect(),row=document.querySelector('.mad4b-rate-filter-row')?.getBoundingClientRect(),original=document.querySelector('#ui-datepicker-div')?.getBoundingClientRect();return{modal:!!modal,overflowX:modal?Math.max(0,-modal.left)+Math.max(0,modal.right-innerWidth):null,rowOverflow:row?Math.max(0,row.right-innerWidth):null,rawCalendarHeight:original?.height}});assert.equal(mobile.modal,true);assert.equal(mobile.overflowX,0);assert.equal(mobile.rowOverflow,0);assert.equal(mobile.rawCalendarHeight,0);results.push({case:'mobile-date-modal',...mobile});await p.close();
}
{
const p=await b.newPage({viewport:{width:1366,height:900}});await p.route('**/*',r=>r.request().resourceType()==='image'?r.abort():r.continue());p.on('pageerror',e=>errors.push('currency:'+String(e)));
await p.goto(tour+'?wcmlc=EGP&mad4b_date_mode=flex&mad4b_date_from=2026-11-18&mad4b_date_to=2026-11-30',{waitUntil:'domcontentloaded',timeout:65000});await p.waitForTimeout(760);
const before=await p.evaluate(()=>document.querySelector('#tour_rates_accordion')?.innerText.match(/From\s+[\d,.]+\s+EGP/)?.[0]);assert.ok(before);
const resp=p.waitForResponse(r=>r.url().includes('admin-ajax.php')&&new URLSearchParams(r.request().postData()||'').get('query[mad4b_currency]')==='EGP',{timeout:38000});await p.locator('.mad4b-rate-filter-row .jet-select__control').first().selectOption('2354');await resp;await p.waitForTimeout(400);
const after=await p.evaluate(()=>document.querySelector('#tour_rates_accordion')?.innerText.match(/From\s+[\d,.]+\s+EGP/)?.[0]);assert.ok(after);results.push({case:'egp-on-native-filter',before,after});
const fr=await p.locator('a.wpml-ls-link[href*="/fr/"]').first().getAttribute('href');if(fr){await p.goto(new URL(fr,p.url()).href,{waitUntil:'domcontentloaded',timeout:68000});await p.waitForTimeout(680);const language=await p.evaluate(()=>({lang:document.documentElement.lang,url:location.href.slice(-180),date:document.querySelector('.mad4b-ud-rate-select .mad4b-ud__summary')?.textContent,note:document.querySelector('.mad4b-ud-rate-note')?.textContent}));assert.match(language.lang,/fr/);assert.ok(language.date);assert.match(language.note||'',/Périodes tarifaires|Choisissez/);results.push({case:'wpml-france',...language});}
await p.close();
}
{
const p=await b.newPage({viewport:{width:1366,height:900}});await p.route('**/*',r=>r.request().resourceType()==='image'?r.abort():r.continue());p.on('pageerror',e=>errors.push('archive:'+String(e)));
await p.goto('https://tours.dev.mad4b.com/tours-and-activities/?mad4b_date_mode=fixed&mad4b_date_from=2028-01-20',{waitUntil:'domcontentloaded',timeout:65000});await p.waitForTimeout(750);
const archive=await p.evaluate(()=>({url:location.href,roots:document.querySelectorAll('.mad4b-ud--archive').length,summary:document.querySelector('.mad4b-ud--archive .mad4b-ud__summary')?.textContent,cardCount:document.querySelectorAll('#tours_query_archive .jet-listing-grid__item').length}));assert.ok(archive.roots>0);assert.match(archive.summary||'',/Jan 20|20 Jan/);results.push({case:'archive-reload',...archive});await p.close();
}
assert.deepEqual(errors,[]);console.log(JSON.stringify({status:'PASS',results,errors}).slice(0,12000));await b.close()})().catch(e=>{console.error('FAIL '+String(e).slice(0,1100));process.exitCode=1});
