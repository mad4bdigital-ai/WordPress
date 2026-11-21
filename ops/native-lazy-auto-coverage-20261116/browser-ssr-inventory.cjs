'use strict';
const {chromium}=require('playwright');
const fs=require('fs');
const crypto=require('crypto');
const manifest=JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const widths=(process.argv[3]||'390,1920').split(',').map(Number);
const langs=(process.argv[4]||'all').split(',');
if(widths.some(w=>![320,390,767,768,1024,1920].includes(w)))throw Error('Unknown viewport');
if(manifest.contract!=='mad4b.native-lazy.auto-discovery.v1'||manifest.candidates.length>256)throw Error('Unknown inventory contract');
const base=manifest.home_url;
const candidates=manifest.candidates;
const sha=s=>crypto.createHash('sha256').update(String(s)).digest('hex');
const UA_M='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const UA_D='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';
const ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
async function main(){
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 let fails=0;
 try{
  const routes=new Map();
  for(const item of candidates){
   const k=item.locale+'|'+item.url;
   if(!routes.has(k))routes.set(k,{locale:item.locale,url:item.url,entries:[]});
   routes.get(k).entries.push(item);
  }
  for(const grouped of routes.values()){
   const {locale:lang,url:route,entries}=grouped;
   if(langs[0]!=='all'&&!langs.includes(lang))continue;
   for(const width of widths){
    const mobile=width<=768;
    const ctx=await browser.newContext({viewport:{width,height:844},isMobile:mobile,hasTouch:mobile,deviceScaleFactor:1,
       userAgent:mobile?UA_M:UA_D,extraHTTPHeaders:{Accept:ACCEPT}});
    const page=await ctx.newPage();
    const err=[];
    page.on('pageerror',e=>err.push(String(e).slice(0,150)));
    try{
     const url=route+(route.includes('?')?'&':'?')+'mad4b_native_audit_ssr=1';
     const res=await page.goto(url,{waitUntil:'domcontentloaded',timeout:48000});
     await page.waitForTimeout(450);
     for(const item of entries){
      const selector='.elementor-element-'+item.widget_id;
      const nodes=page.locator(selector);
      if(await nodes.count()!==1){
       fails++;console.log('SSR_RESULT '+JSON.stringify({locale:lang,url:route,width,widget:item.widget_id,query_id:item.query_id,
        listing_id:item.listing_id,document_id:item.document_id,
        pass:false,reason:'missing/duplicate widget',found:await nodes.count()}));continue;
      }
      const result=await nodes.first().evaluate(el=>{
       const area=el.getBoundingClientRect(),grid=el.querySelector('.jet-listing-grid');
       const items=[...el.querySelectorAll('.jet-listing-grid__item')];
       const text=[],links=[];
       for(const item of items){
        const walk=document.createTreeWalker(item,NodeFilter.SHOW_TEXT);
        let n;const bits=[];
        while((n=walk.nextNode())){
         const parent=n.parentElement;
         if(!parent||parent.closest('script,style,noscript,template'))continue;
         const v=(n.nodeValue||'').replace(/\s+/g,' ').trim();
         if(v)bits.push(v);
        }
        text.push(bits.join(' '));links.push(...[...item.querySelectorAll('a[href]')].map(a=>a.href));
       }
       return {items:items.length,height:Math.round(area.height),width:Math.round(area.width),
         lazy:!!grid?.classList.contains('jet-listing-grid--lazy-load'),
         overflow:Math.round(document.documentElement.scrollWidth-innerWidth),
         text,links};
      });
      const valid=res.status()===200&&result.items>0&&!result.lazy&&result.height>=100&&result.height<=1600
       &&result.width>50&&result.overflow<=1&&err.length===0;
      if(!valid)fails++;
      console.log('SSR_RESULT '+JSON.stringify({locale:lang,url:route,width,widget:item.widget_id,
       query_id:item.query_id,listing_id:item.listing_id,document_id:item.document_id,pass:valid,
       http:res.status(),count:result.items,height:result.height,area_width:result.width,
       content_sha256:sha(result.text.join('\n')),links_sha256:sha(result.links.join('\n')),
       overflow:result.overflow,lazy:result.lazy,errors:err}));
     }
    }catch(e){
     for(const item of entries){
      fails++;
      console.log('SSR_RESULT '+JSON.stringify({locale:lang,url:route,width,widget:item.widget_id,query_id:item.query_id,
       listing_id:item.listing_id,document_id:item.document_id,
       pass:false,reason:String(e).slice(0,220)}));
     }
    }finally{await ctx.close()}
   }
  }
 }finally{await browser.close()}
 console.log('SSR_SUMMARY '+JSON.stringify({failed:fails}));
 if(fails)process.exitCode=2;
}
main().catch(e=>{console.log('FATAL '+String(e));process.exitCode=3});
