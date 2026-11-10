#!/usr/bin/env python3
"""Strict READ-ONLY company migration prerequisite observation.
Never backs up, migrates, signs, executes SQL DDL or changes Production.
"""
from pathlib import Path
from datetime import datetime,timezone
import json,hashlib,os,stat,re,subprocess
ROOT=Path('/opt/mad4b-company-provisioning/reports/company-execution-closeout-20261110')
SRC=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-tenant-sync/includes')
CONTAINER='php-guxhp9y6vgh7oouuw8plxrlr'
FILES=('class-mad4b-tenant-workflow.php','class-mad4b-tenant-sparse-store.php')
BACKUPS=(Path('/opt/mad4b-backups'),Path('/opt/mad4b-company-provisioning/backups'))
OUT=ROOT/'wayfa-tenant-migration-preflight-current.json'
RESTORE_PROOF=ROOT/'wayfa-backup-restore-verification.json'
def run(args,timeout=20):
 result=subprocess.run(args,check=False,capture_output=True,text=True,timeout=timeout)
 if result.returncode:raise RuntimeError('PRODUCTION_READBACK_UNAVAILABLE')
 return result.stdout.strip()
def inspect():
 source_hash={}
 deployed_hash={}
 for file in FILES:
  p=SRC/file
  if p.is_symlink() or not p.is_file(): raise RuntimeError('UNTRUSTED_SOURCE_FILE')
  source_hash[file]=hashlib.sha256(p.read_bytes()).hexdigest()
  observed=run(['docker','exec',CONTAINER,'sha256sum','/var/www/html/wp-content/plugins/mad4b-tenant-sync/includes/'+file])
  matched=re.fullmatch(r'([a-f0-9]{64})\s+\S+',observed)
  if not matched:raise RuntimeError('PRODUCTION_SHA_UNVERIFIED')
  deployed_hash[file]=matched.group(1)
 report=json.loads((ROOT/'wayfa-tenant-readiness.json').read_text())
 if report.get('contract')!='mad4b.company-tenant-approved-content-production-preflight.v1' or report.get('environment')!='production' or report.get('read_only') is not True or report.get('writes_executed') is not False:raise RuntimeError('SCOPED_SITE_READBACK_UNVERIFIED')
 observed=datetime.fromisoformat(report['observed_utc'].replace('Z','+00:00'))
 if observed.tzinfo is None or not -60<=(datetime.now(timezone.utc)-observed).total_seconds()<=3600:raise RuntimeError('SCOPED_READBACK_STALE')
 schema_path=ROOT/'wayfa-native-schema-current-sql-readback.json'
 if schema_path.is_symlink() or not schema_path.is_file() or schema_path.stat().st_uid!=0 or schema_path.stat().st_mode&0o077:
  raise RuntimeError('CURRENT_SCHEMA_REPORT_UNTRUSTED')
 schema=json.loads(schema_path.read_text())
 if (schema.get('contract')!='mad4b.wayfatours.current-native-schema-sql-readback.v1'
     or schema.get('company')!='wayfatours' or schema.get('environment')!='production'
     or schema.get('schema_name')!='wayfa_rebuild_wp'
     or schema.get('read_only') is not True
     or schema.get('sql_select_executed') is not True
     or schema.get('sql_write_executed') is not False
     or schema.get('schema_created') is not False):
  raise RuntimeError('CURRENT_SCHEMA_READBACK_UNTRUSTED')
 schema_time=datetime.fromisoformat(schema['observed_utc'].replace('Z','+00:00'))
 if schema_time.tzinfo is None or not -30<=(datetime.now(timezone.utc)-schema_time).total_seconds()<=900:
  raise RuntimeError('CURRENT_SCHEMA_READBACK_STALE')
 expected={'maps':'wp_mad4b_tenant_content_maps',
           'overrides':'wp_mad4b_tenant_field_overrides',
           'cache':'wp_mad4b_tenant_hub_cache',
           'approvals':'wp_mad4b_tenant_approvals'}
 if (set(schema.get('tables',{}))!=set(expected)
     or any(not isinstance(schema['tables'][family],dict)
             or schema['tables'][family].get('name')!=name
             or type(schema['tables'][family].get('exists')) is not bool
            for family,name in expected.items())):
  raise RuntimeError('CURRENT_SCHEMA_TABLE_SCOPE_DRIFT')
 tables={family:schema['tables'][family]['exists'] for family in expected}
 candidate=[]
 for folder in BACKUPS:
  if not folder.is_dir():continue
  for path in folder.glob('**/*'):
   if not path.is_file() or not ('wayfa' in str(path).lower()) or not (path.name.endswith('.sql.gz') or path.name.endswith('.sql') or path.name.endswith('.sql.zst')):continue
   try:
    s=path.stat()
    if s.st_size<10000:continue
    candidate.append((s.st_mtime,str(path),s.st_size))
   except OSError:pass
 candidate.sort(reverse=True)
 backup_metadata=(dict(path=candidate[0][1],size_bytes=candidate[0][2],date_utc=datetime.fromtimestamp(candidate[0][0],timezone.utc).isoformat()) if candidate else None)
 backup_recent=bool(candidate and 0<=(datetime.now(timezone.utc).timestamp()-candidate[0][0])<=3600)
 # Independently validate the particular backup imported into the isolated,
 # network-disabled MariaDB sandbox. A filename, receipt, or checksum alone
 # never grants a schema migration capability.
 restore_verified=False
 restore_proof_state='MISSING_OR_UNVERIFIED'
 try:
  receipt=json.loads(RESTORE_PROOF.read_text())
  actual_path=Path(str(receipt.get('backup_path','')))
  if (not RESTORE_PROOF.is_symlink()
      and RESTORE_PROOF.stat().st_uid==0
      and RESTORE_PROOF.stat().st_mode&0o077==0
      and receipt.get('contract')=='mad4b.wayfatours.backup-and-isolated-restore.v1'
      and receipt.get('company')=='wayfatours'
      and receipt.get('environment')=='production'
      and receipt.get('source_db')=='wayfa_rebuild_wp'
      and receipt.get('backup_method','').startswith('mariadb-dump --single-transaction')
      and receipt.get('compression_checked') is True
      and receipt.get('checksum_checked') is True
      and receipt.get('restore_import_success') is True
      and receipt.get('restore_authenticated_select_1') is True
      and receipt.get('restore_sandbox_removed') is True
      # Historical receipt predates source_table_count and was independently
      # verified at exactly 314 tables. New receipts must use dynamic,
      # independently measured source table counts, never a global constant.
      and type(receipt.get('source_table_count',314)) is int
      and receipt.get('source_table_count',314)>=314
      and type(receipt.get('restored_table_count')) is int
      and receipt.get('restored_table_count')==receipt.get('source_table_count',314)
      and (receipt.get('source_wp_posts_count') is None or
           (type(receipt['source_wp_posts_count']) is int and
            receipt['source_wp_posts_count']==receipt.get('restored_wp_posts_count')))
      and (receipt.get('source_wp_options_count') is None or
           (type(receipt['source_wp_options_count']) is int and
            receipt['source_wp_options_count']==receipt.get('restored_wp_options_count')))
      and receipt.get('restored_wp_posts_count',0)>100
      and receipt.get('restored_wp_options_count',0)>100
      and receipt.get('restored_tenant_table_count')==0
      and receipt.get('database_modified') is False
      and receipt.get('schema_migration_authorized') is False
      and actual_path.is_file()
      and not actual_path.is_symlink()
      and actual_path.stat().st_uid==0
      and actual_path.stat().st_mode&0o077==0
      and str(actual_path.resolve()).startswith('/opt/mad4b-company-provisioning/backups/wayfatours-tenant-schema-')
      and candidate and candidate[0][1]==str(actual_path)
      and receipt.get('backup_size_bytes')==actual_path.stat().st_size):
   with actual_path.open('rb') as f:
    digest=hashlib.file_digest(f,'sha256').hexdigest()
   restore_verified=digest==receipt.get('backup_sha256')
   restore_proof_state='ISOLATED_RESTORE_VERIFIED' if restore_verified else 'CHECKSUM_MISMATCH'
 except (OSError,TypeError,ValueError,KeyError,json.JSONDecodeError):
  restore_verified=False
 # Freshness is a separate property from restorable backup proof.
 blockers=[]
 if not all(source_hash[k]==deployed_hash[k] for k in FILES):blockers.append('PRODUCTION_TENANT_CODE_NOT_CURRENT')
 if not all(tables.values()):blockers.append('TENANT_APPROVAL_TABLES_MISSING')
 if not backup_recent:blockers.append('CURRENT_HOUR_SCOPED_BACKUP_NOT_PROVEN')
 if not restore_verified:blockers.append('SCOPED_BACKUP_RESTORE_NOT_VERIFIED')
 blockers.append('ORIGINAL_SIGNED_SCHEMA_MIGRATION_GRANT_NOT_PROVEN')
 result={'contract':'mad4b.company-tenant-production-migration-preflight.v1',
  'observed_utc':datetime.now(timezone.utc).isoformat(),
  'company':'wayfatours','environment':'production',
  'read_only':True,'source_code_sha256':source_hash,
  'production_code_sha256':deployed_hash,
  'tenant_code_parity':all(source_hash[k]==deployed_hash[k] for k in FILES),
  'schema_tables':tables,
  'most_recent_discovered_wayfa_backup':backup_metadata,
  'recent_backup_metadata_only':backup_recent,
  'restore_verified':restore_verified,
  'restore_proof_state':restore_proof_state,
  'schema_migration_authorized':False,
  'ready_for_production_migration':False,
  'blockers':blockers,
  'new_job_issued':False,'grant_issued':False,
  'database_modified':False,'code_deployed':False}
 tmp=OUT.with_name(OUT.name+'.new.'+str(os.getpid()))
 with open(tmp,'x') as f:json.dump(result,f,indent=2,sort_keys=True);f.write('\n')
 os.replace(tmp,OUT)
 print(json.dumps({'state':'BLOCKED','blockers':blockers,'code_parity':result['tenant_code_parity'],'tables_missing':sum(not x for x in tables.values()),'backup_recent_metadata':backup_recent,'restore_verified':restore_verified}))
if __name__=='__main__':inspect()
