#!/usr/bin/env python3
"""Bounded fleet-local media readiness scans; never imports images or runs in page requests."""
import fcntl,json,os,subprocess,sys,time
from pathlib import Path
from datetime import datetime,timezone
ROOT=Path('/opt/mad4b-company-provisioning/reports/media-universal-20261110')
FLEET=Path('/opt/mad4b-company-provisioning/releases/configuration-jobs-v1/fleet-sites.json')

def enrolled_sites():
    config=json.loads(FLEET.read_text())
    if config.get('contract')!='mad4b.configuration-host-collector-sources.v1':
        raise RuntimeError('fleet_registry_contract_mismatch')
    registry={}
    for row in config.get('local_sites',[]):
        name=str(row['company_code'])+':'+str(row['environment'])
        container=row['container'];uuid=row['site_uuid']
        if not (isinstance(container,str) and container.startswith('php-') and
            isinstance(uuid,str) and len(uuid)==36 and name not in registry):
            raise RuntimeError('local_registry_identity_invalid')
        registry[name]=(container,uuid)
    if not registry:raise RuntimeError('local_registry_empty')
    return registry

READ=r'''$p=(array)get_option('mad4b_scp_site_profile_v2',[]);
if(!class_exists('MAD4B_Universal_Media_Readiness_V1')||!class_exists('MAD4B_Configuration_Jobs')){echo wp_json_encode(['state'=>'adapter_unavailable']);return;}
$r=MAD4B_Configuration_Jobs::scan();
$media=[];foreach((array)get_option(MAD4B_Configuration_Jobs::STORE,[]) as $row)if(($row['provider']??'')==='universal-media'&&($row['state']??'')==='open')$media[]=$row;
$by=[];foreach($media as $x){$severity=$x['severity']??'other';$by[$severity]=($by[$severity]??0)+1;}
echo wp_json_encode(['contract'=>'mad4b.universal-media-scheduled-scan.v1','site_uuid'=>(string)($p['site_uuid']??''),'scan'=>$r,'open_count'=>count($media),'by_severity'=>$by,'generated_at'=>gmdate('c')]);'''
def main():
 ROOT.mkdir(exist_ok=True,parents=True,mode=0o700)
 with open(ROOT/'scheduled-scans.lock','a+') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:print('SCAN_ALREADY_RUNNING');return
  sites=enrolled_sites()
  results=[]
  for name,(container,uuid) in sites.items():
   r=subprocess.run(['docker','exec','-u','www-data',container,'wp','--path=/var/www/html','eval',READ],capture_output=True,text=True,timeout=150)
   if r.returncode:results.append({'site':name,'state':'failed','reason':'wpcli_exit_'+str(r.returncode)});continue
   try:
    lines=[json.loads(x) for x in r.stdout.splitlines() if x.strip().startswith('{')]
    assert len(lines)==1 and lines[0].get('contract')=='mad4b.universal-media-scheduled-scan.v1'
    x=lines[0]
    if uuid:assert x.get('site_uuid')==uuid
    assert x['scan'].get('status')=='scanned' and x['scan'].get('errors')==0
    results.append({'site':name,'state':'verified','site_uuid':x['site_uuid'],'open':x['open_count'],'by_severity':x['by_severity'],'scanned_at':x['generated_at']})
   except Exception as e:results.append({'site':name,'state':'invalid_result','reason':type(e).__name__})
  output={'contract':'mad4b.universal-media-fleet-scan.v1','at':datetime.now(timezone.utc).isoformat(),'results':results,'all_verified':len(results)==len(sites) and all(q['state']=='verified' for q in results)}
  target=ROOT/'latest-scheduled-scan.json';tmp=ROOT/('latest-scheduled-scan.'+str(os.getpid())+'.tmp');tmp.write_text(json.dumps(output,indent=2));os.chmod(tmp,0o600);tmp.replace(target)
  print(json.dumps(output))
  if not output['all_verified']:sys.exit(2)
if __name__=='__main__':main()
