#!/usr/bin/env python3
"""MAD4B signed, sanitized VPS Broker journal reconciliation to Central WordPress.
No shell execution, no credentials included, no mutation to company sites.
"""
import argparse, base64, datetime, hashlib, json, os, pathlib, secrets, subprocess, time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT=pathlib.Path('/opt/mad4b-company-transport/broker-v1')
SOURCE=ROOT/'broker-events.jsonl'
KEY=ROOT/'host-ed25519-private.pem'
HOST='srv2030908'
CONTRACT='mad4b.vps-broker.journal.v1'
ALLOWED={'HEARTBEAT_VERIFIED','CLAIM_ATTEMPTED','CLAIM_VERIFIED_NOT_EXECUTED',
         'CLAIM_RESULT_UNCERTAIN','PENDING_NOT_ELIGIBLE'}
WP=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php',
    '/usr/local/bin/wp','--path=/var/www/html','--skip-themes','--user=1',
    'mad4b','broker-journal','sync']

def prepare():
    if os.geteuid()!=0:raise SystemExit('root_vps_broker_required')
    if not KEY.is_file() or not SOURCE.is_file():raise SystemExit('broker_key_or_journal_missing')
    events=[]
    for line in SOURCE.read_text(encoding='utf-8').splitlines()[-100:]:
        try: row=json.loads(line)
        except json.JSONDecodeError: continue
        if row.get('event') not in ALLOWED:continue
        item={'event':row['event'],'at':str(row.get('at',''))[:48],
              'job_id':str(row.get('job_id','')),'company':str(row.get('company',''))}
        if len(item['job_id']) not in (0,32):continue
        events.append(item)
    events=events[-30:]
    payload={'contract':CONTRACT,'host_id':HOST,'events':events}
    raw=json.dumps(payload,separators=(',',':'),sort_keys=True).encode()
    issued=int(time.time()); expires=issued+45; nonce=secrets.token_hex(16)
    msg='\n'.join([CONTRACT,'journal',HOST,nonce,str(issued),str(expires),
                   hashlib.sha256(raw).hexdigest()]).encode()
    key=serialization.load_pem_private_key(KEY.read_bytes(),password=None)
    if not isinstance(key,Ed25519PrivateKey):raise RuntimeError('unexpected_signer')
    signature=base64.b64encode(key.sign(msg)).decode()
    return events, [
      '--host='+HOST,'--nonce='+nonce,'--issued='+str(issued),
      '--expires='+str(expires),
      '--payload='+base64.b64encode(raw).decode(),
      '--signature='+signature]

def main():
    p=argparse.ArgumentParser();p.add_argument('action',choices=['sync','preview'])
    a=p.parse_args();events,args=prepare()
    if a.action=='preview':
        print(json.dumps({'contract':CONTRACT,'events':len(events),'execution_performed':False}))
        return
    proc=subprocess.run(WP+args,capture_output=True,text=True,timeout=50,check=False)
    if proc.returncode!=0:
        print(json.dumps({'contract':CONTRACT,'state':'SYNC_FAILED',
                          'wp_cli_exit':proc.returncode,'execution_performed':False}))
        raise SystemExit(1)
    rec=json.loads(proc.stdout.splitlines()[-1])
    if rec.get('contract')!=CONTRACT or rec.get('signature_verified') is not True or rec.get('count')!=len(events):
        raise SystemExit('journal_readback_mismatch')
    print(json.dumps({'contract':CONTRACT,'state':'HOST_JOURNAL_SYNC_VERIFIED',
                      'event_count':len(events),'execution_performed':False}))
if __name__=='__main__': main()
