from pathlib import Path
import importlib.util,tempfile,json,hashlib,sys
p=Path(sys.argv[1])/'assess.py'
spec=importlib.util.spec_from_file_location('criticalcss_assess',p)
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
with tempfile.TemporaryDirectory() as td:
 root=Path(td);(root/'litespeed/css').mkdir(parents=True)
 (root/'mad4b-critical-css-v1').mkdir()
 filename='a'*32+'.css'
 source=b'@media(min-width:2px){.hero{color:green}}'*400
 (root/'litespeed/css'/filename).write_bytes(source)
 sha=hashlib.sha256(source).hexdigest()
 doc='<body class="page-id-1 elementor-35138 elementor-25927"><main id="hero"></main></body>'
 docsha=hashlib.sha256('25927,35138'.encode()).hexdigest()
 html='<html><head><link rel="stylesheet" href="https://tours.dev.mad4b.com/wp-content/litespeed/css/'+filename+'"></head>'+doc+'<style id="mad4b-critical-css-v1">a{}</style></html>'
 headers='HTTP/2 200\nx-mad4b-mobile-css-ready: 1\n'
 state={'site_host':'tours.dev.mad4b.com','environment':'staging','critical_css':'on','native_breakpoint':767,'wpml_languages':['en'],'default_language':'en','css_epoch':127}
 entry={'bundle_file':filename,'variants':{'/|en|mobile':{
    'gate_status':'pass','css_epoch':127,'elementor_doc_fingerprint':docsha,
    'css_critical_mobile_breakpoint':767,'evidence':{'decision':'passed_evidence','adaptive_mobile_breakpoint_px':767}}}}
 (root/'mad4b-critical-css-v1/manifest.json').write_text(json.dumps({'site':state['site_host'],'version':2,'entries':{sha:entry}}))
 checks=[
  ('trusted native adaptive CSS certified',mod.classify('source',root,state,'en',html,headers)['status']=='certified_active'),
  ('missing Ready header cannot count as active',mod.classify('source',root,state,'en',html,'HTTP/2 200\n')['status']=='needs_native_browser_qa'),
  ('missing inline Critical CSS cannot count as active',mod.classify('source',root,state,'en',html.replace('<style id="mad4b-critical-css-v1">a{}</style>',''),headers)['status']=='needs_native_browser_qa'),
  ('CSS epoch drift cannot reuse certificate',mod.classify('source',root,{**state,'css_epoch':128},'en',html,headers)['status']=='needs_native_browser_qa'),
  ('unmatched Elementor doc revokes certificate',mod.classify('source',root,state,'en',html.replace('elementor-25927','elementor-29999'),headers)['status']=='needs_native_browser_qa'),
  ('wrong host fails closed',False)]
 try:mod.classify('source',root,{**state,'site_host':'wayfatours.com'},'en',html,headers)
 except ValueError:checks[-1]=('wrong host fails closed',True)
 (root/'litespeed/css'/filename).write_bytes(source+b'new edit')
 checks.append(('changed CSS bytes require recertification',mod.classify('source',root,state,'en',html,headers)['status']=='needs_native_browser_qa'))
 (root/'litespeed/css'/filename).write_bytes(source)
 # Native 767px Elementor breakpoint, but a proven 599px Critical CSS cap.
 # The assessor must recognize it without accidentally admitting a failed
 # seven-width test or unverifiable on-disk critical stylesheet.
 original_entry=json.loads(json.dumps(entry))
 variant=original_entry['variants']['/|en|mobile']
 critical=b'body{font-size:16px}'*1200
 critical_sha=hashlib.sha256(critical).hexdigest()
 critical_name=critical_sha+'.css'
 (root/'mad4b-critical-css-v1'/critical_name).write_bytes(critical)
 variant['css_critical_mobile_breakpoint']=599
 variant['file']=critical_name
 variant['critical_sha']=critical_sha
 variant['critical_bytes']=len(critical)
 variant['evidence']={'decision':'passed_evidence','adaptive_mobile_breakpoint_px':599,
                      'native_mobile_breakpoint_px':767,
                      'viewports':[320,390,414,599,600,767,768]}
 manifest_path=root/'mad4b-critical-css-v1/manifest.json'
 manifest_path.write_text(json.dumps({'site':state['site_host'],'version':2,'entries':{sha:original_entry}}))
 html599=html.replace('<style id="mad4b-critical-css-v1">a{}</style>',
       '<style id="mad4b-critical-css-v1" media="(max-width:599px)">a{}</style>'
       '<link id="mad4b-css-rest-v1" rel="stylesheet" media="(min-width:600px)"'
       ' href="/wp-content/litespeed/css/'+filename+'">')
 checks.append(('narrow 599px cap recognized with real seven-width QA and CSS SHA',
       mod.classify('source',root,state,'en',html599,headers)['status']=='certified_active'))
 checks.append(('narrow 599px cap reported precisely',
       mod.classify('source',root,state,'en',html599,headers)['critical_cap_px']==599))
 variant['evidence']['viewports'].remove(600)
 manifest_path.write_text(json.dumps({'site':state['site_host'],'version':2,'entries':{sha:original_entry}}))
 checks.append(('missing 600px edge revokes narrower certificate',
       mod.classify('source',root,state,'en',html599,headers)['status']=='needs_native_browser_qa'))
 variant['evidence']['viewports'].append(600)
 variant['critical_sha']=hashlib.sha256(b'untrusted').hexdigest()
 manifest_path.write_text(json.dumps({'site':state['site_host'],'version':2,'entries':{sha:original_entry}}))
 checks.append(('tampered critical asset SHA revokes narrower certificate',
       mod.classify('source',root,state,'en',html599,headers)['status']=='needs_native_browser_qa'))
 variant['critical_sha']=critical_sha
 manifest_path.write_text(json.dumps({'site':state['site_host'],'version':2,'entries':{sha:original_entry}}))
 checks.append(('mismatched 599px CSS media switches to native CSS',
       mod.classify('source',root,state,'en',
           html599.replace('(max-width:599px)','(max-width:767px)'),
           headers)['status']=='needs_native_browser_qa'))
 entry_v1={'gate_status':'pass','languages':['en'],'devices':['mobile'],'source_css_bytes':len(source)}
 (root/'mad4b-critical-css-v1/manifest.json').write_text(json.dumps({'site':'wayfatours.com','version':1,'entries':{sha:entry_v1}}))
 st_v1={**state,'site_host':'wayfatours.com','environment':'production'}
 checks.append(('Wayfa legacy certified exact CSS',mod.classify('wayfa',root,st_v1,'en',html,headers)['status']=='certified_active'))
 for name,passed in checks:
  assert passed,name
  print('PASS',name)
 print(f'PASS {len(checks)}/{len(checks)} dual-site dynamic Critical CSS assessor contracts')
