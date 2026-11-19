#!/usr/bin/env python3
"""Host-to-WordPress diagnostics for Original305, never an approval channel.

The only WP mutation is a non-authoritative, auto-expiring diagnostic option.
This script cannot call the original signer, remove HOLD, enable readiness
renewals, issue tickets, authenticate as a Master, or publish Host source.
"""
from __future__ import annotations
import argparse,base64,hashlib,importlib.util,json,os
from pathlib import Path
import re,subprocess,sys,time

HOST=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1')
TRIAGE=HOST/'original305_hold_triage_v1.py'
TRIAGE_SHA='8268de82651a5b2ffbfd4a675c36ac12048da71a492490eeb2c663bb61c83f83'
REGISTRY=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/index.json')
REGISTRY_SHA='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'
HOLD=HOST/'original305-readiness-ambiguous-write-HOLD.json'
CONTRACT='mad4b.original305-diagnostic-snapshot.v1'
OPTION='mad4b_original305_runtime_observation_v1'
STATES={
    'LEGACY_AMBIGUOUS_WRITE_HOLD_NEEDS_GOVERNED_RECONCILIATION',
    'SIGNED_HOST_READINESS_ACCEPTED',
    'NO_CURRENT_SIGNED_READINESS',
    'ORIGINAL_REGISTRY_CHANGED_HOLD',
    'INCIDENT_EVIDENCE_INVALID_OR_UNAVAILABLE',
    'ORIGINAL305_WORDPRESS_READBACK_UNAVAILABLE',
}

class ObserverDenied(RuntimeError):pass

def require(ok,code):
    if not ok:raise ObserverDenied(code)

def sha(path:Path):return hashlib.sha256(path.read_bytes()).hexdigest()

def observe(*,clock=time.time,triage_reader=None)->dict:
    require(os.geteuid()==0,'OBSERVER_ROOT_REQUIRED')
    require(TRIAGE.is_file() and not TRIAGE.is_symlink() and
            TRIAGE.stat().st_uid==0 and sha(TRIAGE)==TRIAGE_SHA,
            'READONLY_TRIAGE_SOURCE_PIN_MISMATCH')
    if triage_reader is None:
        spec=importlib.util.spec_from_file_location('original305_readonly_host_triage',TRIAGE)
        require(spec is not None and spec.loader is not None,'READONLY_TRIAGE_IMPORT_FAILED')
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        triage_reader=module.check
    before=sha(HOLD) if HOLD.is_file() else None
    read=triage_reader()
    require(isinstance(read,dict) and read.get('signer_invoked') is False and
            read.get('wordpress_changed') is False and read.get('hold_cleared') is False and
            read.get('renewal_enabled_by_this_check') is False and
            read.get('host_release_authorized') is False,
            'READONLY_TRIAGE_SAFETY_CONTRACT_INVALID')
    require(read.get('state') in STATES and
            type(read.get('legacy_hold_present')) is bool and
            read.get('timer_state') in ('enabled','disabled') and
            type(read.get('signed_readiness_admitted')) is bool,
            'READONLY_TRIAGE_OUTPUT_INVALID')
    require((sha(HOLD) if HOLD.is_file() else None)==before,
            'ORIGINAL305_HOLD_CHANGED_DURING_OBSERVATION')
    match=REGISTRY.is_file() and sha(REGISTRY)==REGISTRY_SHA
    require(match==read.get('original_v8_registry_exact'),
            'REGISTRY_TRIAGE_FACT_CONFLICT')
    now=int(clock())
    return {
       'contract':CONTRACT,
       'observation_epoch':now,'expires_epoch':now+900,
       'state':read['state'],
       'legacy_hold_present':read['legacy_hold_present'],
       'timer_enabled':read['timer_state']=='enabled',
       'original_v8_registry_exact':match,
       'signed_readiness_admitted':read['signed_readiness_admitted'],
       'signer_invoked':False,'host_release_authorized':False,
       'approval_created':False,'incident_closed':False,
    }

