#!/usr/bin/env python3
"""Verify/fix owner-console JS traversal rights after a WordPress deployment.

The PHP worker needs to read the reviewed JS source inside mu-plugins, but
the directory must not be opened to the general public. This is an explicit
deployment step only, never called by a page load, cron or a web request.

Usage:
 python3 -B owner_admin_asset_permissions.py --wp-root /srv/wp
 python3 -B owner_admin_asset_permissions.py --wp-root /srv/wp --apply
No Host Authority grant, user, key, WordPress option, registry or Job is touched.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import stat

PHP_REL="wp-content/mu-plugins/393-mad4b-host-authority-owner-console.php"
JS_REL="wp-content/mu-plugins/mad4b-host-authority-owner-ui/host-authority.js"
DIR_REL="wp-content/mu-plugins/mad4b-host-authority-owner-ui"
MU_REL="wp-content/mu-plugins"
EXPECTED_PHP="adeb530481626de99627be44aabfee51b5a64a61b95e25075a8a5c25bfd6b1d0"
EXPECTED_JS="1f4acc13b5c4afd5c55b6ac64afc2d2ba3d6f707d085a672ceb7d1ac25ada388"

class AdmissionError(ValueError):
    pass

def require(ok:bool,reason:str)->None:
    if not ok:raise AdmissionError(reason)

def checksum(path:Path)->str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def inspect(root:Path)->dict:
    root=Path(root)
    mu=root/MU_REL
    folder=root/DIR_REL
    php=root/PHP_REL
    js=root/JS_REL
    for path in (root,root/"wp-content",mu,folder,php,js):
        info=path.lstat()  # symlinks fail closed
        require(not stat.S_ISLNK(info.st_mode),
                "UNTRUSTED_SYMLINK_IN_DEPLOYMENT_PATH")
        require(stat.S_ISDIR(info.st_mode) if path in (root,root/"wp-content",mu,folder)
                else stat.S_ISREG(info.st_mode),
                "EXPECTED_DIRECTORY_OR_FILE_NOT_FOUND")
    fm=folder.lstat()
    mm=mu.lstat()
    jstat=js.lstat()
    pstat=php.lstat()
    require(fm.st_uid==0 and mm.st_uid==0,
            "ROOT_OWNED_DEPLOYMENT_DIRECTORY_REQUIRED")
    require(mm.st_gid>0 and stat.S_IMODE(mm.st_mode)&0o070,
            "TRUSTED_PHP_WORKER_GROUP_MISSING")
    require(jstat.st_nlink==1 and pstat.st_nlink==1
            and jstat.st_uid==0 and pstat.st_uid==0,
            "ROOT_OWNED_REVIEWED_SOURCE_REQUIRED")
    require(checksum(js)==EXPECTED_JS and checksum(php)==EXPECTED_PHP,
            "EXACT_REVIEWED_UI_SOURCE_HASH_DRIFT")
    state=(fm.st_uid,fm.st_gid,stat.S_IMODE(fm.st_mode))
    want=(0,mm.st_gid,0o750)
    return {
        "state":"READABLE_BY_PHP_GROUP" if state==want else "REPAIR_REQUIRED",
        "relative_directory":DIR_REL,
        "owner_uid":state[0],"group_gid":state[1],
        "directory_mode":f"{state[2]:04o}",
        "target_group_gid":want[1],
        "target_mode":"0750",
        "reviewed_source_sha256":{"php":EXPECTED_PHP,"js":EXPECTED_JS},
        "host_authority_issued":False
    }

def apply(root:Path)->dict:
    require(os.geteuid()==0,"ROOT_ONLY_DEPLOYMENT_REPAIR")
    first=inspect(root)
    if first["state"]=="READABLE_BY_PHP_GROUP":return first
    folder=Path(root)/DIR_REL
    old=folder.lstat()
    try:
        os.chown(folder,0,first["target_group_gid"])
        os.chmod(folder,0o750)
        after=inspect(root)
        require(after["state"]=="READABLE_BY_PHP_GROUP",
                "DEPLOYMENT_ASSET_PERMISSION_READBACK_FAILED")
        return after
    except BaseException:
        os.chown(folder,old.st_uid,old.st_gid)
        os.chmod(folder,stat.S_IMODE(old.st_mode))
        raise

def main()->int:
    p=argparse.ArgumentParser()
    p.add_argument("--wp-root",required=True,type=Path)
    p.add_argument("--apply",action="store_true")
    args=p.parse_args()
    try:
        evidence=apply(args.wp_root) if args.apply else inspect(args.wp_root)
    except (AdmissionError,OSError) as exc:
        print(json.dumps({"state":"HOLD","reason":str(exc),
                          "host_authority_issued":False},sort_keys=True))
        return 2
    print(json.dumps(evidence,sort_keys=True))
    return 0 if evidence["state"]=="READABLE_BY_PHP_GROUP" else 1

if __name__=="__main__":
    raise SystemExit(main())
