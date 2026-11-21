"""CP3.1: Pricing Controls preview is optional and does not fetch until opened.

Verifies the original JetEngine repeater, row actions and Save form stay in
place. Other JetEngine pages preserve the previous non-compact overview.
No WordPress database access, no real supplier APIs or customer prices.
"""
import asyncio,json
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[3]
JS=ROOT/'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/repeater-overview.js'
CSS=ROOT/'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.css'

async def scenario(browser,pricing):
    page=await browser.new_page(viewport={'width':1280,'height':850})
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    await page.set_content('''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
      <div class="jet-engine-options-page-wrap mad4b-ws-visual">
       <form id="native-form">
        <div id="settings_top">
          <div class="cx-control cx-control-repeater mad4b-ws-control" data-control-name="shared_gateway_availability_rows">
           <div class="cx-control__info"><h3 class="cx-control__title">Gateway availability</h3></div>
           <div class="cx-control__content">
            <div class="cx-ui-repeater-list">
             <div class="cx-ui-repeater-item">
              <div class="cx-ui-repeater-actions-box"><button type="button">Expand original row</button></div>
              <div data-repeater-control-name="enabled">
               <input type="checkbox" name="shared_gateway_availability_rows[item-0][enabled]" checked>
              </div>
             </div>
            </div>
           </div>
          </div>
        </div>
        <button type="submit">Save changes</button>
       </form>
      </div></body></html>''')
    await page.evaluate("""(pricing) => {
       window.MAD4BJetModePage={
        slug:pricing?'etg-dynamic-pricing-controls':'other-jetengine-options',
        overview:{ajax_url:'/mock-preview',nonce:'test-nonce',action:'readonly-preview'},
        fields:[{name:'shared_gateway_availability_rows',label:'Gateway availability',
         type:'repeater',repeater_fields:[{name:'enabled',label:'Enabled'}]}]
       };
       window.__previewCalls=0;
       window.fetch=async ()=>{window.__previewCalls++;return {
         ok:true,json:async ()=>({success:true,data:{previews:[{
           field:'shared_gateway_availability_rows',rows:[{summary:'Test preview only'}]
         }]}})}};
     }""",pricing)
    await page.add_style_tag(path=str(CSS))
    await page.add_script_tag(path=str(JS))
    disclosure=page.locator('.mad4b-repeater-overview-disclosure')
    if pricing:
        assert await disclosure.count()==1
        assert not await disclosure.evaluate('(e)=>e.open')
        assert await page.locator('.mad4b-repeater-overview').is_hidden()
        await page.wait_for_timeout(1450)
        assert await page.evaluate('window.__previewCalls')==0,"Closed preview issued background AJAX"
        await disclosure.locator('summary').focus()
        await page.keyboard.press('Enter')
        assert await disclosure.evaluate('(e)=>e.open'),"Keyboard Enter failed to open disclosure"
        await page.wait_for_function('window.__previewCalls===1',timeout=3000)
        assert await page.locator('.mad4b-repeater-overview').is_visible()
        assert "Test preview only" in await page.locator('.mad4b-repeater-overview__list').inner_text()
        await disclosure.locator('summary').focus()
        await page.keyboard.press('Enter')
        assert not await disclosure.evaluate('(e)=>e.open'),"Cannot close preview from keyboard"
    else:
        assert await disclosure.count()==0,"Non-pricing JetEngine page changed"
        assert await page.locator('.mad4b-repeater-overview').is_visible()
        await page.wait_for_function('window.__previewCalls>=1',timeout=3500)
    d=await page.evaluate("""() => ({
     formCount:document.querySelectorAll('form').length,
     originalSave:document.querySelectorAll('#native-form>button[type=submit]').length,
     originalRow:document.querySelectorAll('.cx-ui-repeater-item').length,
     originalCheckbox:document.querySelector(
       '[name="shared_gateway_availability_rows[item-0][enabled]"]').checked,
     nativePreview:document.querySelectorAll('.mad4b-repeater-row-preview').length,
     triggeredPreviewCalls:window.__previewCalls
    })""")
    assert d['formCount']==1 and d['originalSave']==1 and d['originalRow']==1 and d['originalCheckbox']
    assert d['nativePreview']==1,d
    assert not errors,errors
    await page.close()
    return {'mode':'pricing' if pricing else 'other_jetengine','status':'PASS',**d}

async def main():
    async with async_playwright() as p:
        executable=Path('/root/.cache/ms-playwright/chromium_headless_shell-1194/chrome-linux/headless_shell')
        browser=await p.chromium.launch(
            headless=True,executable_path=str(executable) if executable.exists() else None,
            args=['--no-sandbox','--disable-dev-shm-usage'])
        results=[]
        for pricing in (True,False):
            results.append(await scenario(browser,pricing))
        await browser.close()
        print('CP31_REPEATER_OPTIONAL_PREVIEW_PASS '+json.dumps(results))
asyncio.run(main())
