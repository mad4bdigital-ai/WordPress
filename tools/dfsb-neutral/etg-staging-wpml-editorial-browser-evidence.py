from playwright.sync_api import sync_playwright
import hashlib,json,datetime,sys
targets={'ar':40547,'de':34597,'es':34605,'fr':34599,'it':39502}
languages={'ar':'ar','de':'de-DE','es':'es-ES','fr':'fr-FR','it':'it-IT'}
for lang in sys.argv[1:]:
 with sync_playwright() as p:
  b=p.chromium.launch(headless=True)
  page=b.new_page(viewport={'width':390,'height':844})
  problems=[];page.on('pageerror',lambda e:problems.append(str(e)[:120]))
  resp=page.goto('https://tours.dev.mad4b.com/'+lang+'/',wait_until='domcontentloaded',timeout=18000)
  root=page.locator('.elementor-'+str(targets[lang]))
  headings=root.locator('.elementor-heading-title').all_inner_texts()
  result={'contract':'mad4b.wpml.editorial-browser-readback.v1','locale':lang,
  'target_post_id':targets[lang],'status':resp.status,'html_lang':page.locator('html').get_attribute('lang'),
  'template_present':root.count()==1,'heading_texts':headings,'headings_count':len(headings),
  'anchor_count':root.locator('#state_governorate').count(),'script_errors':problems,
  'captured_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
  result['screenshot_sha256']=hashlib.sha256(page.screenshot(full_page=False)).hexdigest()
  print(json.dumps(result,ensure_ascii=False))
  b.close()