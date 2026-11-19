#!/usr/bin/env python3
"""Rotate only Wayfatours EN/ES home FastCGI cache namespace after valid CSS promotion.

Nginx binds its company conf to the *same host inode* as a read-only file.
Consequently a host os.replace would not update that mount; rewrite the
existing inode with a guarded locked file descriptor and immediate nginx -t.
Roll back the exact original bytes on any test/reload mismatch.
Never delete cache files or alter sensitive WooCommerce route keys.
"""
import fcntl,hashlib,json,os,re,stat,subprocess,sys
from pathlib import Path

HOST='wayfatours.com'
PATTERN=re.compile(r'(?P<pre>map \$request_uri \$wayfa_home_firstpaint_epoch \{\s*\n'
  r'\s*default "";\s*\n'
  r'\s*~\^/\(\?:es/\)\?\$ "\|pref-firstpaint-v1)'
  r'(?:-cc-(?P<rev>[0-9a-f]{16}))?(?P<post>";\s*\n\})',re.M)
def derive_manifest_epoch(manifest:dict,inventory:dict)->str:
    if (manifest.get('site')!=HOST or manifest.get('version')!=1
        or not isinstance(manifest.get('entries'),dict)):
        raise ValueError('wrong site or manifest version')
    if (inventory.get('host')!=HOST
        or inventory.get('contract')!='mad4b.mobile-critical.candidates.v1'
        or inventory.get('errors')):
        raise ValueError('invalid company QA inventory')
    rows=inventory['candidates']
    locales={r['lang'] for r in rows}
    # Rotate home cache keys after each independently verified locale.
    # Both paths share the legacy Nginx home map, but all unrelated
    # manifest entries and original WordPress markup remain unchanged.
    if not 1<=len(rows)<=2 or len(locales)!=len(rows) or not locales<={'en','es'}:
        raise ValueError('EN/ES original company route subset required')
    for r in rows:
        entry=manifest['entries'].get(r['source_sha'])
        if (not isinstance(entry,dict)
            or entry.get('gate_status')!='pass'
            or entry.get('languages')!=[r['lang']]
            or entry.get('critical_sha')!=r['critical_sha']):
            raise ValueError('current CSS source not certified for '+r['lang'])
    # Delivery-v2 namespace additionally signals that the static CSS asset
    # passed an actual PHP-readability verification before cache turnover.
    # This repairs the former v1 publication that wrote correct bytes 0600
    # due to the host's umask=077, leaving cached Ready=0 responses.
    serialized=json.dumps(manifest,sort_keys=True,separators=(',',':'))
    return hashlib.sha256(('mad4b-public-critical-css-readable-v2|'+serialized).encode()).hexdigest()[:16]

def verify_readable_assets(manifest:dict,inventory:dict,wp_content:Path):
    expected=wp_content/'mad4b-critical-css-v1'
    if str(expected)!='/opt/wayfa-rebuild-20261102/site-copy/wp-content/mad4b-critical-css-v1':
        raise ValueError('not Wayfatours original certificate storage')
    for row in inventory['candidates']:
        entry=manifest['entries'][row['source_sha']]
        name=entry['file']
        if not isinstance(name,str) or not re.fullmatch(r'[a-f0-9]{64}\.css',name):
            raise ValueError('invalid first-paint asset filename')
        asset=expected/name
        if not asset.is_file() or asset.is_symlink():
            raise ValueError('missing critical CSS asset')
        meta=asset.stat()
        if (not (stat.S_IMODE(meta.st_mode)&0o004)
            or meta.st_size!=entry['critical_bytes']
            or hashlib.sha256(asset.read_bytes()).hexdigest()!=entry['critical_sha']):
            raise ValueError('first-paint asset is not PHP-readable or changed')

