#!/usr/bin/env python3
import json,pathlib
from playwright.sync_api import sync_playwright
root=pathlib.Path('/opt/staging/mad4b-wpml-priority-local-lab-20261102/control-plane-review-ui/visual')
checks=[]
def require(condition,name,details=None):
 checks.append({'check':name,'pass':bool(condition),'details':details})
 if not condition:raise AssertionError(name)
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox'])
 for name,width,h,screen in [('desktop',1440,900,'overview.html'),('mobile',390,844,'overview.html'),
                             ('small_mobile',320,700,'overview.html'),('fr_draft',390,844,'french-drafts.html')]:
  page=b.new_page(viewport={'width':width,'height':h})
  page.goto((root/screen).as_uri())
  checks_result=page.evaluate('''() => {
   const scroll=document.querySelector('.mad4b-wpml-table-scroll');
   const left=scroll.scrollLeft;scroll.scrollLeft=scroll.scrollWidth;
   const shifted=scroll.scrollLeft;
   scroll.scrollLeft=left;
   const form=document.querySelector('form.mad4b-wpml-filters');
   const buttons=[...document.querySelectorAll('button, input[type=submit], a.button')];
   return {doc:document.documentElement.scrollWidth,viewport:innerWidth,
     scrollPossible:scroll.scrollWidth>scroll.clientWidth,scrollDidMove:shifted>0,
     width:scroll.clientWidth,table:scroll.scrollWidth,rows:document.querySelectorAll('.mad4b-wpml-table tbody tr').length,
     ariaStatus:document.querySelectorAll('[role=status]').length,
     filters:[...form.querySelectorAll('select')].map(x=>({name:x.name,label:x.labels.length})),
     formMethod:form.method,formTarget:form.getAttribute('action'),
     publishActions:buttons.filter(x=>/publish|approve|complete|delete/i.test(x.textContent||x.value||'')).length};
  }''')
  require(checks_result['doc']==width,name+' no document overflow',checks_result)
  require(checks_result['publishActions']==0,name+' no mutation buttons')
  require(checks_result['formMethod']=='get',name+' read-only filtering')
  require(all(x['label']==1 for x in checks_result['filters']),name+' select labels')
  if width<700:
   require(checks_result['scrollPossible'] and checks_result['scrollDidMove'],name+' table scroll works')
  if name=='fr_draft':require(checks_result['rows']==4,'filtered page only four French draft rows')
  else:require(checks_result['rows']==26,name+' shows all review items')
  page.close()
 # Check *real* website: anonymous headless browser must never see the protected dashboard.
 page=b.new_page(viewport={'width':1280,'height':800})
 try:
  response=page.goto('https://tours.dev.mad4b.com/wp-admin/admin.php?page=mad4b-wpml-review-dashboard',
    wait_until='domcontentloaded',timeout=20000)
  url=page.url
  require('/wp-login.php' in url,'real admin page enforces WordPress login',{'status':response.status if response else None,'redirect_to_login':True})
  require('WPML Translation Reviews' not in page.content(),'anonymous session cannot see draft ledger')
 except Exception as e:
  print('LIVE_BROWSER_GATE_UNAVAILABLE',str(e)[:180])
  # Don't claim checked when network fails.
  checks.append({'check':'live browser authentication gate','pass':None,'details':str(e)[:180]})
 page.close()
 b.close()
report={'contract':'mad4b.wpml.visual-browser-qa.v1',
 'render_source':'WordPress generated HTML via authorized WP-CLI; file:// Chromium, no session forgery',
 'authenticated_browser_checked':False,'checks':checks,
 'passed':sum(x['pass'] is True for x in checks),'failed':sum(x['pass'] is False for x in checks)}
(root/'browser_ui_security_checks.json').write_text(json.dumps(report,indent=2))
print(json.dumps({'passed':report['passed'],'failed':report['failed'],
  'live_login_check':[x for x in checks if x['check'].startswith('real admin') or x['check'].startswith('anonymous') or x['check'].startswith('live browser')], 'result_file':str(root/'browser_ui_security_checks.json')},indent=2))
