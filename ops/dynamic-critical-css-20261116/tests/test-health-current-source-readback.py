"""Historical CSS status must never be reported as current source readiness."""
from pathlib import Path
import importlib.util,sys
root=Path(sys.argv[1])
s=importlib.util.spec_from_file_location('health_source_readback',root/'health-audit.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
route=lambda id,lang,path:{"route_key":str(id),"language":lang,"path":path,"type":"page","revision":id}
a=route(1,"en","/about-us/")
b=route(2,"en","/category/interests/")
c=route(3,"en","/tours-and-activities/marsa-alam-to-luxor-temples-1-day-tour/")
d=route(4,"es","/es/tours-and-activities/saqqara-y-menfis/")
catalog={"routes":[a,b,c,d],"host":"wayfatours.com","global_generation":679,"listing_generation":1436}
state={"css_epoch":252,"native_breakpoint":767,"critical_css":"on","runtime_sha":"2"*64}
old=m.queue.source_key
try:
 m.queue.source_key=lambda catalog,route,state,depends: "SOURCE-"+route["route_key"]+"-"+str(state["css_epoch"])
 records={"routes":{
  a["route_key"]:{"status":"certified","source_key":"SOURCE-1-39"},
  b["route_key"]:{"status":"certified","source_key":"SOURCE-2-39"},
  c["route_key"]:{"status":"certified","source_key":"SOURCE-3-252"},
  d["route_key"]:{"status":"certified","source_key":"SOURCE-4-252"}}}
 fresh,stale=m.source_current_route_readback_candidates(catalog,state,records,limit=2)
 assert [v["path"] for v in fresh]==[c["path"],d["path"]]
 assert stale==[a["path"],b["path"]]
 print("PASS historical v1 certified rows omitted from live CSS readiness")
 print("PASS exactly two current route samples selected across EN and ES")
 state["css_epoch"]=253
 fresh,stale=m.source_current_route_readback_candidates(catalog,state,records)
 assert fresh==[] and len(stale)==4
 print("PASS changed CSS epoch invalidates all old source statuses")
 state["css_epoch"]=252
 records["routes"][c["route_key"]]["status"]="hold"
 fresh,stale=m.source_current_route_readback_candidates(catalog,state,records)
 assert [v["path"] for v in fresh]==[d["path"]]
 print("PASS historical HOLD cannot be promoted by sampling")
 records["routes"][c["route_key"]]["status"]="certified"
 records["routes"][c["route_key"]]["source_key"]="bad"
 fresh,stale=m.source_current_route_readback_candidates(catalog,state,records)
 assert c["path"] in stale
 print("PASS mismatched current source key cannot look certified")
finally:
 m.queue.source_key=old
print("PASS 5/5 current-source readback regressions")
