#!/usr/bin/env python3
"""Render a fail-closed per-site OS Cron entry; never installs it."""
import argparse,json,re,stat
from pathlib import Path

def render(site_dir,offset):
    p=Path(site_dir)
    if not p.is_absolute() or p.is_symlink() or not p.is_dir():
        raise ValueError("site_directory_not_trusted")
    s=p.stat()
    if s.st_uid!=0 or s.st_mode&0o022:
        raise ValueError("site_directory_not_root_owned")
    worker=p/"worker.py"
    profile=p/"site-profile.json"
    if (worker.is_symlink() or profile.is_symlink()
        or not worker.is_file() or not profile.is_file()
        or profile.stat().st_uid!=0 or profile.stat().st_mode&0o077):
        raise ValueError("site_worker_or_profile_unsafe")
    record=json.loads(profile.read_text())
    if record.get("contract")!="mad4b.css.site-owned-cron.profile.v1":
        raise ValueError("invalid_profile_contract")
    site=record.get("site_id")
    if not isinstance(site,str) or not re.fullmatch(r"[a-z][a-z0-9_-]{1,63}",site):
        raise ValueError("invalid_site_alias")
    if not isinstance(offset,int) or not 0<=offset<=4:
        raise ValueError("unsupported_cron_offset")
    minute="*/5" if offset==0 else f"{offset}-59/5"
    path=str(worker)
    log=f"/var/log/mad4b-css-site-{site}.log"
    return (f"{minute} * * * * root /usr/bin/flock -n "
            f"/run/lock/mad4b-css-site-{site}.lock /usr/bin/flock -n "
            f"/run/lock/mad4b-css-wpcron-worker.lock /usr/bin/timeout -k 10s 1500s "
            f"/usr/bin/nice -n 18 /usr/bin/ionice -c 3 /usr/bin/python3 "
            f"{path} >> {log} 2>&1")

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("site_dir")
    parser.add_argument("--offset",type=int,default=0)
    parser.add_argument("--active-cron-file")
    args=parser.parse_args()
    line=render(args.site_dir,args.offset)
    if args.active_cron_file:
        existing=Path(args.active_cron_file).read_text()
        if line not in existing.splitlines():
            raise SystemExit("SITE_CRON_LINE_NOT_MATCHED")
        print("SITE_OWNED_CRON_LINE_VERIFIED")
    else:
        print("SHELL=/bin/sh")
        print("PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin")
        print(line)
