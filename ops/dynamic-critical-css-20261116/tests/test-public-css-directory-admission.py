import importlib.util,os,pathlib,stat,sys,tempfile
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('publisher',D/'public-route-certificate.py')
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
checks=[]
def ok(name,value):
 assert value,name;checks.append(name);print('PASS',name)
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t)
 original=os.umask(0o077)
 try:
  base=p.prepare_public_critical_directories(root)
  for d in (base,base/'assets',base/'certs'):
   ok('public CSS directory accessible under restrictive service umask',
      stat.S_IMODE(d.stat().st_mode)==0o755)
  certificate=base/'certs'/('a'*64+'.json')
  p.atomic_write(certificate,b'{"contract":"test"}\n')
  ok('certificate file readable by WordPress group and others',
     stat.S_IMODE(certificate.stat().st_mode)==0o644)
  (base/'assets').chmod(0o700)
  p.prepare_public_critical_directories(root)
  ok('legacy root-only assets directory automatically repaired',
     stat.S_IMODE((base/'assets').stat().st_mode)==0o755)
 finally:os.umask(original)
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t)
 (root/'outside').mkdir();(root/'mad4b-public-critical-css-v1').symlink_to(root/'outside',target_is_directory=True)
 try:p.prepare_public_critical_directories(root)
 except ValueError:ok('symlink replacing public CSS directory refused',True)
 else:raise AssertionError('symlink accepted')
print(f'PASS {len(checks)}/{len(checks)} restrictive umask and public certificate directory admission')
