'use strict';
const {chromium}=require('playwright');
const path=require('path');
const asset=process.env.MAD4B_DESTINATION_JS ||
  path.resolve(__dirname,'../../wp-content/mu-plugins/assets/mad4b-destination-contents-native.js');
(async()=>{
let browser,page;
try{
  browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-zygote']});
  page=await browser.newPage({viewport:{width:1440,height:900}});
  await page.setContent('<body class="tax-destinations"><main><h2 id="main-anchor">Plan Your Trip!</h2></main></body>');
  await page.addScriptTag({path:asset});
  const result=await page.evaluate(async()=>{
    const ghost=document.createElement('div');
    ghost.id='duplicate-wrapper';
    ghost.className='elementor-sticky__spacer';
    ghost.style.cssText='height:200px;visibility:hidden';
    ghost.innerHTML='<div><h2 id="main-anchor">Plan Your Trip!</h2><a id="duplicate-link" href="#main-anchor">Link</a></div>';
    document.body.appendChild(ghost);
    await new Promise(resolve=>setTimeout(resolve,130));
    return {
      hidden:ghost.getAttribute('aria-hidden')==='true',
      inert:ghost.hasAttribute('inert'),
      ghostIds:ghost.querySelectorAll('[id]').length,
      ownId:ghost.hasAttribute('id'),
      marked:ghost.getAttribute('data-mad4b-sticky-ghost')==='1',
      maintainedHeight:Math.round(ghost.getBoundingClientRect().height)===200,
      preservedOriginalId:document.querySelector('main h2')?.id==='main-anchor'
    };
  });
  if(Object.values(result).some((value,i)=>i===2?value!==0:i===3?value!==false:value!==true)){
    throw new Error('Sticky ghost accessibility regression: '+JSON.stringify(result));
  }
  console.log('MAD4B_STICKY_GHOST_PASS '+JSON.stringify(result));
}catch(err){console.error(err);process.exitCode=1;}
finally{if(page)await page.close().catch(()=>{});if(browser)await browser.close().catch(()=>{});}
})();
