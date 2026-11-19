#!/usr/bin/env python3
"""Exercise the real legacy v1 publisher with EN held and ES fully certified."""
import hashlib,importlib.util,json,os,pathlib,subprocess,sys,tempfile
D=pathlib.Path(sys.argv[1])
h=lambda b:hashlib.sha256(b).hexdigest()
with tempfile.TemporaryDirectory(prefix='mad4b-wayfa-locale-') as temp:
    root=pathlib.Path(temp)/'wp-content';out=pathlib.Path(temp)/'evidence'
    for p in [root/'mu-plugins',root/'litespeed/css',root/'mad4b-critical-css-v1',out]:
        p.mkdir(parents=True,exist_ok=True)
    consumer=root/'mu-plugins/415-mad4b-critical-css-orchestrator.php'
    consumer.write_text("""($decoded['version'] ?? null) !== 1
media="print" fetchpriority="low"
""")
    (root/'mad4b-critical-css-v1/manifest.json').write_text(
       json.dumps({'version':1,'site':'wayfatours.com','entries':{}}))
    rows=[]
    for lang in ('en','es'):
        original=(lang+'-source-css-').encode()*2200
        critical=(lang+'{display:block}').encode()*1700
        assert 16000<=len(critical)<=1048576
        source_filename=h(('bundle'+lang).encode())[:32]+'.css'
        critical_filename=h(critical)+'.css'
        (root/'litespeed/css'/source_filename).write_bytes(original)
        (out/critical_filename).write_bytes(critical)
        rows.append({'lang':lang,'route':'/' if lang=='en' else '/es/',
             'source_sha':h(original),'source_bytes':len(original),
             'css_file':source_filename,'critical_sha':h(critical),
             'critical_bytes':len(critical),'critical_file':critical_filename,
             'device':'mobile','widths':[320,390,414,768]})
    inventory={'contract':'mad4b.mobile-critical.candidates.v1',
               'host':'wayfatours.com','errors':[],'candidates':rows}
    results=[{'lang':l,'width':w,'pass':l=='es' or w!=768,'fcpGain':2000,
              'lcpReg':0 if l=='es' else 772}
             for l in ('en','es') for w in (320,390,414,768)]
    gates={l:{'decision':'passed_evidence' if l=='es' else 'hold',
              'widths':[320,390,414,768], 'cssPriority':'low',
              'fullCssPreload':False,'adaptiveMaxWidth':0,
              'hero900':False,'deferCity':False} for l in ('en','es')}
    qa={'contract':'mad4b.mobile-critical.qa.v1','tested':8,
        'results':results,'gates':gates}
    selection={'site':'wayfa','routes':[{'locale':r['lang'],'route':r['route'],
       'css_sha':r['source_sha'],'status':'needs_native_browser_qa'} for r in rows]}
    spec=importlib.util.spec_from_file_location('qa_admission',D/'qa-admission.py')
    qa_module=importlib.util.module_from_spec(spec);spec.loader.exec_module(qa_module)
    admission,subset=qa_module.evaluate('wayfa',['en','es'],inventory,qa,selection)
    assert admission['accepted']==['es'] and [x['locale'] for x in admission['held']]==['en']
    for fn,data in [('candidates.json',inventory),('admitted-candidates.json',subset),
                    ('locale-admission.json',admission),('verification.json',qa)]:
        (out/fn).write_text(json.dumps(data))
    env={**os.environ,'MAD4B_WP_CONTENT':str(root),'MAD4B_OUTPUT':str(out),
         'MAD4B_TARGET_HOST':'wayfatours.com','MAD4B_CSS_EPOCH':'39',
         'MAD4B_DRY_RUN':'1','MAD4B_CSS_NATIVE_V1_COMPAT':'1',
         'MAD4B_CSS_ADMITTED_CANDIDATES':'admitted-candidates.json',
         'MAD4B_CSS_LEGACY_CONSUMER_SHA256':h(consumer.read_bytes())}
    def call(**opts):
        return subprocess.run([sys.executable,str(D/'tools/publish.py')],
            capture_output=True,text=True,timeout=15,env={**env,**opts})
    result=call()
    assert result.returncode==0,(result.stdout,result.stderr)
    report=json.loads(result.stdout)
    assert [x['language'] for x in report['promoted']]==['es'],report
    assert json.loads((root/'mad4b-critical-css-v1/manifest.json').read_text())['entries']=={}
    print('PASS Wayfa legacy dry-run admits only ES, leaves EN unchanged')
    assert call(MAD4B_CSS_ADMITTED_CANDIDATES='').returncode!=0
    print('PASS combined legacy publication cannot borrow EN failing 768px QA')
    bad={**admission,'accepted':['en']}
    (out/'locale-admission.json').write_text(json.dumps(bad))
    assert call().returncode!=0
    print('PASS modified acceptance proof rejected')
    (out/'locale-admission.json').write_text(json.dumps(admission))
    wrong={**subset,'candidates':[rows[0]]}
    (out/'admitted-candidates.json').write_text(json.dumps(wrong))
    assert call().returncode!=0
    print('PASS failed EN candidate replacement rejected')
    (out/'admitted-candidates.json').write_text(json.dumps(subset))
    duplicate_qa={**qa,'results':[v for v in qa['results'] if not(v['lang']=='es' and v['width']==768)]+[results[-2]]}
    (out/'verification.json').write_text(json.dumps(duplicate_qa))
    assert call().returncode!=0
    print('PASS missing ES 768px measurement cannot produce v1 certificate')
print('PASS 5/5 Wayfa independent v1 publication contracts')
