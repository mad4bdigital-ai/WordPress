#!/usr/bin/env python3
import copy
import importlib.util
import json
import pathlib
import tempfile
s=importlib.util.spec_from_file_location('css_manifest',pathlib.Path(__file__).parents[1]/'public-css-dependency-manifest.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
count=0

def check(name,predicate):
    global count
    assert predicate,name
    count+=1
    print('PASS',name)

def fails(name,func):
    try:func()
    except ValueError:check(name,True)
    else:raise AssertionError(name)

with tempfile.TemporaryDirectory() as tmp:
    public=pathlib.Path(tmp)/'public_html'
    content=public/'wp-content'
    css=content/'plugins'/'example'/'assets'/'css'
    css.mkdir(parents=True)
    (content/'litespeed/css').mkdir(parents=True)
    (public/'wp-includes/css').mkdir(parents=True)
    (css/'a.css').write_text('@charset "UTF-8"; .hero{background:url("../img/hero.webp")}\n')
    (content/'litespeed/css'/'a'*0 if False else content/'litespeed/css'/'b.css').write_text('.hero {color: red} .headline {font-size: 24px}')
    (public/'wp-includes/css/core.css').write_text('@media screen {.headline { color: navy }}')
    host='company.example'
    hrefs=['/wp-content/plugins/example/assets/css/a.css',
           '/wp-content/litespeed/css/b.css',
           '/wp-includes/css/core.css']
    def html(links):
        return '<html><head>'+links+'</head><body>ok</body></html>'
    links=''.join('<link href="https://'+host+h+'" rel="stylesheet">' for h in hrefs)
    source=html(links)
    manifest=m.inspect(source,host,content)
    result=m.compile_manifest(manifest,content)
    check('ordered 3-source manifest',len(manifest['files'])==3 and [x['order'] for x in manifest['files']]==[0,1,2])
    check('ordered cascade preserved',result['css'].find(b'background:')<result['css'].find(b'color: red')<result['css'].find(b'color: navy'))
    check('relative image references rebased',b'https://company.example/wp-content/plugins/example/assets/img/hero.webp' in result['css'])
    check('compiled bytes content-addressed',result['compiled_sha256']==m.sha(result['css']))
    check('contiguous CSS without inline interruption compiles to one cascade slot',len(result['chunks'])==1)
    with_inline=html('<link rel="stylesheet" href="'+hrefs[0]+'"><style id="between">h1{color:green}</style>'
                     '<link rel="stylesheet" href="'+hrefs[1]+'">')
    split=m.inspect(with_inline,host,content)
    slots=m.compile_manifest(split,content)
    check('inline styles create immutable ordered CSS slots',[x['slot'] for x in slots['chunks']]==[0,1]
          and slots['slot_preserving_delivery_required'])
    check('manifest repeat is stable',m.inspect(source,host,content)['manifest_sha256']==manifest['manifest_sha256'])
    combined=m.inspect(html('<link rel="stylesheet" href="'+hrefs[1]+'">'),host,content)
    check('single CSS legacy input supported',len(combined['files'])==1)
    media=m.inspect(html('<link rel="stylesheet" media="print" href="'+hrefs[1]+'">'),host,content)
    check('media print wrapper preserved',m.compile_manifest(media,content)['css'].find(b'@media print {')>=0)
    fails('untrusted foreign CSS rejects admission',lambda:m.inspect(html('<link href="https://another.example/a.css" rel="stylesheet">'),host,content))
    fails('unsupported opaque stylesheet blocked',lambda:m.inspect(html('<link rel="stylesheet" href="/styles/app.css">'),host,content))
    scoped=m.inspect(html('<link rel="stylesheet" media="(max-width:767px)" href="'+hrefs[1]+'">'),host,content)
    check('responsive CSS media preserved as ordered wrapper',
          b'@media (max-width:767px) {' in m.compile_manifest(scoped,content)['css'])
    fails('unsafe media injection blocked',lambda:m.inspect(html('<link rel="stylesheet" media="screen};body{display:none" href="'+hrefs[1]+'">'),host,content))
    tampered=copy.deepcopy(manifest);tampered['files'][1]['sha256']='0'*64
    fails('signed manifest tampering rejected',lambda:m.compile_manifest(tampered,content))
    (css/'a.css').write_text('.hero{color:blue}')
    fails('CSS source mutation rejected without recapture',lambda:m.compile_manifest(manifest,content))
    (css/'a.css').write_text('@charset "UTF-8"; .hero{background:url("../img/hero.webp")}\n')
    (css/'a.css').write_text('/* vendor header */\n@charset "UTF-8";h1{color:green}')
    vendor=m.inspect(source,host,content)
    check('vendor CSS charset after initial comment normalizes without nested encoding',
          b'@charset' not in m.compile_manifest(vendor,content)['css'])
    (css/'a.css').write_text('@import "other.css"; body {color:red}')
    new=m.inspect(source,host,content)
    fails('unsupported @import refuses unsafe merge',lambda:m.compile_manifest(new,content))
    (css/'a.css').write_text('.a{background:url(javascript:alert)}')
    new=m.inspect(source,host,content)
    fails('unsafe URL protocol refuses compilation',lambda:m.compile_manifest(new,content))
    (css/'a.css').write_text('.a{color:red}')
    (css/'symlink.css').symlink_to(css/'a.css')
    local_link=m.inspect(html('<link rel="stylesheet" href="/wp-content/plugins/example/assets/css/symlink.css">'),host,content)
    check('symlink resolving within exact company wp-content admitted',len(local_link['files'])==1)
    foreign=pathlib.Path(tmp)/'foreign.css';foreign.write_text('.s{color:red}')
    (css/'foreign.css').symlink_to(foreign)
    fails('cross-root symlink refuses CSS access',lambda:m.inspect(html('<link rel="stylesheet" href="/wp-content/plugins/example/assets/css/foreign.css">'),host,content))
print(f'PASS {count}/{count} ordered-CSS-manifest security and cascade tests')
