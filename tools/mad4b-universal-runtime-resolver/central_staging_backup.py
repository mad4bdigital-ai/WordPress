#!/usr/bin/env python3
"""Central Staging backup with two original, exact-source execution paths.

Root Host identity & current site identity required. New snapshot only.
Never restores, replaces plugin source, alters Production or grants writes.
"""
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import uuid

ROOT=Path("/opt/mad4b-company-transport")
POLICY=ROOT/"universal-runtime-v1/policy.json"
PROBE=ROOT/"universal-runtime-v1/central_execution_route.py"
NATIVE=ROOT/"vps-native-operator.py"
DEST=Path("/opt/staging/mad4b-managed-backups/central-staging")
PHP="etg-commerce-staging-php"
DB="etg-commerce-staging-db"
PLUGIN="mad4b-site-control-plane"
TABLES=("wp_mad4b_scp_agents","wp_mad4b_scp_agent_subjects",
        "wp_mad4b_scp_agent_grants","wp_mad4b_scp_approval_tickets",
        "wp_mad4b_scp_agent_budgets","wp_mad4b_scp_audit_heads","wp_options")

def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def run(args,**kwargs):
    return subprocess.run(args,capture_output=True,text=True,timeout=30,check=False,**kwargs)

def admission():
    native=load("mad4b_backup_native_operator",NATIVE)
    native.root_session()
    central=load("mad4b_backup_live_central",PROBE)
    policy=json.loads(POLICY.read_text())
    state,reason,proof=central.check(policy["scopes"]["tours-central:staging"])
    if state!="verified" or proof.get("fresh_site_readback") is not True:
        raise RuntimeError("EXACT_CENTRAL_HOST_AND_SITE_NOT_READY:"+reason)
    return proof

def tools():
    wp=run(["docker","exec","-u","www-data",PHP,"sh","-c","command -v mysqldump"])
    db=run(["docker","exec",DB,"sh","-c","command -v mariadb-dump"])
    tar=run(["docker","exec",PHP,"sh","-c","command -v tar"])
    return {"wp_mysqldump":wp.returncode==0,
            "db_mariadb_dump":db.returncode==0,
            "php_tar":tar.returncode==0,
            "docker_cp":True}

def hashfile(path):
    h=hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda:stream.read(1024*1024),b""):h.update(chunk)
    return h.hexdigest()

def export_sql(destination,available):
    # Authenticated WP CLI first; if the PHP runtime lacks mysqldump, use the
    # enrolled MariaDB server's local dump binary. Do not export credentials.
    if available["wp_mysqldump"]:
        cmd=["docker","exec","-u","www-data",PHP,"wp","db","export","-",
             "--tables="+",".join(TABLES),"--single-transaction",
             "--path=/var/www/html","--skip-plugins","--skip-themes"]
        lane="wordpress_wpcli_mysqldump"
    elif available["db_mariadb_dump"]:
        cmd=["docker","exec",DB,"sh","-c",
             'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" '
             "--single-transaction --quick --skip-lock-tables "
             "etg_commerce_staging "+" ".join(TABLES)]
        lane="native_mariadb_dump"
    else:
        raise RuntimeError("BOTH_DATABASE_DUMP_ROUTES_UNAVAILABLE")
    with destination.open("xb") as stream:
        os.fchmod(stream.fileno(),0o600)
        p=subprocess.run(cmd,stdout=stream,stderr=subprocess.PIPE,
                         timeout=180,check=False)
    if p.returncode:
        raise RuntimeError("DATABASE_DUMP_LANE_FAILED:"+lane)
    payload=destination.read_bytes()
    for table in TABLES:
        if ("CREATE TABLE `"+table+"`").encode() not in payload:
            raise RuntimeError("DUMP_READBACK_TABLE_MISSING:"+table)
    if payload.count(b"CREATE TABLE")!=len(TABLES):
        raise RuntimeError("DUMP_TABLE_COUNT_UNEXPECTED")
    return lane

