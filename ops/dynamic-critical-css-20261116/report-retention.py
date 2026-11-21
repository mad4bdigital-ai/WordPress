#!/usr/bin/env python3
"""Conservative evidence-retaining report archival, not CSS/cache deletion.

Only timestamped completed run directories under fixed company report roots.
Always preserve newest three runs per company; compress aged full browser
records to verified SHA256 tar.gz with private manifest. Never delete an
original until its verified archive and receipt are fsynced and durable.
"""
import datetime,gzip,hashlib,json,os,re,shutil,stat,sys,tarfile,tempfile,time
from pathlib import Path
ROOTS=(
 Path('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116'),
 Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')
)
RUN_RE=re.compile(r'^20[0-9]{6}T[0-9]{6}Z$')
KEEP_LATEST=3
MIN_AGE_DAYS=5
MIN_FREE_GIB=18
def sha(path):
 h=hashlib.sha256()
 with open(path,'rb') as f:
  while True:
   buf=f.read(1024*1024)
   if not buf:break
   h.update(buf)
 return h.hexdigest()
def inventory(path):
 if path.is_symlink() or not path.is_dir():raise ValueError('invalid-run-directory')
 rows=[]
 for current,dirs,files in os.walk(path,followlinks=False):
  for name in dirs+files:
   p=Path(current)/name
   if p.is_symlink():raise ValueError('symlink-in-run-evidence-refused')
   mode=p.lstat().st_mode
   if not(stat.S_ISDIR(mode) or stat.S_ISREG(mode)):
    raise ValueError('special-file-in-evidence-refused')
  for name in files:
   p=Path(current)/name
   rel=p.relative_to(path).as_posix()
   if rel.startswith('../') or '/..' in rel or len(rel)>450:
    raise ValueError('invalid-evidence-name')
   rows.append({'name':rel,'size':p.stat().st_size,'sha256':sha(p)})
 if not rows:raise ValueError('no-evidence-to-archive')
 return sorted(rows,key=lambda x:x['name'])
def verify(archive,rows):
 expected={r['name']:(r['size'],r['sha256']) for r in rows}
 observed={}
 with tarfile.open(archive,'r:gz') as archive_read:
  for member in archive_read:
   if not member.isfile() or member.name not in expected or member.name in observed:
    raise ValueError('unexpected-archive-member')
   with archive_read.extractfile(member) as file:
    h=hashlib.sha256();size=0
    while True:
     data=file.read(1024*1024)
     if not data:break
     h.update(data);size+=len(data)
    if (size,h.hexdigest())!=expected[member.name]:
     raise ValueError('archived-evidence-content-mismatch')
    observed[member.name]=(size,h.hexdigest())
 if set(observed)!=set(expected):raise ValueError('archive-missing-evidence')
 return True
def archive_one(run,dest,apply):
 if run.is_symlink() or not run.is_dir() or not RUN_RE.fullmatch(run.name):
  raise ValueError('unexpected-run-directory')
 if not apply:return {'run':run.name,'result':'dry_run'}
 dest.mkdir(parents=True,exist_ok=True,mode=0o700)
 if dest.is_symlink() or stat.S_IMODE(dest.stat().st_mode)!=0o700:
  raise ValueError('untrusted-archive-directory')
 out=dest/(run.name+'.tar.gz')
 receipt=dest/(run.name+'.sha256.json')
 if out.exists() or receipt.exists():raise ValueError('conflicting-archive-or-receipt')
 files=inventory(run)
 if shutil.disk_usage(dest).free<MIN_FREE_GIB*1024**3:
  raise ValueError('not-enough-disk-for-verified-archive')
 fd,tmp=tempfile.mkstemp(prefix='.archive-',dir=dest,suffix='.tar.gz')
 os.close(fd)
 try:
  with open(tmp,'wb') as raw:
   os.chmod(tmp,0o600)
   with gzip.GzipFile(fileobj=raw,mode='wb',compresslevel=3,filename=run.name+'.tar') as zipped:
    with tarfile.open(fileobj=zipped,mode='w|') as tape:
     for row in files:
      file=run/row['name']
      if file.is_symlink() or not file.is_file() or file.stat().st_size!=row['size']:
       raise ValueError('evidence-changed-during-archive')
      info=tarfile.TarInfo(row['name']);info.size=row['size']
      info.mode=0o600;info.uid=0;info.gid=0;info.uname='';info.gname=''
      with open(file,'rb') as f:tape.addfile(info,f)
   raw.flush();os.fsync(raw.fileno())
  verify(Path(tmp),files)
  if inventory(run)!=files:raise ValueError('source-run-changed-before-removal')
  digest=sha(Path(tmp))
  receipt_info={
   'contract':'mad4b.critical-css.archived-evidence.v1',
   'run':run.name,'site':run.parent.name,
   'source_root':run.parent.parent.name,
   'tar_sha256':digest,'tar_bytes':Path(tmp).stat().st_size,
   'entries':files,'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
  }
  fd,temporary_receipt=tempfile.mkstemp(prefix='.receipt-',dir=dest)
  try:
   with os.fdopen(fd,'w') as f:
    os.fchmod(f.fileno(),0o600)
    json.dump(receipt_info,f,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
   os.replace(tmp,out);os.replace(temporary_receipt,receipt)
  finally:
   if os.path.exists(temporary_receipt):os.unlink(temporary_receipt)
  # Immutable source evidence is now in a verified, SHA-stamped archive;
  # do not remove it if it changed in the short time since verification.
  if inventory(run)!=files:raise ValueError('archive-committed-source-changed-preserved')
  shutil.rmtree(run)
  return {'run':run.name,'result':'archived','files':len(files),
          'bytes':out.stat().st_size,'sha256':digest[:16]}
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
def sweep(root,now,apply):
 if root.is_symlink() or not root.is_dir():raise ValueError('invalid-report-root')
 rows=[]
 for site in ('source','wayfa'):
  area=root/site
  if area.is_symlink() or not area.is_dir():raise ValueError('untrusted-site-report-root')
  runs=sorted((p for p in area.iterdir() if p.is_dir() and RUN_RE.fullmatch(p.name)),
              key=lambda p:p.name,reverse=True)
  for idx,run in enumerate(runs):
   try:created=datetime.datetime.strptime(run.name,'%Y%m%dT%H%M%SZ').replace(tzinfo=datetime.timezone.utc).timestamp()
   except ValueError:continue
   if idx<KEEP_LATEST or now-created<MIN_AGE_DAYS*86400:continue
   rows.append({'site':site,**archive_one(run,area/'archive',apply)})
 return rows
def main():
 apply=sys.argv[1:]==['--apply']
 if sys.argv[1:] not in ([],['--apply']):raise ValueError('only optional --apply')
 result=[]
 now=time.time()
 for root in ROOTS:result+=sweep(root,now,apply)
 print('MAD4B_CCSS_EVIDENCE_RETENTION',json.dumps({
  'mode':'apply' if apply else 'dry_run','eligible_count':len(result),
  'runs':result[:30]},sort_keys=True))
if __name__=='__main__':
 try:main()
 except (ValueError,OSError,KeyError,TypeError,tarfile.TarError) as exc:
  print('HOLD_CCSS_EVIDENCE_RETENTION',str(exc),file=sys.stderr);sys.exit(4)
