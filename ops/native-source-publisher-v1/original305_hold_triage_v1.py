#!/usr/bin/env python3
"""Read-only Original305 signed-readiness incident triage.

No signer, no WordPress writes, no HOLD mutation, no automatic reconciliation.
Reports facts separately from any Master/Host312/Original313 release approval.
"""
from __future__ import annotations
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import time

REGISTRY=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/index.json')
HOST=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1')
HOLD=HOST/'original305-readiness-ambiguous-write-HOLD.json'
LOCK=HOST/'original305-broker-readiness-refresh.lock'
TIMER='mad4b-original-broker-host-readiness.timer'
EXPECTED_REGISTRY='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'
CONTRACT='mad4b.original305-readiness-incident-readonly-triage.v1'


def timer_state(*,run=subprocess.run)->str:
    try:
        p=run(['/usr/bin/systemctl','is-enabled',TIMER],capture_output=True,text=True,timeout=9)
        s=(p.stdout or '').strip()
        return s if s in ('enabled','disabled','masked','static') else 'UNKNOWN'
    except (OSError,subprocess.SubprocessError):return 'UNKNOWN'


def wp_readback(*,run=subprocess.run)->dict:
    # Only an enrolled Site Profile resolves the WordPress executor. No Docker
    # name, master key, private signing material or untrusted PHP parameters.
    sys.path.insert(0,'/opt/mad4b-company-transport')
    from central_wpcli_runtime import central_wp
    php="""$p=get_option('mad4b_original_host_source_readiness_v1',false);
$ad=class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
 ? MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted('host.source.release','host','host.source.ref.v1') : false;
echo wp_json_encode(['present'=>is_array($p),'issued'=>is_array($p)?($p['issued_epoch']??null):null,
'expires'=>is_array($p)?($p['expires_epoch']??null):null,'admitted'=>$ad]);"""
    p=run(central_wp()+['eval',php],capture_output=True,text=True,timeout=42)
    if p.returncode!=0:return {'state':'WPCLI_READ_FAILED','signed_readiness_admitted':False}
    try:
        rows=[json.loads(x) for x in p.stdout.splitlines() if x.startswith('{')]
        if len(rows)!=1 or not isinstance(rows[0],dict):raise ValueError('bad WP output')
        row=rows[0]
        if (type(row.get('present')) is not bool or
            type(row.get('admitted')) is not bool or
            (row['present'] and (type(row.get('issued')) is not int or
             type(row.get('expires')) is not int))):raise ValueError('bad WP schema')
        now=int(time.time());expires=row.get('expires') if row['present'] else None
        return {'state':'SIGNED_LEASE_CURRENT' if row['admitted'] else
                'SIGNED_LEASE_EXPIRED_OR_UNADMITTED' if row['present'] else
                'SIGNED_LEASE_ABSENT',
                'signed_readiness_admitted':row['admitted'],
                'signed_issued_epoch':row.get('issued') if row['present'] else None,
                'signed_expires_epoch':expires,
                'seconds_until_expiry':expires-now if isinstance(expires,int) else None}
    except (ValueError,TypeError):
        return {'state':'WPCLI_OUTPUT_INVALID','signed_readiness_admitted':False}


def lock_is_busy()->bool|None:
    try:
        fd=os.open(LOCK,os.O_RDONLY|os.O_NOFOLLOW)
        try:
            m=os.fstat(fd)
            if not stat.S_ISREG(m.st_mode) or m.st_uid!=0 or m.st_nlink!=1:return None
            try:
                fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
                fcntl.flock(fd,fcntl.LOCK_UN)
                return False
            except BlockingIOError:return True
        finally:os.close(fd)
    except OSError:return None


def check(*,wp_reader=wp_readback)->dict:
    result={'contract':CONTRACT,'state':'INCOMPLETE_EVIDENCE',
            'signer_invoked':False,'wordpress_changed':False,
            'hold_cleared':False,'renewal_enabled_by_this_check':False,
            'host_release_authorized':False}
    if os.geteuid()!=0:
        result.update({'state':'ROOT_REQUIRED'});return result
    try:
        raw=REGISTRY.read_bytes()
        result['original_v8_registry_exact']=hashlib.sha256(raw).hexdigest()==EXPECTED_REGISTRY
        m=HOLD.lstat() if HOLD.exists() or HOLD.is_symlink() else None
        result['legacy_hold_present']=m is not None
        if m is not None:
            assert stat.S_ISREG(m.st_mode) and m.st_uid==0 and m.st_gid==0 and m.st_nlink==1 and stat.S_IMODE(m.st_mode)==0o600
            value=json.loads(HOLD.read_bytes())
            result['legacy_hold_reason_code']=value.get('reason_code') if isinstance(value.get('reason_code'),str) else 'INVALID'
            result['legacy_hold_created_epoch']=int(m.st_mtime)
            result['legacy_hold_sha256']=hashlib.sha256(HOLD.read_bytes()).hexdigest()
        result['timer_state']=timer_state()
        result['original_signer_lock_busy_now']=lock_is_busy()
        result.update(wp_reader())
        if not result['original_v8_registry_exact']:
            result['state']='ORIGINAL_REGISTRY_CHANGED_HOLD'
        elif result['legacy_hold_present']:
            result['state']='LEGACY_AMBIGUOUS_WRITE_HOLD_NEEDS_GOVERNED_RECONCILIATION'
        elif result.get('state') in ('WPCLI_READ_FAILED','WPCLI_OUTPUT_INVALID'):
            result['state']='ORIGINAL305_WORDPRESS_READBACK_UNAVAILABLE'
        elif result.get('signed_readiness_admitted') is True:
            result['state']='SIGNED_HOST_READINESS_ACCEPTED'
        else:
            result['state']='NO_CURRENT_SIGNED_READINESS'
    except (OSError,ValueError,KeyError,TypeError,AssertionError,subprocess.SubprocessError):
        result['state']='INCIDENT_EVIDENCE_INVALID_OR_UNAVAILABLE'
    result['next_authorized_step']=('ORIGINAL_FIRSTPARTY_HOLD_INCIDENT_REVIEW' if result.get('legacy_hold_present') else
                                    'NORMAL_ORIGINAL_BROKER_READINESS_OPERATIONS')
    return result

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--check',action='store_true',required=True)
    parser.parse_args()
    print(json.dumps(check(),sort_keys=True))
