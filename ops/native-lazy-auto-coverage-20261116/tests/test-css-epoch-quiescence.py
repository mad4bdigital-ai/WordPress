#!/usr/bin/env python3
from pathlib import Path
import importlib.util
import sys
p=Path(sys.argv[1])/'css-epoch-quiescence.py'
spec=importlib.util.spec_from_file_location('epoch_quiescence',p)
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
checks=[
 ('first observed revision waits',127,1000,None,1000,600,False),
 ('repeated stable epoch remains under minimum hold',127,1000,{'epoch':127,'first_seen_at':1000},1500,600,False),
 ('exactly full quiet interval allows QA',127,1000,{'epoch':127,'first_seen_at':1000},1600,600,True),
 ('home edit resets quiet gate even without new epoch observation',127,1650,{'epoch':127,'first_seen_at':1000},1700,600,False),
 ('new epoch uses recent trusted event time',128,4700,{'epoch':127,'first_seen_at':1000},5000,600,False),
 ('new source eventually eligible',128,1000,{'epoch':128,'first_seen_at':5000},5601,600,True),
 ('first seen after a full quiet interval can immediately start',128,1000,None,1700,600,True),
 ('future source update rejected',127,1900,{'epoch':127,'first_seen_at':1000},1700,600,'error'),
 ('future persisted observer rejected',127,1000,{'epoch':127,'first_seen_at':1900},1700,600,'error'),
 ('invalid negative epoch rejected',-1,1000,None,1700,600,'error'),
 ('invalid too-short window rejected',127,1000,None,1700,0,'error'),
 ('no prior source time still must wait one full interval',127,0,None,2000,600,False)
]
for name,epoch,last,old,now,quiet,expected in checks:
    try:got,_=mod.eligible(epoch,last,old,now,quiet)
    except (ValueError,TypeError,KeyError):got='error'
    assert got==expected,(name,got,expected)
    print('PASS',name)
print(f'PASS {len(checks)}/{len(checks)} CSS epoch quiet-period invariants')
