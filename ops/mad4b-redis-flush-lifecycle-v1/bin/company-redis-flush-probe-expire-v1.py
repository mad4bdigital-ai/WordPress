#!/usr/bin/env python3
"""Restore exact original LiteSpeed source after bounded caller attribution."""
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import stat
import tempfile

ROOT=Path('/opt/mad4b-company-provisioning')
AUDIT=ROOT/'reports/redis-flush-root-cause-20261108'
CONFIG=AUDIT/'temporary-probe-contract.json'
def h(data):return hashlib.sha256(data).hexdigest()
def restore_one(site,spec):
 target=Path(spec['target']);backup=Path(spec['backup'])
 if not target.is_file() or not backup.is_file() or target.is_symlink():
  return {'state':'MISSING_OR_UNSAFE_SOURCE'}
 current=target.read_bytes();original=backup.read_bytes()
 if h(original)!=spec['original_sha256']:return {'state':'BACKUP_HASH_CHANGED'}
 if h(current)==spec['original_sha256']:return {'state':'ALREADY_RESTORED'}
 if h(current)!=spec['instrumented_sha256']:return {'state':'FILE_CHANGED_AFTER_DIAGNOSTIC_DO_NOT_OVERWRITE'}
 st=target.stat()
 fd,temp=tempfile.mkstemp(prefix='.mad4b-redis-flush-trace-expire-',dir=target.parent)
 try:
  with os.fdopen(fd,'wb') as f:
   f.write(original);f.flush();os.fsync(f.fileno())
  os.chmod(temp,stat.S_IMODE(st.st_mode))
  if os.geteuid()==0:os.chown(temp,st.st_uid,st.st_gid)
  os.replace(temp,target)
 finally:
  if os.path.exists(temp):os.unlink(temp)
 return {'state':'RESTORED','sha256':h(original)}
def main():
 contract=json.loads(CONFIG.read_text())
 if contract['contract']!='mad4b.redis-flush-temporary-probe.v1':
  raise ValueError('BAD_CONTRACT')
 state={name:restore_one(name,spec) for name,spec in contract['sites'].items()}
 receipt={'contract':'mad4b.redis-flush-probe-expiry.v1','utc':dt.datetime.now(dt.timezone.utc).isoformat(),'sites':state}
 (AUDIT/'probe-expiry-receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
 print(json.dumps(receipt))
 return 0 if all(x['state'] in ('RESTORED','ALREADY_RESTORED') for x in state.values()) else 2
if __name__=='__main__':
 import sys
 sys.exit(main())
