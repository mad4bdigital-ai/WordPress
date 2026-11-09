import collections
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit

sites = {
    'source': ('tours.dev.mad4b.com', 'php-wkgtkzta35wkkgouwt6ruljc'),
    'wayfa': ('wayfatours.com', 'php-guxhp9y6vgh7oouuw8plxrlr'),
}
name = sys.argv[1]
host, php = sites[name]
base = 'https://' + host
directory = pathlib.Path('/var/lib/mad4b-graph-coverage') / name
directory.mkdir(parents=True, exist_ok=True)
agents = {'mobile': 'Mozilla/5.0 (Linux; Android 13) Mobile', 'desktop': 'Mozilla/5.0 (X11; Linux x86_64)'}

def save(file, data):
    temp = file.with_suffix('.tmp')
    temp.write_text(json.dumps(data, ensure_ascii=False))
    os.replace(temp, file)

def curl(*args):
    return subprocess.check_output(['curl','--silent','--show-error','--fail','--max-time','20','--globoff',*args],text=True)

def same_site(url):
    p=urlsplit(url)
    return p.scheme=='https' and p.netloc==host and not p.query and not p.fragment and not re.search(r'[\x00-\x20]',url)

def inventory():
    file=directory/'inventory.json'
    if file.exists() and time.time()-file.stat().st_mtime<21600:
        return json.loads(file.read_text())
    index=ET.fromstring(curl(base+'/sitemap_index.xml'))
    rows={'/':'home'}
    for item in index.findall('{*}sitemap/{*}loc'):
        if not same_site(item.text): raise RuntimeError('Unexpected sitemap origin')
        family=urlsplit(item.text).path.split('-sitemap')[0].strip('/')
        tree=ET.fromstring(curl(item.text))
        for loc in tree.findall('{*}url/{*}loc'):
            if same_site(loc.text):
                rows[urlsplit(loc.text).path]=family
    save(file, rows)
    return rows

def graph():
    code = """global $wpdb;$p=$wpdb->prefix.'mad4b_pd_pages';$e=$wpdb->prefix.'mad4b_pd_edges';
    $q="SELECT p.page_path,p.device,p.language FROM $p p WHERE p.node_kind='page' AND p.expires_at>UTC_TIMESTAMP()
    AND EXISTS(SELECT 1 FROM $e e WHERE e.page_hash=p.page_hash AND e.dep_key IN (CONCAT('page_path:',p.page_path),CONCAT('page_path_hash:',SHA2(p.page_path,256))) AND e.expires_at>UTC_TIMESTAMP())";
    $rows=$wpdb->get_results($q,ARRAY_N);if($wpdb->last_error)exit(2);echo json_encode($rows);"""
    return {(canonical_path(path), device, locale) for path, device, locale in json.loads(subprocess.check_output(['docker','exec',php,'wp','--allow-root','eval',code],timeout=40))}

def language(path):
    segment=path.split('/')[1]
    return segment if segment in ['ar','fr','de','it','es'] else 'en'

def canonical_path(path):
    return re.sub(r'%[0-9a-fA-F]{2}', lambda m: m.group().upper(), path)

rows={canonical_path(path): family for path, family in inventory().items()}
from urllib.parse import unquote, quote
def commerce_paths():
    code = """$lang=apply_filters('wpml_active_languages',null,['skip_missing'=>0]) ?: [];
    $out=[];foreach(['cart','checkout','myaccount'] as $role){
      $base=(int)get_option('woocommerce_'.$role.'_page_id');
      if($base<1)continue;
      foreach(array_merge([null],array_keys($lang)) as $l){
        $id=$l ? apply_filters('wpml_object_id',$base,'page',false,$l) : $base;
        if(!$id || get_post_status($id)!=='publish')continue;
        $url=get_permalink($id);if($l)$url=apply_filters('wpml_permalink',$url,$l,true);
        $out[wp_parse_url($url,PHP_URL_PATH)]=$role;
      }
    }echo wp_json_encode((object)$out);"""
    result=subprocess.check_output(['docker','exec',php,'wp','--allow-root','eval',code],timeout=45)
    return {unquote(p): role for p,role in json.loads(result).items()}

