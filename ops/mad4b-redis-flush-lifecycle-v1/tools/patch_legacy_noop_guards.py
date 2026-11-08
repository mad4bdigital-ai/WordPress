#!/usr/bin/env python3
"""Exact-match patcher: avoid full Redis flush after unchanged older migrations."""
import argparse
import pathlib
import hashlib
import json
import os
import tempfile
RULES={
 'wayfa-jet-config-autoload.php':(
  "    wp_cache_flush();\n    $summary['state']",
  "    if ($activated>0 || $newlinks>0) wp_cache_flush();\n    $summary['state']"),
 'wayfa-design-activate.php':(
  "  wp_cache_flush();\n  $summary['state']",
  "  if ($updated>0) wp_cache_flush();\n  $summary['state']"),
 'wayfa-wpml-resolve.php':(
  "    wp_cache_flush();\n    echo wp_json_encode(array('state'",
  "    if ($inserted>0) wp_cache_flush();\n    echo wp_json_encode(array('state'")}
def main():
 ap=argparse.ArgumentParser()
 ap.add_argument('--dir',required=True,type=pathlib.Path)
 ap.add_argument('--apply',action='store_true')
 ap.add_argument('--backup',type=pathlib.Path)
 args=ap.parse_args()
 if args.apply and not args.backup:ap.error('explicit backup path required for apply')
 plans={}
 for name,(old,new) in RULES.items():
  f=args.dir/name
  if not f.is_file() or f.is_symlink():raise RuntimeError('file missing or symlink: '+name)
  raw=f.read_bytes();s=raw.decode('utf-8')
  if s.count(new)==1 and s.count(old)==0:change=False;revised=raw
  elif s.count(old)==1 and s.count(new)==0:change=True;revised=s.replace(old,new,1).encode()
  else:raise RuntimeError('unknown or duplicate anchor: '+name)
  plans[name]={'file':f,'before':raw,'after':revised,'change':change}
 if args.apply:
  args.backup.mkdir(parents=True,exist_ok=False)
  for name,record in plans.items():
   (args.backup/name).write_bytes(record['before'])
  for name,record in plans.items():
   if not record['change']:continue
   f=record['file']
   if f.read_bytes()!=record['before']:raise RuntimeError('concurrent modified: '+name)
   fd,temp=tempfile.mkstemp(dir=f.parent,prefix='.mad4b-guard-')
   with os.fdopen(fd,'wb') as stream:
    stream.write(record['after']);stream.flush();os.fsync(stream.fileno())
   os.chmod(temp,0o644);os.replace(temp,f)
 print(json.dumps({'mode':'apply' if args.apply else 'dry-run',
  'files':{n:{'requires_change':r['change'],
    'sha_before':hashlib.sha256(r['before']).hexdigest(),
    'sha_after':hashlib.sha256(r['after']).hexdigest()} for n,r in plans.items()}}))
if __name__=='__main__':main()
