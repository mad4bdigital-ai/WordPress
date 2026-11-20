import asyncio, json
from pathlib import Path
from playwright.async_api import async_playwright
W=Path(__file__).resolve().parents[3]
html=(Path(__file__).parent/'fixtures/composed-workspace.html').as_uri()
css1=W/'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.css'
css2=W/'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.css'
js=W/'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.js'
async def main():
 async with async_playwright() as p:
  browser=await p.chromium.launch(headless=True,executable_path='/root/.cache/ms-playwright/chromium_headless_shell-1194/chrome-linux/headless_shell',args=['--no-sandbox','--disable-dev-shm-usage'])
  results=[]
  for width in [1440,1024,768,390,320]:
   page=await browser.new_page(viewport={'width':width,'height':960},device_scale_factor=1)
   await page.goto(html)
   await page.add_style_tag(path=str(css1))
   await page.add_style_tag(path=str(css2))
   await page.evaluate("window.MAD4BWorkspaceShell={pricing:true};")
   await page.add_script_tag(path=str(js))
   data=await page.evaluate("""() => {
     const root=document.querySelector('.jet-engine-options-page-wrap');
     const layout=root.querySelector('.mad4b-ws-layout');
     const content=root.querySelector('.mad4b-ws-content');
     const sidebar=root.querySelector('.mad4b-ws-sidebar');
     const native=root.querySelector('.mad4b-ws-original');
     const inspector=root.querySelector('.mad4b-tour-inspector');
     const r=e=>{const x=e.getBoundingClientRect();return {x:Math.round(x.x),y:Math.round(x.y),w:Math.round(x.width),h:Math.round(x.height)}};
     return {nativeParent:native.parentElement.className,
      metricsParent:root.querySelector('.mad4b-ws-summary').parentElement.className,
      formCount:root.querySelectorAll('form').length,
      originalTitleVisible:!!native.querySelector('.cx-section__info')&&getComputedStyle(native.querySelector('.cx-section__info')).display!=='none',
      root:r(root),layout:r(layout),content:r(content),sidebar:r(sidebar),native:r(native),inspector:r(inspector),
      documentOverflow:document.documentElement.scrollWidth-innerWidth,
      layoutBackground:getComputedStyle(layout).backgroundColor,
      panelOverflow:content.scrollWidth-content.clientWidth,
      mobileToggleVisible:!!root.querySelector('.mad4b-ws-mobile-section-toggle') &&
        getComputedStyle(root.querySelector('.mad4b-ws-mobile-section-toggle')).display!=='none',
      navHidden:root.querySelector('.mad4b-ws-nav').hidden,
      savePosition:getComputedStyle(root.querySelector('.dialog-save')).position,
      composed:root.classList.contains('mad4b-ws-editor-composed')}
    }""")
   if width in (1440,390):
    await page.screenshot(path='/tmp/mad4b_ui_composition_'+str(width)+'.png',full_page=True)
   assert data['composed'], f'not composed {width}'
   assert 'mad4b-ws-banner' in data['metricsParent'], f'metrics still detached {width}'
   assert data['formCount']==1 and 'mad4b-ws-content' in data['nativeParent'], f'form not single/shared {width}'
   assert not data['originalTitleVisible'],f'duplicate title {width}'
   assert data['documentOverflow']<=8,f'horizontal overflow {width}: {data}'
   assert data['panelOverflow']<=10,f'content overflow {width}: {data}'
   assert data['savePosition']=='static',f'save footer overlaps content at {width}'
   assert data['mobileToggleVisible']==(width<=900),f'mobile nav mode mismatch {width}'
   assert data['navHidden']==(width<=900),f'mobile navigation expanded unexpectedly {width}'
   if width<=900:
    await page.locator('.mad4b-ws-mobile-section-toggle').click()
    assert await page.locator('.mad4b-ws-nav').is_visible(),f'Cannot open sections {width}'
    await page.locator('.mad4b-ws-nav-btn[data-section="sources"]').click()
    await page.locator('.mad4b-ws-nav').wait_for(state='hidden',timeout=2500)
   if width>=1101:
    assert data['sidebar']['x']<=data['content']['x']
    assert abs(data['sidebar']['y']-data['content']['y'])<=2,f'not same surface row {width}'
   # Guard against product menus covering original editor while context is changed.
   mode=await page.evaluate("""() => {
      const root=document.querySelector('.jet-engine-options-page-wrap');
      const n=root.querySelector('.mad4b-ws-original');
      root.classList.remove('mad4b-ws-visual');root.classList.add('mad4b-ws-native');
      document.dispatchEvent(new Event('mad4b:jetengine:mode-changed'));
      const restored=n.parentElement===root;
      root.classList.remove('mad4b-ws-native');root.classList.add('mad4b-ws-visual');
      document.dispatchEvent(new Event('mad4b:jetengine:mode-changed'));
      return {restored,embedded:n.parentElement.classList.contains('mad4b-ws-content'),forms:root.querySelectorAll('form').length};
    }""")
   assert mode=={'restored':True,'embedded':True,'forms':1}, f'native toggle broken {width}: {mode}'
   results.append({'width':width,'ok':True,'layout':data['layout'],'content':data['content'],'sidebar':data['sidebar'],'overflow':data['documentOverflow']})
   await page.close()
  await browser.close()
  print('LAYOUT_QA '+json.dumps(results))
asyncio.run(main())