def publish(snapshot:dict,*,runner=subprocess.run)->dict:
    # Static, hard-coded option name and UTF-8 base64 payload. No uploaded
    # scripts, root key files, shell evaluation, ticket creation or signatures.
    require(isinstance(snapshot,dict) and snapshot.get('contract')==CONTRACT,
            'OBSERVATION_CONTRACT_INVALID')
    encoded=base64.b64encode(json.dumps(snapshot,sort_keys=True,
        separators=(',',':'),ensure_ascii=False).encode('utf-8')).decode('ascii')
    require(re.fullmatch('[a-zA-Z0-9+/=]{20,5000}',encoded) is not None,
            'OBSERVATION_FORMAT_INVALID')
    php="""$payload=json_decode(base64_decode('__PAYLOAD__',true),true);
$key='mad4b_original305_runtime_observation_v1';
$ok=is_array($payload)&&($payload['contract']??'')==='mad4b.original305-diagnostic-snapshot.v1'&&
($payload['signer_invoked']??null)===false&&($payload['host_release_authorized']??null)===false&&
($payload['approval_created']??null)===false&&($payload['incident_closed']??null)===false&&
isset($payload['state'],$payload['observation_epoch'],$payload['expires_epoch'])&&
is_int($payload['observation_epoch'])&&is_int($payload['expires_epoch'])&&
$payload['expires_epoch']-$payload['observation_epoch']===900;
if(!$ok){echo wp_json_encode(['state'=>'OBSERVER_REJECTED_PAYLOAD']);return;}
$before=get_option($key,false);
if($before!==$payload)update_option($key,$payload,false);
$stored=get_option($key,false);
echo wp_json_encode(['state'=>($stored===$payload?'OBSERVER_TELEMETRY_STORED':'OBSERVER_READBACK_MISMATCH'),
 'contract'=>is_array($stored)?($stored['contract']??null):null,
 'observation_epoch'=>is_array($stored)?($stored['observation_epoch']??null):null,
 'host_release_authorized'=>false]);""".replace('__PAYLOAD__',encoded)
    sys.path.insert(0,'/opt/mad4b-company-transport')
    from central_wpcli_runtime import central_wp
    p=runner(central_wp()+['eval',php],capture_output=True,text=True,
             timeout=62,check=False)
    require(p.returncode==0,'WORDPRESS_TELEMETRY_WRITE_UNAVAILABLE')
    try:rows=[json.loads(x) for x in p.stdout.splitlines() if x.startswith('{')]
    except ValueError:rows=[]
    require(len(rows)==1 and isinstance(rows[0],dict) and
            rows[0].get('state')=='OBSERVER_TELEMETRY_STORED' and
            rows[0].get('contract')==CONTRACT and
            rows[0].get('observation_epoch')==snapshot['observation_epoch'] and
            rows[0].get('host_release_authorized') is False,
            'WORDPRESS_TELEMETRY_READBACK_MISMATCH')
    return {'state':'ORIGINAL305_OBSERVER_PUBLISHED','observation_epoch':snapshot['observation_epoch'],
            'readiness_state':snapshot['state'],'host_release_authorized':False,
            'original305_signed_refresh_called':False,'incident_hold_changed':False}

if __name__=='__main__':
    p=argparse.ArgumentParser();m=p.add_mutually_exclusive_group(required=True)
    m.add_argument('--check',action='store_true');m.add_argument('--publish',action='store_true')
    a=p.parse_args()
    try:
        observation=observe()
        result=publish(observation) if a.publish else observation
        print(json.dumps(result,sort_keys=True))
    except (ObserverDenied,OSError,ValueError,TypeError,subprocess.SubprocessError) as e:
        print(json.dumps({'state':'ORIGINAL305_OBSERVER_FAILED_CLOSED',
             'reason_code':str(e) if isinstance(e,ObserverDenied) else 'OBSERVER_DEPENDENCY_UNAVAILABLE',
             'host_release_authorized':False,'incident_hold_changed':False},sort_keys=True))
        raise SystemExit(4) from None
