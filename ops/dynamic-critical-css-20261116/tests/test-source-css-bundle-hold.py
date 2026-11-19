import importlib.util,json,pathlib,sys,tempfile
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('runner',D/'run-public-route.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
route={'path':'/ar/safe-tour/','language':'ar'}
rows=[{'uri':'/ar/safe-tour/','lang':'ar','width':w,'error':'Error: status=200 bundles=0'} for w in (320,390,414,768)]
with tempfile.TemporaryDirectory() as t:
 p=pathlib.Path(t)/'capture-index.json';p.write_text(json.dumps({'contract':'mad4b.mobile-critical.capture.v1','scenarios':rows}))
 assert m.optimizer_bundle_missing(p,route)
 print('PASS complete four viewport original-browser zero-CSS evidence')
 for k,v in [('uri','/other/'),('error','Error: status=503 bundles=0'),('lang','es')]:
  copy=[dict(r) for r in rows];copy[0][k]=v;p.write_text(json.dumps({'contract':'mad4b.mobile-critical.capture.v1','scenarios':copy}))
  assert not m.optimizer_bundle_missing(p,route),k
  print('PASS invalid zero-CSS evidence rejected:',k)
 p.write_text(json.dumps({'contract':'untrusted','scenarios':rows}));assert not m.optimizer_bundle_missing(p,route)
 print('PASS incorrect browser evidence contract rejected')
 p.unlink();p.symlink_to('/etc/passwd');assert not m.optimizer_bundle_missing(p,route)
 print('PASS symbolic source evidence cannot convert HOLD into native-only classification')
print('PASS 6/6 original optimizer-unavailable classification tests')
