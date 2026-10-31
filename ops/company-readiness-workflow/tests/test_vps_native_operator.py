#!/usr/bin/env python3
"""Isolated admission tests; must never execute a VPS shell or SQL command."""
import importlib.util, os, pathlib, tempfile, hashlib, json
P=pathlib.Path(__file__).resolve().parents[1]/'executor'/'vps-native-operator.py'
spec=importlib.util.spec_from_file_location('native_operator_test_module',P)
op=importlib.util.module_from_spec(spec);spec.loader.exec_module(op)
policy={'contract':op.CONTRACT,'host_id':op.HOST,'principal':'root','revoked':False,
 'allow_general_shell':True,'allow_raw_sql':True,'scopes':{
 'tours-central:staging':{'allow_operator':True,'allow_sql':True,
   'allow_mutation':True,'allow_production_mutation':False},
 'wayfatours:production':{'allow_operator':True,'allow_sql':True,
   'allow_mutation':False,'allow_production_mutation':False}}}
class Args:pass
def admission(file_data,readback_data,kind='shell',intent='diagnostic',
 company='tours-central',environment='staging',backup=None,rollback=None):
  with tempfile.TemporaryDirectory() as d:
    root=pathlib.Path(d)
    def write(name,value):
      p=root/name;p.write_bytes(value);os.chmod(p,0o600);return str(p)
    a=Args();a.file=write('command',file_data);a.readback_file=write('readback',readback_data)
    a.sha256=op.sha(file_data);a.expected_readback_sha256=op.sha(readback_data)
    a.intent=intent;a.kind=kind;a.company=company;a.environment=environment
    a.backup_file=write('backup',backup) if backup else None
    a.backup_sha256=op.sha(backup) if backup else None
    a.rollback_file=write('rollback',rollback) if rollback else None
    return op.preflight(a,policy)
def denial(fn,label):
  try:fn()
  except RuntimeError:return
  raise AssertionError(label)
shell=admission(b'echo diagnostic',b'hostname')
assert shell['lane']=='vps_ssh' and shell['command_catalog_required']==False
assert shell['mutating']==False
sql=admission(b'SELECT 1;',b'SELECT 1;',kind='sql',intent='read')
assert sql['mutating']==False
denial(lambda:admission(b'UPDATE wp_options SET option_value=1',b'SELECT 1',
    kind='sql'), 'mutating SQL disguised as diagnostic')
denial(lambda:admission(b'SELECT 1; DELETE FROM wp_options;',b'SELECT 1',
    kind='sql'), 'multi-query read bypass')
denial(lambda:admission(b'SELECT 1 INTO OUTFILE \'/tmp/a\';',b'SELECT 1',
    kind='sql'), 'select into bypass')
denial(lambda:admission(b'UPDATE wp_options SET option_value=1',b'SELECT 1',
    kind='sql',intent='repair'), 'backup and rollback mandatory')
denial(lambda:admission(b'SELECT 1',b'SELECT 1',kind='sql',intent='repair',
    environment='production',company='wayfatours',backup=b'backup',rollback=b'ROLLBACK'),
    'production mutating grant denied')
denial(lambda:admission(b'SELECT 1',b'SELECT 1',kind='sql',
    environment='production',company='not-wayfatours'),
    'unenrolled production company')
denial(lambda:admission(b'UPDATE canary SET state=1',
    b'UPDATE canary SET state=2',kind='sql',intent='repair',
    backup=b'database-backup-evidence',rollback=b'UPDATE canary SET state=0'),
    'mutating readback must be denied even for mutating SQL')
denial(lambda:admission(b'SELECT 1',b'SELECT 1; DELETE FROM canary',
    kind='sql',intent='diagnostic'), 'multi-statement readback denied')
denial(lambda:admission(b'SELECT 1',b'SELECT GET_LOCK(\'x\',1)',
    kind='sql',intent='read'), 'SQL readback locking primitive denied')
# Test that a scoped staging SQL write can be planned when BOTH
# independent snapshot and rollback files are supplied. No DB changes.
plan=admission(b'UPDATE canary SET state=1',b'SELECT state FROM canary',
    kind='sql',intent='repair',backup=b'database-backup-evidence',
    rollback=b'UPDATE canary SET state=0')
assert plan['mutating'] is True and plan['environment']=='staging'
assert plan['backup_sha256']==op.sha(b'database-backup-evidence')
assert plan['rollback_sha256']==op.sha(b'UPDATE canary SET state=0')
large_backup=b'MAD4B_STAGING_SCHEMA_BACKUP\\n'*12000
big=admission(b'UPDATE canary SET state=1',b'SELECT state FROM canary',
    kind='sql',intent='repair',backup=large_backup,
    rollback=b'UPDATE canary SET state=0')
assert big['backup_sha256']==op.sha(large_backup)
print('PASS 13: native general SSH, SQL inspection, no unsafe read bypass, staging backup/rollback and production deny')
