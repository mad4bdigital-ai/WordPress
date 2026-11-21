"""Verify extracted ORIGINAL native JetEngine HTML from a real WP-CLI render.

Input is an ephemeral read-only HTML dump, never committed (may carry option
values). No HTTP request, saved credential, database write or AJAX call.
The source CSS/JS is the actual checked-out UI. Use:
  python3 .../verify-rendered-jetengine-native-form-playwright.py /tmp/...html
"""
import asyncio, json, sys
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[3]
EXPECTED={'overview':6,'sources':8,'policies':5,'payments':3,'dates':14,'advanced':9}
STYLES=[
 'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.css',
 'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/jetengine-native-field-system.css',
 'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/jetengine-field-recipe.css',
 'ops/pricing-unified-checkpoints/tests/fixtures/pricing-composer-vendor.css',
 'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.css',
 'wp-content/mu-plugins/assets/mad4b-pricing-interface-v2.css'
]
JS=[
 'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.js',
 'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.js'
]
async def main():
 if len(sys.argv)!=2:raise SystemExit('Provide one generated native HTML snapshot path')
 html=Path(sys.argv[1]).resolve()
 if not html.is_file():raise FileNotFoundError(str(html))
 async with async_playwright() as p:
  chrome=Path('/root/.cache/ms-playwright/chromium_headless_shell-1194/chrome-linux/headless_shell')
  browser=await p.chromium.launch(headless=True,
   executable_path=str(chrome) if chrome.exists() else None,
   args=['--no-sandbox','--disable-dev-shm-usage'])
  results=[]
  for width in (1440,1024,768,390,320):
   page=await browser.new_page(viewport={'width':width,'height':920})
   errors=[]
   page.on('pageerror',lambda e:errors.append(str(e)[:300]))
   await page.goto(html.as_uri())
   await page.evaluate("""() => {
      window.MAD4BWorkspaceShell={pricing:true};
      window.fetch=()=>{throw Error('Network access forbidden in native snapshot test')};
   }""")
   for asset in STYLES:
    await page.add_style_tag(path=str(ROOT/asset))
   for asset in JS:
    await page.add_script_tag(path=str(ROOT/asset))
   initial=await page.evaluate("""() => ({
    originalNativeForm:document.querySelectorAll('#settings_top').length,
    formCount:document.querySelectorAll('form').length,
    inputCount:document.querySelectorAll('#settings_top input').length,
    nativeSaveCount:document.querySelectorAll('form button[type=submit]').length,
    registeredControls:document.querySelectorAll('#settings_top .mad4b-ws-control').length,
    workspace:!!document.querySelector('.mad4b-ws-content')
   })""")
   assert initial['workspace'] and initial['originalNativeForm']==1
   assert initial['formCount']==1 and initial['nativeSaveCount']==1,initial
   assert initial['registeredControls']==45,initial
   for section,n in EXPECTED.items():
    await page.locator('.mad4b-ws-nav-btn[data-section="'+section+'"]').evaluate('(el)=>el.click()')
    visible=await page.evaluate("""() => {
     const all=[...document.querySelectorAll('#settings_top .mad4b-ws-control')];
     return {actual:all.filter(e=>e.getClientRects().length&&e.getBoundingClientRect().height>0).length,
        filteredVisible:all.filter(e=>e.classList.contains('mad4b-ws-filtered') &&
          e.getClientRects().length && e.getBoundingClientRect().height>0).length,
        overflow:document.documentElement.scrollWidth-innerWidth};
    }""")
    assert visible['actual']==n,(width,section,visible)
    assert visible['filteredVisible']==0,(width,section,visible)
    assert visible['overflow']<=12,(width,section,visible)
   if width>=1024:
    nav=page.locator('.mad4b-ws-nav-btn[data-section="payments"]')
    await nav.focus()
    await page.keyboard.press('Enter')
    assert await nav.get_attribute('aria-current')=='true'
   if width<=768:
    toggle=page.locator('.mad4b-ws-mobile-section-toggle')
    assert await toggle.count()==1
    await toggle.click()
    assert await page.locator('.mad4b-ws-nav').is_visible()
   assert not errors,(width,errors)
   results.append({'viewport':width,'original_input_count':initial['inputCount'],
     'original_forms':1,'original_saves':1,
     'all_45_fields_single_owner':True,'section_responsive':True})
   await page.close()
  await browser.close()
  print('RENDERED_NATIVE_JETENGINE_PLAYWRIGHT_PASS '+json.dumps(results))
asyncio.run(main())
