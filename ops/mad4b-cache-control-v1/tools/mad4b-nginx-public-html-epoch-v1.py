#!/usr/bin/env python3
"""Company-scoped, no Redis access. Reload tested Nginx only on fixed safe-public-HTML signal."""
import argparse,datetime,hashlib,json,os,re,stat,subprocess,sys,tempfile,time
from pathlib import Path

SITES={
 'tours-dev':{
  'conf':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf'),
  'signal':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/.mad4b-purge/global-html-invalidate.request'),
  'container':'nginx-wkgtkzta35wkkgouwt6ruljc',
  'status':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/.mad4b-purge/global-html-status.json')},
 'wayfatours':{
  'conf':Path('/opt/wayfa-rebuild-20261102/stack/nginx.conf'),
  'signal':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content/.mad4b-purge/global-html-invalidate.request'),
  'container':'nginx-guxhp9y6vgh7oouuw8plxrlr',
  'status':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content/.mad4b-purge/global-html-status.json')}
}
STATE=Path('/var/lib/mad4b-public-html-epoch')
MARK='# MAD4B_PUBLIC_HTML_GLOBAL_EPOCH_V1'
TRIGGER=b'MAD4B_SAFE_PUBLIC_HTML_ALL_V1\n'
RE=r'(?m)^(?P<indent>\s*fastcgi_cache_key\s+")(?P<key>[^"]*)(?P<end>";\s*)$'
RE_EPOCH=re.compile(r'(?m)^(?P<a>\s*default\s+")(?P<val>(?:\|g[0-9a-f]{12})?)(?P<b>";)\s*$')
def timestamp():return datetime.datetime.now(datetime.timezone.utc).isoformat()
def sha(raw):return hashlib.sha256(raw).hexdigest()
def conf_with_epoch(raw:bytes)->bytes:
    body=raw.decode('utf-8')
    if MARK in body:raise ValueError('already_enrolled')
    def repl(m):
        key=m.group('key')
        return m.group('indent')+(key+'$mad4b_public_html_global_epoch' if '$mad4b_fastcgi_version' in key else key)+m.group('end')
    out,n=re.subn(RE,repl,body)
    count=sum('$mad4b_fastcgi_version' in m.group('key') for m in re.finditer(RE,body))
    if count not in (1,5) or out.count('$mad4b_public_html_global_epoch')!=count:
        raise ValueError('unexpected_fastcgi_cache_key_coverage')
    stanza=(MARK+' BEGIN\n'
      'map $host $mad4b_public_html_global_epoch {\n'
      '    default "";\n'
      '}\n'
      +MARK+' END\n')
    # A global map_hash_bucket_size directive must precede the first map.
    first=out.splitlines(keepends=True)
    if first and re.fullmatch(r'map_hash_bucket_size\s+[0-9]+;\s*',first[0]):
        return (first[0]+stanza+''.join(first[1:])).encode('utf-8')
    return (stanza+out).encode('utf-8')

def set_epoch(old:bytes,epoch:str)->bytes:
    text=old.decode('utf-8')
    if text.count(MARK+' BEGIN')!=1 or text.count(MARK+' END')!=1:
        raise ValueError('unknown_config_contract')
    first=text.index(MARK+' BEGIN');last=text.index(MARK+' END',first)
    stanza=text[first:last]
    hit=list(RE_EPOCH.finditer(stanza))
    if len(hit)!=1:raise ValueError('epoch_default_mismatch')
    prev=hit[0].group('val')
    if not (prev=='' or re.fullmatch(r'\|g[0-9a-f]{12}',prev)):
        raise ValueError('unexpected_epoch_value')
    next_epoch='|g'+epoch
    if next_epoch==prev:raise ValueError('repeat_epoch')
    stanza=stanza[:hit[0].start('val')]+next_epoch+stanza[hit[0].end('val'):]
    return (text[:first]+stanza+text[last:]).encode('utf-8')

def atomic(path:Path,raw:bytes,mode=0o640):
    fd,temp=tempfile.mkstemp(dir=path.parent,prefix='.mad4b-public-html-epoch-')
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(raw);f.flush();os.fsync(f.fileno())
        if path.exists():
            info=path.stat()
            os.chown(temp,info.st_uid,info.st_gid)
            os.chmod(temp,stat.S_IMODE(info.st_mode))
        else:
            os.chmod(temp,mode)
            os.chown(temp,0,33)
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def replace_conf_inplace(path:Path,expected:bytes,desired:bytes):
    # Nginx's /etc/nginx/conf.d/default.conf is a bind-mounted *file*.
    # Replacing the host inode would leave Nginx reading the OLD config.
    # Preserve inode and POSIX metadata, then verify bytes before reload.
    fd=os.open(path,os.O_RDWR|os.O_NOFOLLOW)
    try:
        with os.fdopen(fd,'r+b',closefd=False) as f:
            info=os.fstat(fd)
            if not stat.S_ISREG(info.st_mode):
                raise RuntimeError('NGINX_CONF_NOT_REGULAR')
            if f.read()!=expected:raise RuntimeError('NGINX_CONFIG_CONCURRENT_CHANGE')
            f.seek(0);f.write(desired);f.truncate();f.flush();os.fsync(fd)
    finally:
        os.close(fd)
    if path.read_bytes()!=desired:raise RuntimeError('NGINX_HOST_FILE_WRITE_NOT_VERIFIED')

def check_reload(container:str,verb:str):
    if verb not in ('-t','-s'):raise ValueError('UNSUPPORTED_NGINX_OPERATION')
    cmd=['docker','exec',container,'nginx',verb]+(['reload'] if verb=='-s' else [])
    res=subprocess.run(cmd,capture_output=True,text=True,timeout=15)
    if res.returncode:
        raise RuntimeError('nginx_'+verb+'_failed_'+str(res.returncode)+' '+res.stderr[-350:])
def write_receipt(site,body):
    STATE.mkdir(mode=0o700,parents=True,exist_ok=True)
    atomic(STATE/f'{site}.json',json.dumps(body,indent=2,sort_keys=True).encode()+b'\n',0o600)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--site',choices=SITES)
    ap.add_argument('--selftest',action='store_true')
    args=ap.parse_args()
    if args.selftest:
        original=b'map $uri $k {default 0;}\nfastcgi_cache_key "$host|$mad4b_fastcgi_version";\n'
        transformed=conf_with_epoch(original)
        assert transformed.endswith(b'fastcgi_cache_key "$host|$mad4b_fastcgi_version$mad4b_public_html_global_epoch";\n')
        assert transformed.count(b'default "";')==1
        assert b'|g0123456789ab' in set_epoch(transformed,'0123456789ab')
        try:conf_with_epoch(transformed)
        except ValueError:pass
        else:raise AssertionError('duplicate_enrollment_accepted')
        print('GLOBAL_EPOCH_SELFTEST_PASS');return
    site=args.site
    if not site:ap.error('--site required')
    cfg=SITES[site];signal=cfg['signal'];conf=cfg['conf'];container=cfg['container']
    old_state=json.loads((STATE/f'{site}.json').read_text()) if (STATE/f'{site}.json').is_file() else {}
    x=os.lstat(signal)
    if not stat.S_ISREG(x.st_mode) or x.st_uid!=33 or x.st_size!=len(TRIGGER):
        raise RuntimeError('UNSAFE_SITE_SIGNAL')
    if signal.read_bytes()!=TRIGGER:raise RuntimeError('UNRECOGNIZED_PURGE_SIGNAL')
    prev=int(old_state.get('processed_mtime_ns',0))
    if x.st_mtime_ns<=prev:
        print('NO_NEW_PUBLIC_PURGE_SIGNAL');return
    if old_state.get('last_rotated_unix',0)>time.time()-60:
        print('DEFER_COALESCED_EPOCH_COOLDOWN');return
    content=conf.read_bytes()
    assert b'$mad4b_public_html_global_epoch' in content
    epoch=hashlib.sha256((site+'|'+str(x.st_mtime_ns)+'|'+str(time.time_ns())).encode()).hexdigest()[:12]
    new=set_epoch(content,epoch)
    replace_conf_inplace(conf,content,new)
    try:
        check_reload(container,'-t')
        check_reload(container,'-s')
    except BaseException:
        replace_conf_inplace(conf,new,content)
        check_reload(container,'-t')
        try:check_reload(container,'-s')
        except Exception:pass
        raise
    result={'contract':'mad4b.public-html.global-epoch.v1','site':site,'utc':timestamp(),
            'status':'ok','old_config_sha256':sha(content),'new_config_sha256':sha(new),
            'new_epoch':'g'+epoch,'processed_mtime_ns':x.st_mtime_ns,
            'last_rotated_unix':int(time.time()),'nginx_validation':'PASS',
            'redis_mutated':False,'cache_keys_rotated_sitewide':True,
            'old_cache_files_not_deleted':True}
    write_receipt(site,result)
    atomic(cfg['status'],json.dumps(result,sort_keys=True).encode()+b'\n',0o640)
    print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()
