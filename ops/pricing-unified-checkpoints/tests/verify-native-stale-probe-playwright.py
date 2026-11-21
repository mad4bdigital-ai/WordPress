"""Original Save negative-path browser control, using an offline HTTP stub.

The request intentionally contains ONLY the original CAS nonce and a
guaranteed-different HMAC, never JetEngine pricing inputs, user credentials,
Tour Rates or checkout fields. Test verifies 409 yields PASS, other responses
never claim success. It does NOT claim a logged-in real-browser execution.
"""
import asyncio, json
from pathlib import Path
from playwright.async_api import async_playwright

SOURCE=Path(__file__).resolve().parents[3]/'wp-content/mu-plugins/assets/mad4b-native-pricing-stale-probe-v1.js'

async def main():
 async with async_playwright() as p:
  exe=Path('/root/.cache/ms-playwright/chromium_headless_shell-1194/chrome-linux/headless_shell')
  b=await p.chromium.launch(headless=True,
    executable_path=str(exe) if exe.is_file() else None,
    args=['--no-sandbox','--disable-dev-shm-usage'])
  results=[]
  for code in (409,403,200):
   page=await b.new_page()
   errors=[]
   page.on('pageerror',lambda error:errors.append(str(error)))
   await page.set_content("""<!doctype html><html><body>
     <button id="mad4b-native-stale-probe" type="button"
       data-save-url="https://example.test/wp-admin/admin.php?page=etg-dynamic-pricing-controls&amp;action=jet-engine-op-save-settings">
       Test stale-save rejection
     </button>
     <span id="mad4b-native-stale-probe-result" role="status"></span>
     </body></html>""")
   await page.evaluate("""code => {
      window.MAD4BJetEngineNativePricingCAS={
         nonce:'native-test-nonce',revision:'a'.repeat(64)
      };
      window.__requests=[];
      window.fetch=async (url,options)=>{
        const fields=Object.fromEntries(new URLSearchParams(options.body).entries());
        window.__requests.push({url,method:options.method,
           credentials:options.credentials,fields});
        return {status:code};
      };
   }""",code)
   await page.add_script_tag(path=str(SOURCE))
   await page.locator('button').click()
   data=await page.evaluate("""() => ({
     text:document.getElementById('mad4b-native-stale-probe-result').textContent,
     requests:window.__requests,
     disabled:document.querySelector('button').disabled
   })""")
   assert len(data['requests'])==1 and not data['disabled'],data
   request=data['requests'][0]
   assert request['method']=='POST' and request['credentials']=='same-origin',data
   assert request['fields']=={
    'mad4b_native_cas_nonce':'native-test-nonce',
    'mad4b_native_cas_revision':'b'+'a'*63,
    'mad4b_native_cas_probe':'1',
   },data
   assert ('PASS' in data['text'])==(code==409),data
   assert 'No pricing fields' in data['text'] if code==409 else 'NOT VERIFIED' in data['text']
   assert not errors,errors
   results.append({'http':code,'correct_protected_route':True,
      'pricing_fields_submitted':0,'reported_success':code==409})
   await page.close()
  await b.close()
  print('NATIVE_STALE_BROWSER_PROBE_OFFLINE_PASS '+json.dumps(results))
asyncio.run(main())
