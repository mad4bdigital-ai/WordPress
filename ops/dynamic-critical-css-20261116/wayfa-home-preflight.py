#!/usr/bin/env python3
"""Once-per-dependency-change exact EN/ES home FastCGI generation refresh.

This prevents a stale 645KB Elementor/destination menu HTML from being
compared to a newer 865KB HTML after an otherwise successful CSS QA run.
No wildcard purge, no nginx config modification, no cart/account routes.
"""
import fcntl,hashlib,importlib.util,json,os,re,socket,stat,sys,tempfile,time
from pathlib import Path
BASE=Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
HOST='wayfatours.com'
def read(path):
    p=Path(path)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>65536:
        raise ValueError('invalid-or-oversized-firstparty-state')
    return json.loads(p.read_text())
def validate(source,manifest,consumer,site_root=None):
    if site_root is None:site_root=BASE
    if str(site_root)!=str(BASE) or source.get('site_host')!=HOST:
        raise ValueError('foreign-site-root')
    if (source.get('environment')!='production'
        or source.get('contract')!='mad4b.critical-css.site-source.v1'
        or source.get('critical_css')!='on' or source.get('native_breakpoint')!=767
        or source.get('wpml_languages')!=['en','es']
        or source.get('default_language')!='en'
        or not isinstance(source.get('css_epoch'),int)
        or source['css_epoch']<1):
        raise ValueError('unexpected-company-WPML-or-native-settings')
    if (manifest.get('site')!=HOST or manifest.get('version')!=1
        or not isinstance(manifest.get('entries'),dict)):
        raise ValueError('invalid-original-wayfa-manifest')
    if not re.fullmatch(r'[a-f0-9]{64}',consumer):
        raise ValueError('invalid-consumer-hash')
    return hashlib.sha256(json.dumps({
        'contract':'mad4b.wayfa-home.qa-source-refresh.v1',
        'host':HOST,
        'css_epoch':source['css_epoch'],
        'home_dependency':source.get('critical_css_dirty_generation',0),
        'home_impact':source.get('last_home_impact_at',0),
        'active_languages':['en','es'],
        'original_consumer_sha':consumer,
        'runtime_versions':source.get('runtime_versions',{})
    },sort_keys=True,separators=(',',':')).encode()).hexdigest()
def version_exact_paths(root):
    endpoint=root/'.mad4b-purge/origin.sock'
    if endpoint.is_symlink() or not endpoint.exists() or not stat.S_ISSOCK(endpoint.stat().st_mode):
        raise ValueError('native-origin-generation-socket-unavailable')
    routes=['/','/es/']
    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as client:
        client.settimeout(10)
        client.connect(str(endpoint))
        client.sendall(json.dumps(routes,separators=(',',':')).encode()+b'\n')
        line=client.makefile('rb').readline(4096)
    obj=json.loads(line)
    if obj.get('status')!='ok' or obj.get('paths')!=2 or obj.get('versioned')!=2:
        raise ValueError('native-origin-rejected-exact-wayfa-home-routes')
    return {'paths':2,'versioned':2,'status':'ok'}
def write(path,obj):
    path=Path(path)
    if path.is_symlink() or path.parent.is_symlink():raise ValueError('symlink-ledger-refused')
    path.parent.mkdir(parents=True,mode=0o700,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.preflight-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600)
            json.dump(obj,f,sort_keys=True,indent=2);f.write('\n')
            f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    if len(sys.argv)!=6:
        raise ValueError('usage: site-state manifest consumer-php ledger receipt')
    state,manifest,consumer_file,ledger,receipt=map(Path,sys.argv[1:])
    source=read(state);published=read(manifest)
    if str(manifest)!=str(BASE/'mad4b-critical-css-v1/manifest.json'):
        raise ValueError('wrong-original-company-manifest')
    if consumer_file!=BASE/'mu-plugins/415-mad4b-critical-css-orchestrator.php' or consumer_file.is_symlink():
        raise ValueError('untrusted-legacy-consumer')
    key=validate(source,published,hashlib.sha256(consumer_file.read_bytes()).hexdigest())
    ledger.parent.mkdir(mode=0o700,exist_ok=True,parents=True)
    if ledger.is_symlink():raise ValueError('symlink-state-ledger')
    lock=ledger.with_suffix('.lock')
    if lock.is_symlink():raise ValueError('symlink-lock')
    with lock.open('a+') as f:
        fcntl.flock(f.fileno(),fcntl.LOCK_EX)
        old=read(ledger) if ledger.is_file() else {}
        if old and (old.get('contract')!='mad4b.wayfa-home.qa-source-refresh.v1'
                    or old.get('status')!='ok' or not isinstance(old.get('key'),str)):
            raise ValueError('invalid-or-cross-company-original-freshness-ledger')
        if old.get('contract')=='mad4b.wayfa-home.qa-source-refresh.v1' and old.get('key')==key and old.get('status')=='ok':
            result={'contract':'mad4b.wayfa-home.qa-source-refresh.v1','key':key,
                    'action':'skip','paths':['/','/es/'],'status':'ok'}
        else:
            version_exact_paths(BASE)
            result={'contract':'mad4b.wayfa-home.qa-source-refresh.v1','key':key,
                    'action':'refresh','paths':['/','/es/'],'status':'ok',
                    'epoch':source['css_epoch'],'at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}
            write(ledger,result)
        write(receipt,result)
    print('WAYFA_HOME_QA_EXACT_CACHE_PREFLIGHT',result['action'],result['paths'],key[:16])
if __name__=='__main__':
    try:main()
    except (ValueError,OSError,KeyError,TypeError,IndexError,json.JSONDecodeError) as exc:
        print('HOLD_WAYFA_HOME_QA_PREFLIGHT',str(exc),file=sys.stderr);sys.exit(4)
