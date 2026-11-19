import importlib.util,json,pathlib,sys,tempfile
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location("health",D/"health-audit.py")
h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
checks=[]
def test(name,value):
 assert value,name;checks.append(name);print('PASS',name)
with tempfile.TemporaryDirectory() as temp:
 root=pathlib.Path(temp)
 repo=root/'repo';site=root/'site'
 (repo/'wp-content/mu-plugins').mkdir(parents=True)
 (site/'mu-plugins').mkdir(parents=True)
 original=h.REPO
 h.REPO=repo
 try:
  for name in ('421-mad4b-public-critical-css-coverage.php',
               '422-mad4b-public-critical-css-runtime.php'):
   (repo/'wp-content/mu-plugins'/name).write_bytes(name.encode())
   (site/'mu-plugins'/name).write_bytes(name.encode())
  test('source and runtime hashes match',len(h.attest_files(site))==2)
  (site/'mu-plugins/421-mad4b-public-critical-css-coverage.php').write_text('untrusted')
  try:h.attest_files(site)
  except ValueError:test('live source drift fails admission',True)
  else:raise AssertionError
  (site/'mu-plugins/421-mad4b-public-critical-css-coverage.php').write_bytes(
   (repo/'wp-content/mu-plugins/421-mad4b-public-critical-css-coverage.php').read_bytes())
  dirs=[site/'mad4b-public-critical-css-v1'/name for name in ('','assets','certs')]
  for p in dirs:p.mkdir(parents=True,exist_ok=True)
  test('separate world-readable public directories safe',h.file_permissions(site))
  dirs[1].chmod(0o700)
  try:h.file_permissions(site)
  except ValueError:test('root-only directory prevents false healthy',True)
  else:raise AssertionError
  dirs[1].chmod(0o755)
  dirs[2].chmod(0o777)
  try:h.file_permissions(site)
  except ValueError:test('world-writable certificate directory refused',True)
  else:raise AssertionError
  dirs[2].chmod(0o755)
  report=root/'private-report.json'
  h.write(report,{'ok':True})
  test('private read-only audit report atomically recorded',json.loads(report.read_text())['ok'])
  test('audit report never world readable',report.stat().st_mode&0o777==0o600)
  calls=[]
  original_command=h.command
  try:
   def mocked(args,timeout=25):
    calls.append(args)
    return 'HTTP/2 200 \r\nX-MAD4B-Mobile-CSS-Ready: 1\r\nX-MAD4B-Public-CCSS-Currency: USD\r\n'
   h.command=mocked
   result=h.http_get('wayfatours.com','/about-us/')
   test('public GET with browser Accept yields truthful header',result['http']=='HTTP/2 200' and result['ready_home']=='1')
   test('public probe is GET not HEAD', '-I' not in calls[0] and 'curl' in calls[0])
   try:h.http_get('wayfatours.com','/checkout/')
   except ValueError:test('checkout probe denied before network',len(calls)==1)
   else:raise AssertionError
   try:h.http_get('wayfatours.com','/wp-json/private')
   except ValueError:test('REST probe denied',True)
   else:raise AssertionError
   try:h.http_get('wayfatours.com','//other')
   except ValueError:test('invalid path denied',True)
   else:raise AssertionError
  finally:h.command=original_command
 finally:h.REPO=original
messages=[]
original_logger=h.subprocess.run
try:
 def fake_logger(argv,**kwargs):
  messages.append(argv)
 h.subprocess.run=fake_logger
 h.emit_local_alerts({
  'source':{'status':'degraded','warnings':['home_native_css_fallback:it'],'issues':[]},
  'wayfa':{'status':'error','warnings':[],'issues':['native_shafile_untrusted']},
  'healthy':{'status':'healthy','warnings':[],'issues':[]}
 })
 test('safe fallback creates local daemon warning not a silent success',
      len(messages)==2 and messages[0][2]=='daemon.warning')
 test('hard runtime failure creates local error priority',
      messages[1][2]=='daemon.err')
 test('local alerts never contact external SMTP or HTTP',
      all(x[0]=='logger' for x in messages))
finally:h.subprocess.run=original_logger
busy=h.subprocess.CalledProcessError(75,['docker','exec','php','wp'],
    stderr='STAGING_WPCLI_BUSY: two active WordPress CLI sessions; retry later.')
transient=h.classify_site_failure(busy)
test('WPCLI admission code 75 with busy evidence is transient not CSS corruption',
     transient['status']=='deferred' and not transient['issues'])
test('non-busy command failure is a genuine hard integrity error',
     h.classify_site_failure(h.subprocess.CalledProcessError(4,['docker'],stderr='php fatal'))['status']=='error')
test('unrelated code 75 without explicit admission evidence does not hide real failures',
     h.classify_site_failure(h.subprocess.CalledProcessError(75,['docker'],stderr='unknown'))['status']=='error')
unit=(D/'systemd/mad4b-critical-css-integrity-audit.service').read_text()
test('hard integrity failure delegates to existing rate-limited systemd alert channel',
     'OnFailure=mad4b-alert-handler@%n.service' in unit)
test('WPCLI busy keeps bounded retry without turning into a hard-error email storm',
     'Restart=on-failure' in unit and 'RestartPreventExitStatus=4' in unit)
part={'discovered':100,'certified_ledger_only':8,'native_preferred_full_css':2,
      'held_full_css':3,'needs_certification':87,'pending_native_confirmation':0}
res=h.coverage_completion(part)
test('low certificate count remains rolling regardless of healthy engine',
     res['status']=='rolling' and not res['fully_qualified'] and res['qualified_percent']==10)
test('independently negative-proven full native CSS counts as qualified',
     res['independently_native_preferred']==2 and res['needs_certification_or_hold']==90)
part.update({'certified_ledger_only':98,'native_preferred_full_css':2,
             'held_full_css':0,'needs_certification':0})
test('full scope only when all routes independently accounted',
     h.coverage_completion(part)['fully_qualified'])
part['needs_certification']=1
try:h.coverage_completion(part)
except ValueError:test('tampered overlapping counts refused',True)
else:raise AssertionError('unbalanced coverage accepted')
print(f'PASS {len(checks)}/{len(checks)} read-only permanent stability health invariants')
