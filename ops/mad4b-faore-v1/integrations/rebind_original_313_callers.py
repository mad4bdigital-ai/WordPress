#!/usr/bin/env python3
"""One-time root-backed migration of original 313 Central WPCLI prefixes only."""
import json
import os
import re
from pathlib import Path
import subprocess
import tempfile

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
BACKUP=Path("/opt/mad4b-company-transport/native-operator-v1/backups/faore-dynamic-actor-20261105/native-313")
SCRIPTS=("claimed-job-worker.py","original-wpcli-executor.py",
         "independent-readback.py")
SOURCE_PATTERN=re.compile(
    r"^WP=\['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php',\s*"
    r"'/usr/local/bin/wp','--path=/var/www/html','--skip-themes'\]",re.M)


def patch(source: str) -> str:
    import_line="from central_wpcli_runtime import central_wp"
    import_with_path="sys.path.insert(0, '/opt/mad4b-company-transport')\n"+import_line
    if import_line in source:
        if "WP=central_wp" not in source:
            raise RuntimeError("ORIGINAL_313_PARTIAL_MIGRATION")
        if import_with_path in source:
            return source
        return source.replace(import_line,import_with_path,1)
    replaced,count=SOURCE_PATTERN.subn(
        ("sys.path.insert(0, '/opt/mad4b-company-transport')\n"
         "from central_wpcli_runtime import central_wp\nWP=central_wp"),source)
    if count!=1 or "WP+args" not in replaced and "WP+[" not in replaced:
        raise RuntimeError("ORIGINAL_313_STAGING_PREFIX_UNEXPECTED")
    return replaced.replace("WP+args","WP()+args").replace("WP+[","WP()+[")


def apply():
    if os.geteuid()!=0:
        raise RuntimeError("ORIGINAL_313_ROOT_REQUIRED")
    BACKUP.mkdir(mode=0o700,parents=True,exist_ok=True)
    BACKUP.chmod(0o700)
    for name in SCRIPTS:
        path=ROOT/name
        st=path.lstat()
        if path.is_symlink() or st.st_uid!=0 or st.st_mode & 0o022:
            raise RuntimeError("ORIGINAL_313_FILE_UNSAFE")
        text=path.read_text()
        modified=patch(text)
        if modified==text:
            continue
        backup=BACKUP/(name+".original")
        if backup.exists():
            if patch(backup.read_text()) != modified:
                raise RuntimeError("ORIGINAL_313_ROLLBACK_MISMATCH")
        else:
            fd=os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
            with os.fdopen(fd,"wb") as dest:
                dest.write(text.encode())
        fd,tmp=tempfile.mkstemp(prefix=".faore313-",dir=ROOT)
        try:
            with os.fdopen(fd,"w") as dest:
                dest.write(modified)
            os.chmod(tmp,st.st_mode & 0o777)
            p=subprocess.run(["/usr/bin/python3","-m","py_compile",tmp],
                             capture_output=True,check=False,timeout=10)
            if p.returncode:
                raise RuntimeError("ORIGINAL_313_COMPILE_FAILED")
            os.replace(tmp,path)
        finally:
            if os.path.exists(tmp):
                os.unlink(tmp)
    return {"state":"ORIGINAL_313_CENTRAL_RUNTIME_REBOUND",
            "files":list(SCRIPTS),"new_jobs":0,"grants":0,
            "signatures_modified":False,"native_execution_modified":False}


if __name__=="__main__":
    print(json.dumps(apply(),sort_keys=True))
