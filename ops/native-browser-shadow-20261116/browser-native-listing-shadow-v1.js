'use strict';
/*
 * MAD4B existing Presentation Browser Audit — source-stable SSR measurement.
 * This is OBSERVATION only, not an AJAX parity certification and not a write.
 * No private pages, cookies, actions, or clicked links are used.
 */
const {chromium}=require('playwright');
const crypto=require('crypto');
const root=process.argv[2];
if(!/^https:\/\/[a-z0-9.-]+\/$/i.test(root||''))throw Error('INVALID_CANONICAL_HTTPS_ORIGIN');
const baseHost=new URL(root).hostname;
if(!/^[a-z0-9.-]{3,160}$/.test(baseHost)||baseHost.includes('..'))throw Error('INVALID_SITE_HOST');
const WIDTHS=[320,390,768,1920],MAX_GRID_ITEMS=150,MAX_GRIDS=12,MAX_LOCALES=8;
const sha=x=>crypto.createHash('sha256').update(String(x)).digest('hex');
const report={
  contract:'mad4b.native-listing.shadow-browser-evidence.v1',
  site_host:baseHost,observed_utc:new Date().toISOString(),
  mode:'ssr_browser_observation_only',
  authorized_lazy_promotion:false,
  signed_ajax_parity_certified:false,
  high_trust_certificate_created:false,
  warnings:[],pages:[],complete:false
};
const norm=s=>String(s||'').replace(/\s+/g,' ').trim();
const short=s=>String(s||'').slice(0,160);
const U='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
async function main(){
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const home=await browser.newPage({viewport:{width:390,height:844},userAgent:U,extraHTTPHeaders:{Accept:ACCEPT}});
  const mainResponse=await home.goto(root,{waitUntil:'domcontentloaded',timeout:45000});
  if(mainResponse.status()!==200)throw Error('HOMEPAGE_NOT_200');
  const locales=await home.evaluate(()=>{
    const lang=String(document.documentElement.lang||'en').toLowerCase().split('-')[0];
    const found=new Set([lang,'en']);
    for(const link of document.querySelectorAll('link[hreflang][rel="alternate"],a[hreflang]')){
      const raw=String(link.getAttribute('hreflang')||'').toLowerCase();
      if(/^[a-z]{2}$/.test(raw))found.add(raw);
    }
    return [...found].filter(x=>/^[a-z]{2}$/.test(x)).sort();
  });
  await home.close();
  if(locales.length>MAX_LOCALES)throw Error('TOO_MANY_LOCALES');
  // Deterministic bounded rotation: at most two locales per twice-daily audit.
  // This covers the entire language inventory in <=4 runs without 24 pages
  // per schedule; no manually configured language or widget IDs.
  const batch=Math.floor(Date.now()/43200000)%Math.max(1,Math.ceil(locales.length/2));
  const selected=locales.slice(batch*2,batch*2+2);
  report.all_locales=locales;
  report.sampled_locales=selected;
  report.batch_index=batch;
  for(const locale of selected){
   const path=locale==='en'?'/':'/'+locale+'/';
   for(const width of WIDTHS){
    const context=await browser.newContext({
      viewport:{width,height:844},isMobile:width<=768,hasTouch:width<=768,
      userAgent:U,extraHTTPHeaders:{Accept:ACCEPT}});
    const page=await context.newPage();
    const pageErrors=[];
    page.on('pageerror',e=>pageErrors.push(short(String(e),180)));
    await page.addInitScript(()=>{
      window.__mad4bShadowCLS=0;
      try{
       new PerformanceObserver(list=>{
         for(const entry of list.getEntries()) if(!entry.hadRecentInput)
           window.__mad4bShadowCLS+=entry.value;
       }).observe({type:'layout-shift',buffered:true});
      }catch(_){}
    });
    const started=Date.now();
    try {
      const response=await page.goto(root.replace(/\/$/,'')+path,{waitUntil:'domcontentloaded',timeout:45000});
      const status=response.status(),headers=response.headers();
      await page.waitForTimeout(700);
      const observation=await page.evaluate(({MAX_GRID_ITEMS,MAX_GRIDS})=>{
        const normalize=v=>String(v||'').replace(/\s+/g,' ').trim();
        const grids=[];
        for(const widget of document.querySelectorAll('.elementor-widget-jet-listing-grid')){
          if(grids.length>=MAX_GRIDS)break;
          const r=widget.getBoundingClientRect();
          const grid=widget.querySelector('.jet-listing-grid');
          if(!grid || r.width<10 || r.height<1)continue;
          const items=[...grid.querySelectorAll('.jet-listing-grid__item')].slice(0,MAX_GRID_ITEMS);
          if(items.length<4)continue;
          grids.push({
            widget_id:String(widget.getAttribute('data-id')||'').slice(0,32),
            items_count:items.length,
            items_truncated:grid.querySelectorAll('.jet-listing-grid__item').length>MAX_GRID_ITEMS,
            width_px:Math.round(r.width),height_px:Math.round(r.height),
            lazy_placeholder:grid.classList.contains('jet-listing-grid--lazy-load'),
            item_links:items.map(item=>
              [...item.querySelectorAll('a[href]')].slice(0,12)
              .map(a=>String(a.getAttribute('href')||'')).join('|')),
            item_text:items.map(item=>normalize(item.innerText||item.textContent).slice(0,2000))
          });
        }
        return {
          grids,viewport_overflow_px:Math.round(document.documentElement.scrollWidth-innerWidth),
          h1_count:document.querySelectorAll('h1').length,
          css_urls:[...document.querySelectorAll('link[rel=stylesheet][href]')]
            .map(l=>l.getAttribute('href')||'').slice(0,150),
          cls:window.__mad4bShadowCLS||0,
          document_bytes:new TextEncoder().encode(document.documentElement.outerHTML).length
        };
      },{MAX_GRID_ITEMS,MAX_GRIDS});
      report.pages.push({
        locale,width_px:width,status,
        elapsed_ms:Date.now()-started,
        css_ready:headers['x-mad4b-mobile-css-ready']||'unknown',
        cache_control:headers['cache-control']||'',
        viewport_overflow_px:observation.viewport_overflow_px,
        h1_count:observation.h1_count,
        cls_observed:+observation.cls.toFixed(5),
        document_bytes:observation.document_bytes,
        css_link_fingerprint:sha(observation.css_urls.join('|')),
        widgets:observation.grids.map(g=>({
          widget_id:g.widget_id,
          item_count:g.items_count,
          items_truncated:g.items_truncated,
          width_px:g.width_px,height_px:g.height_px,
          signed_ajax_parity_verified:false,
          links_sha256:sha(g.item_links.join('\n')),
          content_sha256:sha(g.item_text.join('\n')),
          current_lazy_placeholder:g.lazy_placeholder
        })),
        page_errors:pageErrors.slice(0,5)
      });
      if(status!==200 || observation.viewport_overflow_px>1 || pageErrors.length){
        report.warnings.push({locale,width_px:width,
          reason:status!==200?'HTTP_'+status:observation.viewport_overflow_px>1?'VIEWPORT_OVERFLOW':'BROWSER_SCRIPT_ERROR'});
      }
    } catch(e){
      report.pages.push({locale,width_px:width,error:short(e)});
      report.warnings.push({locale,width_px:width,reason:'UNREACHABLE_OR_TIMED_OUT'});
    } finally {
      await context.close();
    }
   }
  }
  report.complete=report.pages.length===selected.length*WIDTHS.length
    && report.pages.every(p=>p.status===200 && !p.error);
  report.decision='HOLD_AJAX_PARITY_NOT_MEASURED';
  console.log(JSON.stringify(report));
  if(!report.complete)process.exitCode=2;
 }finally{await browser.close()}
}
main().catch(error=>{
  report.warnings.push({reason:short(error)});
  report.complete=false;
  report.decision='HOLD_BROWSER_AUDIT_FAILED';
  console.log(JSON.stringify(report));
  process.exitCode=3;
});