def rewrite_home_map(source:str,revision:str):
    if not re.fullmatch(r'[0-9a-f]{16}',revision):
        raise ValueError('invalid cache namespace')
    matches=list(PATTERN.finditer(source))
    if len(matches)!=1:raise ValueError('expected exactly one home cache map')
    m=matches[0]
    if not re.fullmatch(r'[0-9a-f]{16}',m.group('rev') or '') and m.group('rev') is not None:
        raise ValueError('invalid previous map token')
    value=m.group('pre')+'-cc-'+revision+m.group('post')
    after=source[:m.start()]+value+source[m.end():]
    assert after.count('|pref-firstpaint-v1-cc-'+revision)==1
    return after,source!=after

def main():
    if len(sys.argv)!=5:
        raise ValueError('manifest, inventory, nginx config, backup dir required')
    manifest_path,inventory_path,nginx_path,backup_path=map(Path,sys.argv[1:])
    manifest=json.loads(manifest_path.read_text())
    inventory=json.loads(inventory_path.read_text())
    revision=derive_manifest_epoch(manifest,inventory)
    verify_readable_assets(manifest,inventory,Path(
        '/opt/wayfa-rebuild-20261102/site-copy/wp-content'))
    assert str(nginx_path)=='/opt/wayfa-rebuild-20261102/stack/nginx.conf'
    assert nginx_path.is_file() and not nginx_path.is_symlink()
    backup_path.mkdir(parents=True,exist_ok=True)
    os.chmod(backup_path,0o700)
    lock=backup_path/'wayfa-home-cache-refresh.lock'
    with lock.open('w') as handle:
        fcntl.flock(handle.fileno(),fcntl.LOCK_EX)
        original=nginx_path.read_bytes()
        prev=original.decode()
        next_text,changed=rewrite_home_map(prev,revision)
        if not changed:
            print('READY current Wayfatours home FastCGI epoch',revision)
            return
        old_sha=hashlib.sha256(original).hexdigest()
        backup=backup_path/('nginx-home-before-cc-'+old_sha[:16]+'.conf')
        if not backup.exists():
            fd=os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as f:f.write(original);f.flush();os.fsync(f.fileno())
        # Bind mount is path->inode; update the SAME inode, never os.replace.
        fd=os.open(nginx_path,os.O_RDWR|os.O_NOFOLLOW)
        try:
            if os.read(fd,len(original))!=original:
                raise ValueError('Nginx configuration changed during cache epoch plan')
            def write(blob:bytes):
                os.lseek(fd,0,os.SEEK_SET)
                os.write(fd,blob);os.ftruncate(fd,len(blob));os.fsync(fd)
            write(next_text.encode())
            try:
                subprocess.run(['docker','exec','nginx-guxhp9y6vgh7oouuw8plxrlr',
                                'nginx','-t'],check=True,capture_output=True,timeout=25)
                runtime=subprocess.run(['docker','exec','nginx-guxhp9y6vgh7oouuw8plxrlr',
                              'sha256sum','/etc/nginx/conf.d/default.conf'],
                              check=True,capture_output=True,text=True,timeout=10)
                if runtime.stdout.split()[0]!=hashlib.sha256(next_text.encode()).hexdigest():
                    raise ValueError('Container bind does not see updated host config inode')
                subprocess.run(['docker','exec','nginx-guxhp9y6vgh7oouuw8plxrlr',
                                'nginx','-s','reload'],check=True,capture_output=True,timeout=20)
            except Exception:
                write(original)
                subprocess.run(['docker','exec','nginx-guxhp9y6vgh7oouuw8plxrlr',
                                'nginx','-t'],capture_output=True,timeout=25)
                raise
        finally:os.close(fd)
        print('ROTATED Wayfatours homepage cache only','revision='+revision,
              'previous_conf_sha='+old_sha,'backup='+str(backup))

if __name__=='__main__':
    try:main()
    except Exception as exc:
        print('HOLD: Selective home cache epoch not committed',str(exc),file=sys.stderr)
        sys.exit(4)
