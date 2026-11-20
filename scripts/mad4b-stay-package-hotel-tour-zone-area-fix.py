#!/usr/bin/env python3
"""Bounded source reconciliation patch for the exact live Wayfatours assignment provider.

Source package in this PR is older than the 448-line live WP module. This
guarded transformation documents only the reviewed single-hunk production fix.
A full cross-company release must first reconcile the native source file.
"""
import argparse
import hashlib
import os
from pathlib import Path
import stat
import tempfile

BEFORE_SHA = '6b9128ef3ed9a540537130554a4c9b344c45bcd60fc4b0a3a16531ef66e725b8'
AFTER_SHA = 'edfdfa4b7ca5ad0c3d2b8652a103e05f58d3fffdf84807192f64fa78a71a2bf8'
BEFORE = """ // An explicitly assigned city narrows its own branch; never broaden that city to its state.
 foreach($locations as $id=>$loc)foreach($locations as $other=>$child){
  if($id!==$other&&in_array($id,array_column($child['ancestry'],'id'),true)){unset($locations[$id]);break;}
 }"""
AFTER = """ // Tour destinations combine sightseeing areas and possible stay regions.
 // An explicitly selected governorate remains a stay-candidate scope even
 // when a more specific zone_area (e.g. an attraction visit) is also tagged.
 // Property scopes retain their original narrower matching behavior.
 $isTour=get_post_type($post)==='tours-and-activities';
 foreach($locations as $id=>$loc)foreach($locations as $other=>$child){
  if($id!==$other&&in_array($id,array_column($child['ancestry'],'id'),true)){
   if($isTour&&$loc['location_type']==='state_governorate'
     &&$child['location_type']==='zone_area')continue;
   unset($locations[$id]);break;
  }
 }"""
def sha(content):
    return hashlib.sha256(content).hexdigest()
def patched(source):
    assert sha(source)==BEFORE_SHA, 'unknown original runtime, refuse'
    assert source.count(BEFORE.encode())==1, 'unknown source snippet, refuse'
    changed=source.replace(BEFORE.encode(),AFTER.encode())
    assert sha(changed)==AFTER_SHA, 'unexpected output hash, refuse'
    return changed

def atomic(target, data):
    st=target.stat()
    if target.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise ValueError('unsafe target')
    with tempfile.NamedTemporaryFile(dir=target.parent,prefix='.mad4b-stay-',suffix='.php',delete=False) as fh:
        temp=Path(fh.name)
        fh.write(data)
        fh.flush()
        os.fsync(fh.fileno())
    try:
        os.chmod(temp,stat.S_IMODE(st.st_mode))
        os.chown(temp,st.st_uid,st.st_gid)
        os.replace(temp,target)
    finally:
        temp.unlink(missing_ok=True)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode',choices=['plan','apply','verify','rollback'])
    parser.add_argument('--target',required=True,type=Path)
    parser.add_argument('--backup',type=Path)
    parser.add_argument('--confirm',default='')
    args=parser.parse_args()
    target=args.target
    if (target.name!='accommodation-assignment.php' or not target.is_file()
        or target.is_symlink()
        or 'wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins/shared-product-core/pricing'
        not in str(target.resolve())):
        raise SystemExit('site/file identity not authorized')
    old=target.read_bytes()
    current=sha(old)
    if args.mode=='plan':
        if current not in (BEFORE_SHA,AFTER_SHA):raise SystemExit('unknown runtime')
        if current==BEFORE_SHA:patched(old)
        print({'mode':'plan','current_sha256':current,'expected_sha256':AFTER_SHA,'rate_writes':0})
    elif args.mode=='verify':
        if current!=AFTER_SHA or AFTER.encode() not in old:
            raise SystemExit('hotfix absent or changed')
        print({'mode':'verify','sha256':current,'match':True})
    elif args.mode=='apply':
        if args.confirm!='HOTFIX_EXACT_SOURCE_SHA' or not args.backup or args.backup.exists():
            raise SystemExit('explicit approval and new backup path required')
        new=patched(old)
        args.backup.write_bytes(old)
        if sha(args.backup.read_bytes())!=BEFORE_SHA:raise SystemExit('backup mismatch')
        atomic(target,new)
        if sha(target.read_bytes())!=AFTER_SHA:raise SystemExit('write mismatch')
        print({'mode':'apply','new_sha256':AFTER_SHA})
    else:
        if args.confirm!='ROLLBACK_EXACT_SOURCE_SHA' or not args.backup or current!=AFTER_SHA:
            raise SystemExit('rollback not authorized or target changed')
        before=args.backup.read_bytes()
        if sha(before)!=BEFORE_SHA:raise SystemExit('wrong backup')
        atomic(target,before)
        if sha(target.read_bytes())!=BEFORE_SHA:raise SystemExit('rollback mismatch')
        print({'mode':'rollback','new_sha256':BEFORE_SHA})
if __name__=='__main__': main()
