#!/usr/bin/env python3
"""Honest, read-only public Critical CSS coverage report for one company.

Discovered route != browser-certified route != active public certificate.
This tool reports ledger admission without guessing HTML or live status.
"""
import collections,importlib.util,json,sys
from pathlib import Path
DIR=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location("routequeue",DIR/"public-route-queue.py")
queue=importlib.util.module_from_spec(spec);spec.loader.exec_module(queue)
def build(site,catalog,state,ledger):
    if ledger["site"]!=site:raise ValueError("wrong company ledger")
    rows=queue.safe_catalog(catalog,site)
    stats=collections.Counter()
    langs=collections.defaultdict(collections.Counter)
    bytype=collections.defaultdict(collections.Counter)
    for route in rows:
        typ=route["type"];lang=route["language"]
        rec=ledger["routes"].get(route["route_key"],{})
        key=queue.source_key(catalog,route,state,bool(rec.get("depends_on_listings")))
        if rec.get("source_key")!=key:
            status="needs_certification"
        elif rec.get("status")=="certified":
            status="certified_ledger_only"
        elif rec.get("status")=="native_preferred":
            status="native_preferred_full_css"
        elif rec.get("status")=="native_candidate":
            status="pending_native_confirmation"
        elif rec.get("status") in ["unsupported","hold"]:
            status="held_full_css"
        else:status="needs_certification"
        stats[status]+=1
        langs[lang][status]+=1
        bytype[typ][status]+=1
    return {"contract":"mad4b.public-critical-css.coverage-status.v1",
            "site":site,"discovered":len(rows),
            "certified_ledger_only":stats["certified_ledger_only"],
            "held_full_css":stats["held_full_css"],
            "native_preferred_full_css":stats["native_preferred_full_css"],
            "pending_native_confirmation":stats["pending_native_confirmation"],
            "needs_certification":stats["needs_certification"],
            "by_language":{k:dict(v) for k,v in sorted(langs.items())},
            "by_type":{k:dict(v) for k,v in sorted(bytype.items())},
            "note":"ledger admission only, public HTTP runtime readback needed to assert active"}
def main():
    if len(sys.argv)!=5:raise ValueError("usage: site catalog.json state.json ledger.json")
    site,cp,sp,lp=sys.argv[1:]
    cat=queue.read(cp);state=queue.read(sp)
    ledger=queue.read_ledger(Path(lp),site)
    print(json.dumps(build(site,cat,state,ledger),ensure_ascii=False,sort_keys=True,indent=2))
if __name__=="__main__":
    try:main()
    except (ValueError,TypeError,KeyError,OSError,IndexError,json.JSONDecodeError) as e:
        print("HOLD_ROUTE_COVERAGE_STATUS",str(e),file=sys.stderr);sys.exit(4)
