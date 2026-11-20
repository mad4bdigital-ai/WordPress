"""Regressions: no silent success or context leak when public QA is held."""
import json, os, pathlib, subprocess, sys, tempfile
D=pathlib.Path(sys.argv[1])
COUNT=0
MOCK="""const fs=require('fs');module.exports={chromium:{launch:async()=>({newContext:async()=>({newPage:async()=>({goto:async()=>({status:()=>200,text:async()=>process.env.MOCK_HTML,headers:()=>({'x-mad4b-public-ccss-currency':'USD','x-mad4b-public-ccss-market':'base'})})}),close:async()=>fs.appendFileSync(process.env.MOCK_CLOSED,'closed\\n')}),close:async()=>{}})}};"""
for reason,html,currency in [('source_html_drift','<html>old stylesheet</html>','USD'),('country_or_currency_context_drift','<html>/stable.css</html>','EUR')]:
 with tempfile.TemporaryDirectory() as td:
  p=pathlib.Path(td);mods=p/'playwright';mods.mkdir();(mods/'index.js').write_text(MOCK)
  (p/'candidates.json').write_text(json.dumps({'candidates':[{'lang':'en','route':'/article/','css_file':'stable.css','resolved_currency':currency,'resolved_market':'base'}]}))
  env={**os.environ,'NODE_PATH':str(p),'MAD4B_OUTPUT':str(p),'MAD4B_QA_LANGS':'en','MAD4B_SOURCE_SITE':'https://example.test','MOCK_HTML':html,'MOCK_CLOSED':str(p/'closed.log')}
  proc=subprocess.run(['node',str(D/'tools/verify.js')],env=env,capture_output=True,text=True,timeout=12)
  assert proc.returncode==0,(reason,proc.stdout,proc.stderr)
  proof=json.loads((p/'verification.json').read_text())
  assert proof['tested']==0 and proof['results']==[],proof
  assert proof['gates']['en']=={'decision':'hold','reason':reason},proof
  assert (p/'closed.log').read_text().count('closed')==1,'Browser context leak on held QA'
  COUNT+=1;print('PASS',reason,'zero-width proof preserved and browser context closed')
print(f'PASS {COUNT}/{COUNT} held-QA evidence regression')
