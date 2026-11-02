#!/usr/bin/env python3
"""Revalidate only EXISTING signed Original 305 Staging pairs via Cross-SSH.
No grant creation, no native executor activation, no Production writes.
"""
from __future__ import annotations
import json,os,pathlib,subprocess,sys,tempfile,time,stat
ROOT=pathlib.Path('/opt/mad4b-company-transport')
POLICY=ROOT/'native-operator-v1/host-authority.json'
ISSUER=pathlib.Path('/opt/mad4b-company-provisioning/source-candidates/cross-ssh-host-proof-v1/company-native-host-proof-cross-ssh.py')
REPORT=pathlib.Path('/opt/mad4b-company-provisioning/reports/company-cross-ssh-host-proof-current.json')
WP=['docker','exec','-u','33:33','etg-commerce-staging-php',
    '/usr/local/bin/wp','mad4b','company-native-wpcli','status']
def exact_json(args,timeout=35):
    p=subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=False)
    if p.returncode:raise RuntimeError('ORIGINAL_STATUS_READBACK_FAILED')
    rows=[v for v in p.stdout.splitlines() if v.startswith('{')]
    if len(rows)!=1:raise RuntimeError('ORIGINAL_STATUS_JSON_UNCERTAIN')
    result=json.loads(rows[0])
    if not isinstance(result,dict):raise RuntimeError('ORIGINAL_STATUS_TYPE_INVALID')
    return result
def main():
    if os.geteuid()!=0:raise RuntimeError('ROOT_ORIGINAL_PROOF_REFRESH_REQUIRED')
    st=POLICY.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077:
        raise RuntimeError('ROOT_POLICY_NOT_PROTECTED')
    policy=json.loads(POLICY.read_text())
    if policy.get('contract')!='mad4b.native-vps-operator.v1' or policy.get('revoked') is not False:
        raise RuntimeError('ORIGINAL_HOST_POLICY_INVALID')
    targets=[]
    for key,row in sorted(policy.get('scopes',{}).items()):
        if not key.endswith(':staging') or not isinstance(row,dict) or 'registry_binding' not in row:
            continue
        company=key.rsplit(':',1)[0]
        if row.get('allow_operator') is not True or row.get('allow_mutation') is not True:
            continue
        record=exact_json(WP+['--company='+company,'--environment=staging',
                              '--path=/var/www/html','--skip-themes'],timeout=40)
        if record.get('company')!=company or record.get('environment')!='staging':
            raise RuntimeError('ORIGINAL_305_COMPANY_SCOPE_MISMATCH')
        if record.get('state')!='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED':
            targets.append({'company':company,'environment':'staging',
                'state':'ORIGINAL_305_NOT_ENROLLED','proof_renewed':False})
            continue
        p=subprocess.run([sys.executable,str(ISSUER),'attest','--company='+company,
            '--environment=staging'],capture_output=True,text=True,timeout=115,check=False)
        if p.returncode:raise RuntimeError('CROSS_SSH_ORIGINAL_PROOF_REFUSED:'+company)
        lines=[v for v in p.stdout.splitlines() if v.startswith('{')]
        if len(lines)!=1:raise RuntimeError('CROSS_SSH_ORIGINAL_PROOF_UNCERTAIN')
        response=json.loads(lines[0])
        if response.get('company')!=company or response.get('environment')!='staging' or response.get('state')!='HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING' or response.get('signed_host_proof_verified') is not True:
            raise RuntimeError('ORIGINAL_SIGNED_PROOF_RESPONSE_MISMATCH')
        targets.append({'company':company,'environment':'staging',
            'state':response['state'],'proof_renewed':True,
            'native_executor_certified':False,'operation_executed':False})
    output={'contract':'mad4b.cross-ssh-original-host-proof-refresh.v1',
        'checked_at_epoch':int(time.time()),'targets':targets,
        'new_grants_created':False,'production_mutated':False,'site_execution_performed':False}
    REPORT.parent.mkdir(parents=True,exist_ok=True)
    fd,path=tempfile.mkstemp(dir=REPORT.parent,prefix='.original-proof-')
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600)
            json.dump(output,f,sort_keys=True)
            f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(path,REPORT)
    finally:
        if os.path.exists(path):os.unlink(path)
    print(json.dumps(output,sort_keys=True))
if __name__=='__main__':
    try:main()
    except (OSError,RuntimeError,ValueError,subprocess.SubprocessError) as err:
        print('ORIGINAL_PROOF_REFRESH_DENIED:'+str(err)[:160],file=sys.stderr)
        sys.exit(2)
