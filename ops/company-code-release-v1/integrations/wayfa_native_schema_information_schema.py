#!/usr/bin/env python3
"""Strictly read-only current Wayfatours tenant schema from live MariaDB.

This does not create or modify tables and does not confer migration authority.
It replaces using a stale legacy schema snapshot as current proof.
"""
from __future__ import annotations
import datetime as dt,json,os,re,stat,subprocess,tempfile
from pathlib import Path

DB_CONTAINER='db-guxhp9y6vgh7oouuw8plxrlr'
PREFIX='wp_mad4b_tenant_'
TABLES={'approvals':PREFIX+'approvals',
        'maps':PREFIX+'content_maps',
        'overrides':PREFIX+'field_overrides',
        'cache':PREFIX+'hub_cache'}
REPORT=Path('/opt/mad4b-company-provisioning/reports/company-execution-closeout-20261110/wayfa-native-schema-current-sql-readback.json')
SQL=('SELECT TABLE_NAME,ENGINE,TABLE_COLLATION '
     'FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() '
     'AND LEFT(TABLE_NAME,16)=CHAR(119,112,95,109,97,100,52,98,95,116,101,110,97,110,116,95) '
     'ORDER BY TABLE_NAME')
QUERY='MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -u root -N "$MARIADB_DATABASE" -e "'+SQL+'"'

class Denied(RuntimeError):pass

def require(ok,reason):
    if not ok:raise Denied(reason)

def interpret(output:str)->dict:
    require(isinstance(output,str) and len(output)<10000,'SCHEMA_QUERY_OUTPUT_UNTRUSTED')
    found={}
    for line in output.splitlines():
        fields=line.split('\t')
        require(len(fields)==3,'SCHEMA_QUERY_ROW_INVALID')
        name,engine,collation=fields
        require(name in TABLES.values() and name not in found and
                bool(re.fullmatch(r'[A-Za-z0-9_]{1,64}',engine)) and
                bool(re.fullmatch(r'[A-Za-z0-9_]{1,96}',collation)),
                'SCHEMA_QUERY_UNEXPECTED_TABLE')
        found[name]={'engine':engine,'collation':collation}
    return {'contract':'mad4b.wayfatours.current-native-schema-sql-readback.v1',
        'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
        'company':'wayfatours','environment':'production',
        'schema_name':'wayfa_rebuild_wp',
        'source':'mariadb.information_schema.tables',
        'read_only':True,'sql_select_executed':True,'sql_write_executed':False,
        'schema_created':False,'current_migration_authorized':False,
        'tables':{kind:{'name':name,'exists':name in found,
                        'engine':found.get(name,{}).get('engine'),
                        'collation':found.get(name,{}).get('collation')}
                  for kind,name in TABLES.items()}}

def collect(runner=subprocess.run)->dict:
    assert PREFIX=='wp_mad4b_tenant_' and len(PREFIX)==16
    cmd=['/usr/bin/docker','exec','-u','root',DB_CONTAINER,'sh','-lc',
         'test "$MARIADB_DATABASE" = wayfa_rebuild_wp && '+QUERY]
    process=runner(cmd,capture_output=True,text=True,timeout=18,check=False)
    require(process.returncode==0 and not process.stderr.strip(),
            'ORIGINAL_DATABASE_SCHEMA_READBACK_UNAVAILABLE')
    return interpret(process.stdout)

def publish(row:dict,path:Path=REPORT)->None:
    directory=path.parent
    require(not directory.is_symlink() and directory.stat().st_uid==0 and
            directory.stat().st_mode&0o022==0 and not path.is_symlink(),
            'SCOPED_SCHEMA_REPORT_PATH_UNTRUSTED')
    fd,temp=tempfile.mkstemp(dir=directory,prefix='.current-native-schema-')
    try:
        with os.fdopen(fd,'w') as out:
            os.fchmod(out.fileno(),0o600)
            json.dump(row,out,sort_keys=True,indent=2)
            out.write('\n');out.flush();os.fsync(out.fileno())
        os.replace(temp,path)
    finally:
        if os.path.lexists(temp):os.unlink(temp)

if __name__=='__main__':
    try:
        data=collect();publish(data)
        print(json.dumps({'state':'CURRENT_DB_SCHEMA_READ_ONLY_VERIFIED',
            'observed_utc':data['observed_utc'],
            'tables_present':[k for k,v in data['tables'].items() if v['exists']],
            'tables_missing':[k for k,v in data['tables'].items() if not v['exists']],
            'schema_modified':False,'grant_issued':False}))
    except (Denied,OSError,TypeError,ValueError,subprocess.SubprocessError) as e:
        print(json.dumps({'state':'SCHEMA_READBACK_UNAVAILABLE',
                          'schema_modified':False,'grant_issued':False}))
        raise SystemExit(2)
