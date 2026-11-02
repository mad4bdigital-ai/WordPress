#!/usr/bin/env python3
"""Read-only browser acceptance for DFSB + native JSF: no site settings/data writes."""
import argparse
import json
import sys
from playwright.sync_api import sync_playwright

p=argparse.ArgumentParser()
p.add_argument('--locale',required=True)
p.add_argument('--slug',required=True)
p.add_argument('--base-url',required=True)
a=p.parse_args()
base=a.base_url.rstrip('/')+'/'+('' if a.locale=='en' else a.locale+'/')+'tours-and-activities/'
results={'locale':a.locale,'slug':a.slug,'base_url':base,'pass':False}
try:
 with sync_playwright() as pw:
  browser=pw.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
  page=browser.new_page(viewport={'width':1360,'height':940})
  errors=[];ajax=[]
  page.on('pageerror',lambda e:errors.append(str(e)[:400]))
  page.on('response',lambda r:ajax.append({'status':r.status,'method':r.request.method}) if 'admin-ajax.php' in r.url and r.request.method=='POST' else None)
  def snapshot():
   return page.evaluate('''() => {const g=document.querySelector('[data-query-id="5"][data-listing-id]'); if(!g)return {missing:true}; const all=[...g.querySelectorAll('.jet-listing-grid__item')];return {listing_id:g.getAttribute('data-listing-id'),query_id:g.getAttribute('data-query-id'),top_ids:all.filter(el=>!el.parentElement.closest('.jet-listing-grid__item')).map(el=>el.getAttribute('data-post-id')),descendants:all.length}}''')
  response=page.goto(base,wait_until='domcontentloaded',timeout=28000)
  page.wait_for_timeout(900)
  results['initial_status']=response.status
  before=snapshot()
  matches=page.locator('[data-query-var="destinations"] input[data-url-value="'+a.slug+'"]')
  if not matches.count():raise RuntimeError('native translated term option not mounted')
  picked=None
  for i in range(min(matches.count(),8)):
   candidate=matches.nth(i)
   label=candidate.locator('xpath=..')
   if label.is_visible():picked=label;break
  if picked is None:
   candidate=matches.first
   root=candidate.locator('xpath=ancestor::div[contains(@class,"jet-list-tree")][last()]')
   root.locator(':scope > .jet-list-tree__parent .jet-collapse-icon').first.click(timeout=7500)
   picked=candidate.locator('xpath=..')
  picked.click(timeout=9500)
  page.wait_for_timeout(6500)
  after=snapshot()
  ajax_url=page.url
  page.reload(wait_until='domcontentloaded',timeout=26000)
  page.wait_for_timeout(800)
  direct=snapshot()
  results.update({'native_filter_count':matches.count(),'before':before,'after_ajax':after,'after_direct_reload':direct,'ajax_url':ajax_url,'first_party_ajax_requests':ajax,'page_errors':errors})
  results['pass']=(response.status==200 and ('destinations:'+a.slug+'/') in ajax_url and not errors and not after.get('missing') and after.get('top_ids')==direct.get('top_ids') and all(x['status']==200 for x in ajax))
  browser.close()
except Exception as e:
 results['error']=str(e)[:600]
print(json.dumps(results,ensure_ascii=False))
sys.exit(0 if results['pass'] else 1)
