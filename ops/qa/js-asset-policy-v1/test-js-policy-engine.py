#!/usr/bin/env python3
"""Synthetic zero-network JS dynamic-policy acceptance suite."""
import importlib.util,sys
from pathlib import Path
q=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('eng',q/'js-policy-engine.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
rows=[]
for n in range(5):
 for mode in ['native-v1','files-v1']:
  native=mode=='native-v1'
  rows.append({'status':200,'documentReady':'complete','mainNav':2,'vary':1,'errors':[],'failedJs':[],
   'dom':{'listing':156,'widgets':639,'forms':4,'filters':1},
   'globals':{'jquery':True,'elementor':True,'woocommerce':True},
   'clsMeasured':.012,'lcpMeasured':4100,'ttfb':3100,'mobile':False,'round':n,'mode':mode,
   'scripts':[{}]*(90 if native else 27),
   'metrics':{'ScriptDuration':1.5 if native else 1.1,'TaskDuration':3.4 if native else 2.9}})
data={'complete':True,'path':'/','device':'desktop','candidate':'files-v1','planned':10,'observations':rows}
issues,metrics=m.evaluate(data,'desktop','files-v1');assert not issues,(issues,metrics)
print('PASS full five-pair desktop certification evidence')
rows[1]['dom']['listing']=155
issues,_=m.evaluate(data,'desktop','files-v1');assert 'Elementor/JetEngine/listing DOM mismatch' in issues
print('PASS dynamic listing regression causes HOLD')
rows[1]['dom']['listing']=156
issues,_=m.evaluate(data,'mobile','files-v1');assert issues
print('PASS Desktop evidence cannot apply to Mobile')
data['complete']=False;issues,_=m.evaluate(data,'desktop','files-v1');assert issues
print('PASS truncated evidence causes HOLD')
data['complete']=True
for row in rows:row['scripts']=[{}]*90
issues,_=m.evaluate(data,'desktop','files-v1');assert 'No effective JS combining on this device' in issues
print('PASS no-op mobile-style bundle change cannot certify')
print('mad4b.js.performance.contract.v1 PASS')