def snapshot_plugin(folder,available):
    source=PHP+":/var/www/html/wp-content/plugins/"+PLUGIN
    result=subprocess.run(["docker","cp",source,str(folder)],capture_output=True,
                          timeout=90,check=False)
    if result.returncode==0:
        path=folder/PLUGIN
        if not path.is_dir():raise RuntimeError("DOCKER_CP_DIRECTORY_MISSING")
        return path,"docker_cp"
    if not available["php_tar"]:
        raise RuntimeError("BOTH_PLUGIN_SNAPSHOT_ROUTES_UNAVAILABLE")
    archive=folder/"plugin-files.tar"
    with archive.open("xb") as stream:
        os.fchmod(stream.fileno(),0o600)
        p=subprocess.run(["docker","exec",PHP,"tar","-C",
                          "/var/www/html/wp-content/plugins","-cf","-",PLUGIN],
                         stdout=stream,stderr=subprocess.PIPE,timeout=90,check=False)
    if p.returncode:raise RuntimeError("PLUGIN_TAR_BACKUP_FAILED")
    import tarfile
    with tarfile.open(archive) as tf:
        names=tf.getnames()
        if len(names)<900 or not all(
                x==PLUGIN or x.startswith(PLUGIN+"/") for x in names):
            raise RuntimeError("PLUGIN_TAR_CONTENT_INVALID")
    return archive,"docker_exec_tar"

def backup():
    proof=admission()
    available=tools()
    if not available["db_mariadb_dump"] and not available["wp_mysqldump"]:
        raise RuntimeError("NO_AVAILABLE_DATABASE_BACKUP_ROUTE")
    DEST.mkdir(parents=True,exist_ok=True)
    os.chmod(DEST,0o700)
    ident=datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")+"-"+uuid.uuid4().hex[:8]
    folder=DEST/ident
    folder.mkdir(mode=0o700)
    plugin,lane=snapshot_plugin(folder,available)
    sql=folder/"central-authority.sql"
    database_lane=export_sql(sql,available)
    if plugin.is_dir():
        files=sorted(x for x in plugin.rglob("*") if x.is_file())
        if len(files)<900:raise RuntimeError("PLUGIN_BACKUP_COUNT_TOO_LOW")
        manifest=hashlib.sha256("".join(str(x.relative_to(plugin))+hashfile(x) for x in files).encode()).hexdigest()
        plugin_count=len(files)
    else:
        manifest=hashfile(plugin)
        plugin_count=None
    if admission().get("site_uuid")!=proof["site_uuid"]:
        raise RuntimeError("SOURCE_SITE_CHANGED_DURING_BACKUP")
    receipt={"contract":"mad4b.central-staging-dual-backup.v1","state":"VERIFIED",
        "site_uuid":proof["site_uuid"],"environment":"staging",
        "backup_directory":str(folder),"plugin_lane":lane,
        "plugin_files":plugin_count,"plugin_sha256":manifest,
        "database_lane":database_lane,"table_count":len(TABLES),
        "sql_bytes":sql.stat().st_size,"sql_sha256":hashfile(sql),
        "backup_only":True,"wordpress_data_mutated":False,"production_mutation":False}
    manifest_file=folder/"receipt.json"
    with manifest_file.open("x") as stream:
        os.fchmod(stream.fileno(),0o600)
        json.dump(receipt,stream,sort_keys=True,indent=2)
    return receipt

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ("plan","backup"):
        raise RuntimeError("ONLY_PLAN_OR_BACKUP_ALLOWED")
    proof=admission()
    if sys.argv[1]=="plan":
        print(json.dumps({"contract":"mad4b.central-staging-dual-backup.v1",
          "state":"ADMITTED","source_site":proof["site_uuid"],
          "routes":tools(),"mutation_scope":"new_backup_files_only"},sort_keys=True))
    else:
        print(json.dumps(backup(),sort_keys=True))

if __name__=="__main__":
    try:main()
    except (RuntimeError,ValueError,OSError,KeyError,TypeError,
            subprocess.TimeoutExpired) as exc:
        print(json.dumps({"contract":"mad4b.central-staging-dual-backup.v1",
          "state":"DENIED","reason":str(exc),"production_mutation":False}))
        sys.exit(75)
