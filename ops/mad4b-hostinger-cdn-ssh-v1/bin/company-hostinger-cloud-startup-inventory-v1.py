#!/usr/bin/env python3
"""Read-only pinned SSH discovery of Cloud Startup WordPress company sites."""
import datetime,json,os,subprocess,shlex,tempfile,hashlib
from pathlib import Path
ROOT=Path('/opt/mad4b-company-provisioning')
REPORT=ROOT/'reports/hostinger-cloud-startup-sites-current.json'
D=Path('/opt/hostinger-shell-mcp/external-targets/hosting-1')
ACCOUNT='u338416126'
HOST='147.93.49.130'
SSH=['ssh','-F','/dev/null','-T','-o','BatchMode=yes','-o','IdentitiesOnly=yes',
     '-o','StrictHostKeyChecking=yes','-o','UpdateHostKeys=no',
     '-o','UserKnownHostsFile='+str(D/'known_hosts_verified'),'-o','ConnectTimeout=8',
     '-i',str(D/'id_ed25519'),'-p','65002',ACCOUNT+'@'+HOST]
REMOTE_SCRIPT=r'''
import os,subprocess,json,pathlib
base=pathlib.Path('/home/u338416126/domains')
roots=[]
for domain in sorted(base.iterdir()):
  public=domain/'public_html'
  if not public.is_dir() or domain.name.startswith(('.', 'wpfm-')):continue
  if (public/'wp-config.php').is_file():roots.append(public)
  for sub in sorted(public.iterdir()):
    if sub.is_dir() and (sub/'wp-config.php').is_file():roots.append(sub)
sites=[]
for root in roots:
  def wp(*args):
    p=subprocess.run(['wp',*args,'--skip-themes','--skip-plugins'],cwd=str(root),stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True,timeout=17)
    return p.stdout.strip() if p.returncode==0 else None
  home=wp('option','get','home')
  if not home or not home.startswith('https://'):continue
  from urllib.parse import urlparse
  parsed=urlparse(home)
  if parsed.username or parsed.password or parsed.port or parsed.path not in ('','/') or not parsed.hostname:continue
  if not parsed.hostname.endswith('.'+root.parts[4]) and parsed.hostname!=root.parts[4] and parsed.hostname!='www.'+root.parts[4]:continue
  active=wp('plugin','list','--status=active','--field=name') or ''
  installed=[x for x in active.splitlines() if x.startswith(('mad4b','hostinger','litespeed','woocommerce','sitepress','wpml'))]
  ttl={}
  if 'litespeed-cache' in active.splitlines():
    for key in ('litespeed.conf.cache-ttl_pub','litespeed.conf.cache-ttl_frontpage'):
      ttl[key]=wp('option','get',key)
  sites.append({'home':home,'remote_root':str(root),'observed_active_relevant_plugins':installed,'litespeed_ttl':ttl,
                'mad4b_cache_lifecycle_installed_active':'mad4b-cache-lifecycle' in active.splitlines(),
                'mad4b_control_plane_installed_active':'mad4b-site-control-plane' in active.splitlines()})
print('MAD4B_CLOUD_INVENTORY_JSON='+json.dumps({'sites':sites,'count':len(sites)},separators=(',',':')))
'''
def main():
    assert all(x.is_file() for x in (D/'known_hosts_verified',D/'id_ed25519'))
    import shlex
    cmd='python3 -c '+shlex.quote(REMOTE_SCRIPT)
    r=subprocess.run(SSH+[cmd],capture_output=True,text=True,timeout=155)
    if r.returncode:raise RuntimeError('CLOUD_SSH_INVENTORY_FAILED_'+str(r.returncode))
    prefix='MAD4B_CLOUD_INVENTORY_JSON='
    lines=[x[len(prefix):] for x in r.stdout.splitlines() if x.startswith(prefix)]
    if len(lines)!=1:raise RuntimeError('CLOUD_INVENTORY_NO_EXACT_JSON')
    data=json.loads(lines[0])
    data.update({'contract':'mad4b.hostinger-cloud-startup-sites-readonly.v1',
                 'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                 'ssh_binding':'hosting-1','home_verified_by_wpcli':True,
                 'mode':'READ_ONLY_NO_SITE_WRITES_NO_GLOBAL_PURGE',
                 'full_hostinger_account_api_inventory':False})
    REPORT.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.hostinger-cloud-',dir=REPORT.parent)
    try:
      with os.fdopen(fd,'w') as f:json.dump(data,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
      os.chmod(tmp,0o600);os.replace(tmp,REPORT)
    finally:
      if os.path.exists(tmp):os.unlink(tmp)
    print(json.dumps({'count':data['count'],'homes':[x['home'] for x in data['sites']],
                      'configured_mad4b_sites':[x['home'] for x in data['sites'] if x['mad4b_cache_lifecycle_installed_active']],
                      'report':str(REPORT)}))
if __name__=='__main__':main()
