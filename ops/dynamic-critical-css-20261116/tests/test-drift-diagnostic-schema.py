#!/usr/bin/env python3
"""Pure original source drift output: V2 Elementor graph has NO CSS filename."""
import ast,contextlib,io,pathlib,tempfile,types
HERE=pathlib.Path(__file__).resolve().parent.parent
source=ast.parse((HERE/'run-public-route.py').read_text())
fn=next(x for x in source.body if isinstance(x,ast.FunctionDef) and x.name=='run_core')
block=next(x for x in fn.body if isinstance(x,ast.If) and isinstance(x.test,ast.Name)
           and x.test.id=='drift' and 'PUBLIC_CCSS_LIVE_ASSET_DRIFT' in ast.unparse(x))
checks=0
for evidence in ('document','css'):
 with tempfile.TemporaryDirectory() as tmp:
  base=pathlib.Path(tmp)
  record={'path':'/example-registered/', 'route_key':'c'*64}
  if evidence=='document':record.update(drift_kind='elementor_doc_fingerprint',expected_doc_sha='a'*64,observed_doc_sha='b'*64)
  else:record.update(drift_kind='css_asset',expected_css_file='old.css',css_file='new.css',css_sha='b'*64)
  saved=[]
  def select(doc,drift):return {**doc,'diagnosis':'original-current-only-'+drift['drift_kind']}
  def atom(path,doc):saved.append((path,doc))
  expected={'document':'PUBLIC_CCSS_LIVE_DOCUMENT_GRAPH_DRIFT',
            'css':'PUBLIC_CCSS_LIVE_ASSET_DRIFT'}[evidence]
  scope={'drift':record,'site':'wayfa','current_ledger':{'routes':{}},
         'drift_mod':types.SimpleNamespace(select_verified_repair=select),
         'family':types.SimpleNamespace(queue=types.SimpleNamespace(atom=atom)),
         'jobdir':base,'ledger':base/'original-route-ledger.json'}
  out=io.StringIO()
  with contextlib.redirect_stdout(out):
   exec(compile(ast.Module(body=[block],type_ignores=[]),'drift_diagnostic_policy', 'exec'),scope)
  assert expected+' wayfa /example-registered/' in out.getvalue()
  assert len(saved)==1 and saved[0][0]==base/'verified-asset-drift-selection-ledger.json'
  assert saved[0][1]['diagnosis']=='original-current-only-'+record['drift_kind']
  assert scope['current_ledger']=={'routes':{}}
  checks+=1;print('PASS',evidence,'original CSS/Elementor drift reporting does not require foreign keys')
print('PASS',checks,'/',checks,'source-bound report schema tests')