def probe_special(path,role):
    proc=subprocess.run(['curl','-sS','--max-time','18','--globoff','-D','-','-o','/dev/null',base+path],capture_output=True,text=True)
    headers={}
    status=0
    for line in proc.stdout.splitlines():
        if line.startswith('HTTP/'):
            status=int(line.split()[1]); headers={}
        elif ':' in line:
            key,value=line.split(':',1); headers[key.lower()]=value.strip()
    target=headers.get('location','')
    from urllib.parse import urljoin
    dest=urlsplit(urljoin(base+path,target))
    private=bool({'private','no-store'} & {x.strip() for x in headers.get('cache-control','').lower().split(',')})
    hit=any('cache' in k and v.upper()=='HIT' for k,v in headers.items())
    redirects=status in (301,302,303,307,308) and dest.scheme=='https' and dest.netloc==host
    if role=='xml':
        ok=status==200 and 'xml' in headers.get('content-type','').lower()
    elif role=='checkout':
        ok=redirects and roles.get(unquote(dest.path))=='cart' and language(dest.path)==language(path)
    elif role=='account_gate':
        ok=redirects and dest.path=='/wp-login.php'
    else:
        ok=status==200
    if role!='xml': ok=ok and private and not hit
    return {'path':path,'role':role,'http':status,'location_path':dest.path if target else None,'private':private,'cache_hit':hit,'verified':bool(ok and proc.returncode==0)}

roles=commerce_paths()
account_aliases={'/it/mio-account/','/es/mi-cuenta/','/fr/mon-compte/','/de/mein-konto/','/ar/حسابي/'}
for path in rows:
    if unquote(path) in account_aliases:
        roles.setdefault(unquote(path),'myaccount')
for path in roles:
    if path and path.startswith('/') and path!='/':
        rows.setdefault(quote(path,safe="/-._~"),'commerce')
special={}
for path in rows:
    role=roles.get(unquote(path))
    if path=='/account/' and not role: role='account_gate'
    if path=='/locations.kml': role='xml'
    if role: special[path]=role


known=graph()
def missing():
    return [(p,d,language(p)) for p in rows if p not in special for d in agents if (p,d,language(p)) not in known]

def report():
    gaps=missing()
    counts=collections.Counter((language(p),d) for p,d,_ in gaps)
    print(json.dumps({'site':name,'paths':len(rows),'special_paths':len(special),'variants':2*(len(rows)-len(special)),'covered':2*(len(rows)-len(special))-len(gaps),
                      'missing_by_locale_device':{'/'.join(k):v for k,v in sorted(counts.items())}}),flush=True)

report()
if '--audit' in sys.argv:
    failure_file = directory/'unverified.json'
    previous = json.loads(failure_file.read_text()) if failure_file.exists() else {}
    evidence = {}
    for key, result in previous.items():
        device, _, path = key.partition(' ')
        evidence[device+' '+canonical_path(path)] = result
        print(json.dumps({'record': 'retained_probe', 'path': path, 'device': device,
                          'last_probe': result}, ensure_ascii=False), flush=True)
    for path,role in special.items():
        print(json.dumps(probe_special(path,role)),flush=True)
    for path, device, locale in missing():
        print(json.dumps({'path':path, 'device':device, 'language':locale,
                          'last_probe':evidence.get(device+' '+path)}, ensure_ascii=False))
    sys.exit()
attempts_file=directory/'attempts.json'
attempts=json.loads(attempts_file.read_text()) if attempts_file.exists() else {}
def attemptkey(row): return row[1]+' '+row[0]
def rank(row):
    p,d,lang=row
    priority=0 if p in ('/','/'+lang+'/') else 1 if rows[p] in ('destinations','tours-and-activities') else 2
    return attempts.get(attemptkey(row),0),priority,p,d
if os.getloadavg()[0] > (os.cpu_count() or 1):
    print('deferred: host load above CPU count',flush=True)
    sys.exit()
selected=sorted((row for row in missing() if attempts.get(attemptkey(row),0)<time.time()-3600),key=rank)[:12]
observed={}
for p,d,lang in selected:
    if os.getloadavg()[0] > (os.cpu_count() or 1): break
    attempts[attemptkey((p,d,lang))]=int(time.time())
    try:
        status=curl('-A',agents[d],'-H','X-MAD4B-Bypass-Cache: 1','-o','/dev/null','-w','%{http_code}',base+p)
    except subprocess.CalledProcessError:
        status='error'
    observed[(p,d,lang)]=status
    print('probe',d,p,status,flush=True)
    save(attempts_file,attempts)
if selected:
    known=graph()
    failure_file=directory/'unverified.json'
    failures=json.loads(failure_file.read_text()) if failure_file.exists() else {}
    for row,status in observed.items():
        key=attemptkey(row)
        if row in known:
            failures.pop(key,None)
        else:
            failures[key]={'http':status,'at':int(time.time()),'language':row[2]}
    save(failure_file,failures)
    print('verified',sum(row in known for row in observed),'of',len(observed),'unverified',len(failures),flush=True)
    report()
