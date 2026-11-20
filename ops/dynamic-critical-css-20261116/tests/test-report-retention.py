import datetime,hashlib,importlib.util,json,pathlib,sys,tempfile,time
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('archive',D/'report-retention.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=[]
def ok(name,test):
 assert test,name;checks.append(name);print('PASS',name)
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t)/'reports';(root/'source').mkdir(parents=True);(root/'wayfa').mkdir()
 m.MIN_FREE_GIB=0
 day=lambda n:datetime.datetime.fromtimestamp(time.time()-n*86400,datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
 candidates=[]
 for n in (20,15,12,9):
  path=root/'source'/day(n)
  path.mkdir();(path/'verification.json').write_text('{"qa":"actual five widths","run":'+str(n)+'}\n')
  (path/'page.html').write_text('<html><body>sample</body></html>\n')
  candidates.append(path)
 latest=max(candidates,key=lambda x:x.name)
 observed=m.sweep(root,time.time(),False)
 ok('dry run discovers only the oldest completed run',len(observed)==1 and observed[0]['run']==day(20))
 oldest=min(candidates,key=lambda x:x.name)
 ok('dry run never removes original QA evidence',oldest.is_dir())
 processed=m.sweep(root,time.time(),True)
 ok('actual archive archives only one old run after latest 3 remain',len(processed)==1 and not oldest.exists() and latest.is_dir())
 tar=root/'source/archive'/(oldest.name+'.tar.gz')
 receipt=root/'source/archive'/(oldest.name+'.sha256.json')
 record=json.loads(receipt.read_text())
 ok('read-only receipt saves exact all-file source SHA256',
    record['contract']=='mad4b.critical-css.archived-evidence.v1' and
    set(x['name'] for x in record['entries'])=={'verification.json','page.html'} and
    m.verify(tar,record['entries']))
 ok('compressed archive itself independently SHA-pinned',m.sha(tar)==record['tar_sha256'])
 ok('private tarball and provenance receipt',tar.stat().st_mode&0o777==0o600 and receipt.stat().st_mode&0o777==0o600)
 ok('source no longer selected after archival',not m.sweep(root,time.time(),False))
 other=root/'wayfa'/day(21)
 other.mkdir();(other/'actual-qa.txt').write_text('historical proof')
 (other/'point').symlink_to(root/'source')
 try:m.inventory(other)
 except ValueError:ok('symlink evidence refused instead of following references',True)
 else:raise AssertionError('symlink allowed')
 try:m.archive_one(root/'source'/'foo',root/'source/archive',False)
 except (ValueError,TypeError):ok('non-run directory not eligible for evidence deletion',True)
 else:raise AssertionError('nonrun allowed')
print(f'PASS {len(checks)}/{len(checks)} bounded CSS evidence archival invariants')
