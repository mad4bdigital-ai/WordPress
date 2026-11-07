const {chromium}=require('playwright'),path=require('path');
const asset=process.env.MAD4B_DESTINATION_JS||path.resolve(__dirname,'../../wp-content/mu-plugins/assets/mad4b-destination-contents-native.js');
const css=process.env.MAD4B_DESTINATION_CSS||path.resolve(__dirname,'../../wp-content/mu-plugins/assets/mad4b-destination-contents-native.css');
(async()=>{
 let b,p;const result={};
 try{
  b=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-zygote']});
  p=await b.newPage({viewport:{width:412,height:924}});
  await p.setContent('<html lang="en"><body class="tax-destinations">'+
  '<div class="elementor elementor-999" data-elementor-id="999">'+
    '<div class="elementor-widget-table-of-contents"><div class="elementor-toc__body" id="original"></div></div>'+
    '<div id="section-wrapper"></div>'+
    '<div class="elementor-widget-mad4b-destination-contents"><nav class="mad4b-destination-contents-native" data-mad4b-destination-contents="1" data-group-mode="source" data-group-labels=\'["Destination contents","Overview","Things to do","Places to explore","Planning"]\'>'+
      '<details class="mad4b-tour-toc__drawer"><summary>Destination contents <span class="mad4b-tour-toc__count">0</span></summary><div data-mad4b-destination-panel="1"></div></details></nav></div>'+
  '</div></body></html>');
  await p.evaluate(()=>{
   const o=document.getElementById('original'),w=document.getElementById('section-wrapper');
   const rows=[['About Demo','main-1'],['Plan Your Trip!','main-2'],['Explore Places','main-3'],['Book Your Trip','missing-4']];
   rows.forEach(([title,id],i)=>{const a=document.createElement('a');a.className='elementor-toc__list-item-text';a.href='#'+id;a.textContent=title;o.appendChild(a);const h=document.createElement('h2');h.textContent=title;if(i!==3)h.id=id;w.appendChild(h);});
  });
  await p.addStyleTag({path:css});
  result.beforeScript=await p.evaluate(()=>({
    sourceVisible:getComputedStyle(document.querySelector('.elementor-widget-table-of-contents')).display!=='none',
    nativeHidden:getComputedStyle(document.querySelector('[data-mad4b-destination-contents]')).display==='none'
  }));
  await p.addScriptTag({path:asset});
  await p.waitForTimeout(360);
  const snap=()=>p.evaluate(()=>{
   const n=document.querySelector('[data-mad4b-destination-contents]');
   const a=[...n.querySelectorAll('.mad4b-tour-toc__group a')].map(e=>e.textContent.trim());
   const source=[...document.querySelectorAll('#original a')].map(e=>e.textContent.trim());
   return {ready:n.classList.contains('is-ready'),parity:n.dataset.mad4bParity,count:a.length,source:source.length,order:JSON.stringify(a)===JSON.stringify(source),alias:!!document.getElementById('missing-4'),groups:n.querySelectorAll('details.mad4b-tour-toc__group').length,duplicateIds:[...document.querySelectorAll('[id]')].filter(x=>x.id==='missing-4').length};
  });
  result.initial=await snap();
  const summary=p.locator('[data-mad4b-destination-contents]>.mad4b-tour-toc__drawer>summary');
  await summary.focus();
  await p.keyboard.press('Enter');
  const opened=await p.locator('.mad4b-tour-toc__drawer').evaluate(x=>x.open);
  await p.keyboard.press('Enter');
  const closed=await p.locator('.mad4b-tour-toc__drawer').evaluate(x=>!x.open);
  result.keyboardWorks=opened&&closed;
  await p.evaluate(()=>{document.documentElement.setAttribute('dir','rtl')});
  result.rtlPadding=await p.locator('.mad4b-tour-toc__group ul').first().evaluate(x=>getComputedStyle(x).paddingInlineStart);
  await p.evaluate(()=>{document.documentElement.setAttribute('dir','ltr')});

  await p.evaluate(()=>{document.getElementById('missing-4').remove();const h=[...document.querySelectorAll('h2')].find(x=>x.textContent==='Book Your Trip');h.remove();});
  await p.waitForTimeout(210);
  result.noTarget=await snap();
  await p.evaluate(()=>{const h=document.createElement('h2');h.textContent='Book Your Trip';document.getElementById('section-wrapper').appendChild(h);});
  await p.waitForTimeout(230);
  result.restored=await snap();
  await p.evaluate(()=>{let o=document.getElementById('original');let a=document.createElement('a');a.className='elementor-toc__list-item-text';a.href='#main-2';a.textContent='Another Plan Your Trip';o.appendChild(a);});
  await p.waitForTimeout(240);
  result.duplicatedSource=await snap();
  await p.setViewportSize({width:1440,height:900});
  await p.setViewportSize({width:412,height:900});
  await p.evaluate(()=>{document.getElementById('missing-4').remove();let h=[...document.querySelectorAll('h2')].find(x=>x.textContent==='Book Your Trip');h.replaceWith(h.cloneNode(true));});
  await p.waitForTimeout(230);
  result.resized=await snap();
  result.afterDynamicReplacement=await p.evaluate(()=>{
    const old=document.querySelector('[data-mad4b-destination-contents]');
    const newNode=old.cloneNode(true);
    newNode.classList.remove('is-ready');
    newNode.removeAttribute('data-mad4b-content-signature');
    old.replaceWith(newNode);
    return true;
  });
  await p.waitForTimeout(320);
  result.newRoot=await snap();
  const pass=result.keyboardWorks && result.rtlPadding==='22px' && result.beforeScript.sourceVisible && result.beforeScript.nativeHidden &&
    result.newRoot.ready && result.newRoot.order &&
    result.initial.order && result.initial.count===4 && result.initial.alias &&
    !result.noTarget.ready && result.restored.ready && result.restored.alias &&
    result.duplicatedSource.count===5 && result.duplicatedSource.order &&
    result.resized.ready && result.resized.alias && result.resized.order;
  result.passed=!!pass;
  console.log('DESTINATION_V2_SYNTHETIC',JSON.stringify(result));
  if(!pass)process.exitCode=4;
 }catch(e){console.error('DESTINATION_V2_SYNTHETIC_ERROR',e.stack.slice(0,1500));process.exitCode=6}
 finally{if(p)await p.close().catch(()=>{});if(b)await b.close().catch(()=>{});}
})().catch(e=>{console.error(String(e));process.exitCode=7});
