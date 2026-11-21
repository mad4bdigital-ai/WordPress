import importlib.util,pathlib,sys
dir=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location("status",dir/"public-route-status.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
q=m.queue
def r(t,id,path,lang):
 import hashlib
 return {"type":t,"id":id,"path":path,"language":lang,"revision":0,
    "route_key":hashlib.sha256((lang+"|"+path+"|"+t+"|"+str(id)).encode()).hexdigest()}
routes=[r("page",20,"/about-us/","en"),
        r("post",21,"/es/blog/","es"),
        r("tours-and-activities",22,"/tour/nile/","en")]
cat={"contract":q.CATALOG,"host":"tours.dev.mad4b.com","wpml_languages":["en","es"],
 "default_language":"en","global_generation":0,"listing_generation":0,
 "routes":routes}
state={"site_host":"tours.dev.mad4b.com","critical_css":"on","css_epoch":129,"runtime_sha":"a"*64}
ledger={"site":"source","contract":q.QUEUE,"routes":{
 routes[0]["route_key"]:{"source_key":q.source_key(cat,routes[0],state),
                         "status":"certified","depends_on_listings":False},
 routes[1]["route_key"]:{"source_key":q.source_key(cat,routes[1],state),
                         "status":"hold","depends_on_listings":False}}}
result=m.build("source",cat,state,ledger)
assert result["discovered"]==3 and result["certified_ledger_only"]==1
assert result["held_full_css"]==1 and result["needs_certification"]==1
assert result["by_language"]["es"]["held_full_css"]==1
cat["global_generation"]=1
changed=m.build("source",cat,state,ledger)
assert changed["certified_ledger_only"]==0 and changed["needs_certification"]==3
try:m.build("wayfa",cat,state,ledger)
except ValueError:pass
else:raise AssertionError("cross company accepted")
print("PASS 6/6 read-only WPML route coverage status invariants")
