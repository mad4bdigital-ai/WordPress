#!/usr/bin/env python3
"""Fail closed if CSS/page source changed while Chromium measured candidate."""
import hashlib,json,re,sys
from pathlib import Path
sha=lambda x:hashlib.sha256(x).hexdigest()
def parse(html:str):
    m=re.search(r'<body\b',html,re.I)
    if not m:raise ValueError('missing body')
    body=html[m.start():]
    docs=sorted({int(x) for x in re.findall(
        r'(?<![a-z0-9_-])elementor-(\d+)\b',body,re.I)})
    dom_hash=sha(','.join(map(str,docs)).encode())
    page_id=re.search(r'<body[^>]*class=["\'][^"\']*page-id-(\d+)',html,re.I)
    files=list(dict.fromkeys(re.findall(
        r'/wp-content/litespeed/css/([a-f0-9]{32}\.css)',html)))
    if len(files)!=1 or not page_id:raise ValueError('page id or CSS bundle ambiguous')
    return files[0],dom_hash,int(page_id.group(1))
def verify(data:dict,folder:Path,css_root:Path):
    candidates=data['candidates']
    if not candidates or data.get('errors') or data.get('contract')!='mad4b.mobile-critical.candidates.v1':
        raise ValueError('untrusted candidate inventory')
    result=[]
    for row in candidates:
        lang=row['lang']
        if not re.fullmatch(r'en|fr|de|es|it|ar',lang):
            raise ValueError('invalid language')
        html=(folder/f'{lang}.html').read_text(errors='replace')
        h=(folder/f'{lang}.headers').read_text(errors='replace').replace('\r','')
        status=re.findall(r'^HTTP/[0-9.]+\s+(\d{3})',h,re.I|re.M)
        if not status or status[-1]!='200':raise ValueError('bad homepage response '+lang)
        f,dom,page=parse(html)
        if (f!=row['css_file'] or dom!=row['dom_sha']
            or page!=row['page_id']):
            raise ValueError('source changed while QA running: '+lang)
        css=css_root/f
        if css.is_symlink() or not css.is_file() or sha(css.read_bytes())!=row['source_sha']:
            raise ValueError('CSS source bytes changed while QA running: '+lang)
        result.append({'locale':lang,'css_sha':row['source_sha'],'document_sha':dom})
    return result
def main():
    source,observed,css=sys.argv[1:4]
    report=verify(json.loads(Path(source).read_text()),Path(observed),Path(css))
    print(json.dumps({'contract':'mad4b.critical-css.final-source-identity.v1',
                      'verified':report},sort_keys=True))
if __name__=='__main__':
    try:main()
    except (KeyError,ValueError,TypeError,IndexError,OSError,AssertionError) as e:
        print('HOLD_UNSTABLE_PUBLIC_HTML_OR_CSS',str(e),file=sys.stderr)
        sys.exit(4)
