#!/usr/bin/env python3
"""Offline, first-party CSS dependency proof and ordered compiler (never a certificate).

A diagnostic/qualification artifact: no WordPress write, network access, CSS
promotion, HTML rewriting or public READY claim. Fail closed on unsupported
CSS syntax or remote/non-local styles. Full route QA is required separately.
"""
from __future__ import annotations
import hashlib
import html.parser
import json
import pathlib
import posixpath
import re
import sys
from urllib.parse import urlsplit, urljoin

CONTRACT='mad4b.public-css.ordered-dependencies.v1'
CSS_NAME=re.compile(r'/wp-content/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.css')
INCLUDES_NAME=re.compile(r'/wp-includes/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.css')
MAX_LINKS=256
MAX_ASSET=6*1024*1024
MAX_TOTAL=24*1024*1024
MAX_HTML=6_000_000
SHA=re.compile(r'[a-f0-9]{64}')

def sha(data):
    return hashlib.sha256(data).hexdigest()

def canonical(value):
    return json.dumps(value,separators=(',',':'),sort_keys=True,ensure_ascii=True).encode()

class Styles(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.head=False
        self.links=[]
        self.slot=0
    def handle_starttag(self,tag,attrs):
        if tag=='head':self.head=True
        if not self.head:return
        # A real inline <style> between two external files is a CSS cascade
        # boundary. The compiler must never lift later CSS above it.
        if tag=='style':
            self.slot+=1
            return
        if tag!='link':return
        a=dict(attrs)
        rel=(a.get('rel') or '').lower().split()
        if 'stylesheet' in rel:
            a['_cascade_slot']=self.slot
            self.links.append(a)
    def handle_endtag(self,tag):
        if tag=='head':self.head=False

def relative_file(path,content_root):
    if path.startswith('/wp-content/') and CSS_NAME.fullmatch(path):
        relative=path.removeprefix('/wp-content/')
        base=content_root
    elif path.startswith('/wp-includes/') and INCLUDES_NAME.fullmatch(path):
        relative=path.removeprefix('/')
        base=content_root.parent
    else:raise ValueError('unsupported-local-css-path')
    root=base.resolve(strict=True)
    candidate=(base/relative)
    # WordPress may deploy mu-plugins/assets as an enrolled symlink into
    # wp-content/plugins. Allow only a target resolving INSIDE this exact
    # site's content root; never dereference external/site-crossing links.
    resolved=candidate.resolve(strict=True)
    if not resolved.is_relative_to(root) or not resolved.is_file():
        raise ValueError('css-source-root-escape')
    return resolved

def inspect(document,host,content_root):
    if not isinstance(document,str) or len(document.encode())>MAX_HTML or '</head>' not in document.lower():
        raise ValueError('invalid-original-public-html')
    if not re.fullmatch(r'[a-z0-9.-]{3,200}',host):raise ValueError('untrusted-site-host')
    root=pathlib.Path(content_root)
    if root.is_symlink() or not root.is_dir() or root.name!='wp-content':
        raise ValueError('invalid-site-owned-content-root')
    parser=Styles()
    parser.feed(document[:document.lower().index('</head>')+7])
    if not parser.links or len(parser.links)>MAX_LINKS:raise ValueError('unbounded-or-empty-css-dependencies')
    entries=[];total=0
    for ordinal,a in enumerate(parser.links):
        # 'alternate' or disabled links must not become active on mobile.
        if 'alternate' in (a.get('rel') or '').lower().split() or 'disabled' in a:
            raise ValueError('conditional-stylesheet-unproven')
        if a.get('integrity') or a.get('crossorigin'):
            raise ValueError('cross-origin-integrity-stylesheet-unproven')
        href=a.get('href')
        if not isinstance(href,str) or len(href)>1024:
            raise ValueError('invalid-css-link-url')
        parsed=urlsplit(urljoin('https://'+host+'/',href))
        if parsed.scheme!='https' or parsed.hostname!=host or parsed.port is not None:
            raise ValueError('external-or-mixed-scheme-css')
        if parsed.username or parsed.password or parsed.fragment:
            raise ValueError('untrusted-css-url-identity')
        # Legacy WordPress enqueues may have /includes/../assets/*.css.
        # Canonicalise literal POSIX path segments exactly like the browser;
        # never permit escaping the site's wp-content/wp-includes source.
        raw_path=parsed.path
        if '%' in raw_path or chr(92) in raw_path or any(ord(c)<32 for c in raw_path):
            raise ValueError('encoded-or-control-css-path-untrusted')
        css_path=posixpath.normpath(raw_path)
        if not css_path.startswith('/wp-content/') and not css_path.startswith('/wp-includes/'):
            raise ValueError('css-dot-segment-crossed-site-boundary')
        # Query strings are recorded exactly, but never used as a file path.
        if len(parsed.query)>120 or not re.fullmatch(r'[A-Za-z0-9_=&%.+;-]*',parsed.query):
            raise ValueError('untrusted-css-variant-query')
        media=(a.get('media') or 'all').strip().lower()
        if not re.fullmatch(r'[a-z0-9\s(),:%.-]{1,180}',media):
            # Preserve media queries, but never interpolate untrusted CSS
            # syntax such as braces, comments, semicolons or URLs.
            raise ValueError('unsupported-css-media-syntax')
        file=relative_file(css_path,root)
        size=file.stat().st_size
        if not 1<=size<=MAX_ASSET:raise ValueError('css-source-size-out-of-range')
        total+=size
        if total>MAX_TOTAL:raise ValueError('unbounded-combined-css-bytes')
        source=file.read_bytes()
        if len(source)!=size:raise ValueError('css-source-raced-during-read')
        try:source.decode('utf-8')
        except UnicodeDecodeError:raise ValueError('css-source-not-utf8')
        entries.append({'order':ordinal,'slot':a['_cascade_slot'],
                        'href':'https://'+host+css_path+('?' + parsed.query if parsed.query else ''),
                        'path':css_path,'media':media,'bytes':size,'sha256':sha(source)})
    data={'contract':CONTRACT,'host':host,'files':entries,'total_bytes':total}
    data['manifest_sha256']=sha(canonical(data))
    return data

URL_RE=re.compile(r'url\(\s*(?:(["\x27])(.*?)\1|([^"\x27)\s][^)]*))\s*\)',re.I|re.S)
IMPORT_RE=re.compile(r'@import\b',re.I)
NAMESPACE_RE=re.compile(r'@namespace\b',re.I)

def rebase_css(source,base_url):
    if IMPORT_RE.search(source) or NAMESPACE_RE.search(source):
        raise ValueError('unresolved-css-import-or-namespace')
    # Relative images/fonts must resolve against original stylesheet, NOT the
    # compiled CSS location or the visitor route.
    def rewrite(match):
        value=match.group(2) if match.group(1) else match.group(3)
        value=value.strip()
        if not value or '\\' in value or any(ch in value for ch in '\r\n\x00'):
            raise ValueError('unsupported-css-url-escape')
        if value.startswith(('data:','#')):
            return match.group(0)
        resolved=urlsplit(urljoin(base_url,value))
        if resolved.scheme not in ('http','https') or resolved.username or resolved.password:
            raise ValueError('untrusted-css-linked-resource')
        return 'url("'+resolved.geturl().replace('"','%22')+'")'
    css=URL_RE.sub(rewrite,source)
    if re.search(r'url\(',URL_RE.sub('',css),re.I):
        raise ValueError('unparsed-css-url')
    return css

def compile_manifest(manifest,content_root):
    if not isinstance(manifest,dict) or manifest.get('contract')!=CONTRACT:
        raise ValueError('invalid-manifest-contract')
    expected=manifest.get('manifest_sha256')
    body={k:v for k,v in manifest.items() if k!='manifest_sha256'}
    if not isinstance(expected,str) or not SHA.fullmatch(expected) or sha(canonical(body))!=expected:
        raise ValueError('manifest-tampered')
    entries=manifest.get('files')
    if not isinstance(entries,list) or not 1<=len(entries)<=MAX_LINKS:
        raise ValueError('invalid-manifest-length')
    if manifest.get('total_bytes')!=sum(x['bytes'] for x in entries):
        raise ValueError('manifest-byte-accounting-mismatch')
    combined=[]; chunks=[]; seen=0
    slot=None; group=[]
    def flush():
        if not group:return
        blob='\n'.join(group).encode()
        chunks.append({'slot':slot,'sha256':sha(blob),'bytes':len(blob),'css':blob})
    for i,entry in enumerate(entries):
        if entry.get('order')!=i:raise ValueError('source-order-drift')
        if not isinstance(entry.get('slot'),int) or entry['slot']<0:
            raise ValueError('untrusted-inline-cascade-slot')
        if slot is not None and entry['slot']<slot:
            raise ValueError('cascade-slot-reordered')
        if slot is None:slot=entry['slot']
        elif entry['slot']!=slot:
            flush();group=[];slot=entry['slot']
        local=relative_file(entry['path'],pathlib.Path(content_root))
        raw=local.read_bytes()
        if len(raw)!=entry['bytes'] or sha(raw)!=entry['sha256']:
            raise ValueError('source-css-sha-drift')
        seen+=len(raw)
        if seen>MAX_TOTAL:raise ValueError('oversized-css-dependency-graph')
        css=raw.decode('utf-8')
        # Permit CSS 2.1 @charset only at original file start. All content
        # after compilation is UTF-8; duplicate inner @charset is invalid.
        # WordPress vendor CSS sometimes puts a comment before its UTF-8
        # charset declaration. Source bytes were strictly UTF-8 decoded;
        # remove only the initial declaration (and its harmless preamble).
        css=re.sub(r'^(?:\ufeff|\s|/\*.*?\*/)*@charset\s+["\x27]utf-8["\x27]\s*;',
                   '',css,count=1,flags=re.I|re.S)
        if re.search(r'@charset\b',css,re.I):raise ValueError('unsafe-nested-css-charset')
        css=rebase_css(css,entry['href'])
        if entry['media']!='all':
            css='@media '+entry['media']+' {\n'+css+'\n}'
        group.append('/* source:'+str(i)+' */\n'+css)
        combined.append('/* source:'+str(i)+' */\n'+css)
    flush()
    # The aggregate is for provenance/reporting only. Delivery must put
    # each chunk back into its original slot, or cascade is not equivalent.
    blob='\n'.join(combined).encode()
    return {'manifest_sha256':expected,'compiled_sha256':sha(blob),
            'compiled_bytes':len(blob),'css':blob,'chunks':chunks,
            'slot_preserving_delivery_required':len(chunks)>1}

if __name__=='__main__':
    if len(sys.argv)!=5:raise SystemExit('USAGE: HTML HOST WP_CONTENT_ROOT OUTPUT_DIR')
    htmlfile,host,root,out=sys.argv[1:]
    output=pathlib.Path(out)
    if output.is_symlink():raise SystemExit('unsafe-report-directory')
    output.mkdir(mode=0o700,parents=True,exist_ok=True)
    manifest=inspect(pathlib.Path(htmlfile).read_text(),host,root)
    compiled=compile_manifest(manifest,root)
    (output/'css-dependencies.json').write_bytes(canonical(manifest)+b'\n')
    # Persist individual immutable cascade chunks, never a flattened
    # stylesheet that could override a native inline style between slots.
    for chunk in compiled['chunks']:
        (output/(chunk['sha256']+'.css')).write_bytes(chunk['css'])
    print(json.dumps({'contract':CONTRACT,'count':len(manifest['files']),
       'manifest_sha256':manifest['manifest_sha256'],
       'compiled_sha256':compiled['compiled_sha256'],
       'compiled_bytes':compiled['compiled_bytes'],
       'cascade_slots':len(compiled['chunks']),
       'slot_preserving_delivery_required':compiled['slot_preserving_delivery_required']}))
