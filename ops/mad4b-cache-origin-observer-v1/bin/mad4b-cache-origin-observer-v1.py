#!/usr/bin/env python3
"""Bounded native cache/origin telemetry: aggregated flags only; no visitor identity.

Mode: one site at a time, independent timer. Source Nginx is sampled (20%)
and emits no sensitive fields. Wayfa remains on its original configuration,
so only nonauthenticated synthetic requests are used there.
"""
from __future__ import annotations
import argparse
import collections
import datetime
import hashlib
import json
import math
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

SITES = {
 "source": {
   "host": "tours.dev.mad4b.com",
   "nginx_host_config":"/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf",
   "nginx": "nginx-wkgtkzta35wkkgouwt6ruljc",
 },
 "wayfa": {
   "host": "wayfatours.com",
   "nginx_host_config":"/opt/wayfa-rebuild-20261102/stack/nginx.conf",
   "nginx": "nginx-guxhp9y6vgh7oouuw8plxrlr",
 },
}
REPORT_ROOT=Path("/opt/mad4b-company-provisioning/reports/cache-origin-observability-20261122")
PREFIX="MAD4B_CACHE_DECISION_V1 "
GOOD_CACHES={"HIT","MISS","BYPASS","EXPIRED","STALE","UPDATING","REVALIDATED","SCARCE","-"}
ROUTES={"home","localized_home","tour_archive","tour_detail","packages","other"}
FLAGS={
 "source":("reqskip","cookie","auth","query","context","ip_hold","optin","private","policy_private","set_cookie"),
 "wayfa":("reqskip","pathskip","cookie","sensitive","locale_conflict","signed","optin","private","set_cookie","css_veto"),
}
SAFE_COOKIE_NAMES=re.compile(r"^[A-Za-z_][A-Za-z0-9_-]{0,63}$")
SAFE_TOKEN=re.compile(r"^[A-Za-z0-9_./-]{1,100}$")
MAX_LOG_LINES=3000
WINDOW_SECONDS=1200
HTTP_TIMEOUT=13
MAX_LOAD_FOR_ORIGIN_PROBES=8.0

def run_cmd(command,timeout=15):
    return subprocess.run(command,check=False,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
                          timeout=timeout,text=True,errors="replace")

def safe_integer(value, low, high):
    try:n=int(value)
    except (ValueError,TypeError):return None
    return n if low <= n <=high else None

def safe_float(value, high=180):
    try:n=float(value)
    except (ValueError,TypeError):return None
    return n if math.isfinite(n) and 0<=n<=high else None

def percentile(values,p):
    if not values:return None
    seq=sorted(values)
    return round(seq[min(len(seq)-1,max(0,math.ceil(len(seq)*p)-1))],3)

def loaded_observer(container):
    r=run_cmd(["docker","exec","-u","33",container,"grep","-q",
               "MAD4B_CACHE_DECISION_V1","/etc/nginx/conf.d/default.conf"],timeout=8)
    return r.returncode==0

def parse_entry(line,site):
    index=line.find(PREFIX)
    if index<0 or len(line)>1200:return None
    items=line[index+len(PREFIX):].strip().split()
    fields={}
    for item in items:
        if "=" not in item: return None
        k,v=item.split("=",1)
        fields[k]=v
    if fields.get("site")!=site or fields.get("route") not in ROUTES:return None
    if fields.get("cache") not in GOOD_CACHES:return None
    if not re.fullmatch(r"[0-9a-f]{32}",fields.get("rid","")):return None
    if safe_integer(fields.get("status"),100,599) is None:return None
    if safe_float(fields.get("rt")) is None:return None
    for f in FLAGS[site]:
        if fields.get(f) not in ("0","1"):return None
    # Strict whitelist: never persist headers, cookies, request URL, IP or UA.
    return {
        "route":fields["route"],
        "cache":fields["cache"],
        "http_status":int(fields["status"]),
        "duration_seconds":float(fields["rt"]),
        "origin_seconds":safe_float(fields.get("origin")),
        "flags":{k:int(fields[k]) for k in FLAGS[site]}
    }

def host_bind_parity(host_file,container):
    p=Path(host_file)
    try:
        host_stat=p.stat()
        if p.is_symlink() or not p.is_file() or not (host_stat.st_mode & 0o004):
            return {"state":"HOST_CONFIGURATION_UNREADABLE_OR_UNSAFE"}
        host_sha=hashlib.sha256(p.read_bytes()).hexdigest()
        r=run_cmd(["docker","exec","-u","33",container,"sha256sum",
                   "/etc/nginx/conf.d/default.conf"],timeout=8)
        m=re.match(r"^([a-f0-9]{64})\s+",r.stdout)
        if r.returncode!=0 or not m:return {"state":"LIVE_BIND_UNREADABLE"}
        return {"state":"SYNCED" if m.group(1)==host_sha else "DRIFT_HOLD",
                "exact_live_host_sha256_match":m.group(1)==host_sha,
                "host_config_readable_by_nginx":True,
                "automatic_nginx_restart_allowed":False}
    except (OSError,subprocess.TimeoutExpired):
        return {"state":"CONFIG_CONSISTENCY_UNAVAILABLE"}

def sampled_metrics(site,container):
    r=run_cmd(["docker","logs","--since",str(WINDOW_SECONDS)+"s",
               "--tail",str(MAX_LOG_LINES),container],timeout=15)
    if r.returncode!=0:
        return {"state":"docker_log_unavailable","sampled_n":0}
    entries=[x for x in (parse_entry(line,site) for line in r.stdout.splitlines()) if x]
    rt=[x["duration_seconds"] for x in entries]
    origins=[x["origin_seconds"] for x in entries if x["origin_seconds"] is not None]
    cache=collections.Counter(x["cache"] for x in entries)
    route=collections.Counter(x["route"] for x in entries)
    flag_count={f:sum(x["flags"][f] for x in entries) for f in FLAGS[site]}
    return {
      "state":"sampled_aggregate",
      "sample_probability":0.2,
      "sampled_n":len(entries),
      "cache_status_counts":dict(cache),
      "route_group_counts":dict(route),
      "flag_counts":flag_count,
      "response_time_p50_seconds":percentile(rt,0.5),
      "response_time_p75_seconds":percentile(rt,0.75),
      "response_time_p95_seconds":percentile(rt,0.95),
      "origin_time_p75_seconds":percentile(origins,0.75),
      "slow_over_1s_count":sum(x>1 for x in rt),
      "requests_are_anonymized":True,
      "cannot_distinguish_all_miss_causes":True,
      "not_a_100_percent_traffic_sample":True,
    }

def synthetic_probe(host,kind):
    command=["/usr/bin/curl","-ksS","--max-time",str(HTTP_TIMEOUT),
             "--connect-timeout","5","--max-redirs","1",
             "--output","/dev/null","--dump-header","-",
             "--write-out","\nMAD4B_HTTP=%{http_code}|%{time_starttransfer}|%{time_total}\n"]
    if kind=="analytics":
        command+=["--header","Cookie: _ga=GA1.1.123456789.123456789"]
    elif kind=="unknown":
        command+=["--header","Cookie: mad4b_native_telemetry_unknown_cookie=1"]
    elif kind=="market":
        command+=["--header","Cookie: mad4b_country_selected=DE"]
    command+=["https://"+host+"/tours-and-activities/"]
    result=run_cmd(command,timeout=HTTP_TIMEOUT+4)
    part=re.search(r"MAD4B_HTTP=(\d{3})\|([\d.]+)\|([\d.]+)",result.stdout)
    if not part:return {"http_status":0,"state":"unavailable"}
    http=int(part.group(1));ttfb=safe_float(part.group(2));total=safe_float(part.group(3))
    headers=result.stdout.split("\nMAD4B_HTTP=",1)[0]
    cookies=set()
    cache="-"
    for line in headers.splitlines():
        if line.lower().startswith("set-cookie:"):
            raw=line.split(":",1)[1].strip().split("=",1)[0].strip()
            if SAFE_COOKIE_NAMES.fullmatch(raw):cookies.add(raw)
        elif line.lower().startswith(("x-wayfa-origin-cache:","x-mad4b-preset-cache:")):
            value=line.split(":",1)[1].strip()
            if value in GOOD_CACHES:cache=value
    return {"http_status":http,"ttfb_seconds":ttfb,"total_seconds":total,
            "cache_status":cache,"anonymous_response_cookie_names_only":sorted(cookies),
            "cookie_values_collected":False,"state":"read_only_probe"}

def run(site,skip_probes=False):
    config=SITES[site]
    nginx=config["nginx"]
    docker=run_cmd(["docker","inspect","--format","{{.State.Status}}",nginx],timeout=8)
    running=docker.returncode==0 and docker.stdout.strip()=="running"
    observer_active=running and loaded_observer(nginx)
    source_logs=sampled_metrics(site,nginx) if observer_active else {
       "state":"not_enabled_in_running_nginx",
       "sampled_n":0,
       "no_real_visitor_data_claimed":True,
    }
    load=os.getloadavg()[0]
    probes={}
    if running and not skip_probes and load<=MAX_LOAD_FOR_ORIGIN_PROBES:
        for kind in ("anonymous","analytics","unknown","market"):
            probes[kind]=synthetic_probe(config["host"],kind)
    result={
       "contract":"mad4b.cache-origin-native-observability.v1",
       "site":site,"hostname":config["host"],
       "at_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
       "nginx_running":running,"nginx_classifier_enabled":observer_active,
       "host_nginx_configuration":host_bind_parity(config["nginx_host_config"],nginx) if running else {"state":"OFFLINE"},
       "sampled_traffic":source_logs,
       "synthetic_class_probe":probes,
       "host_load_1m":round(load,2),
       "origin_probes_skipped_on_high_load":load>MAX_LOAD_FOR_ORIGIN_PROBES,
       "external_browser_used":False,
       "raw_cookie_values_recorded":False,
       "raw_request_paths_recorded":False,
       "request_ip_recorded":False,
       "personalized_html_cache_policy_changed":False,
       "site_write_grants_changed":False,
       "origin_performance_root_cause_closed":False,
    }
    REPORT_ROOT.mkdir(mode=0o700,parents=True,exist_ok=True)
    file=REPORT_ROOT/(site+"-latest.json")
    fd,tmp=tempfile.mkstemp(dir=str(REPORT_ROOT),prefix=".cache-obs-"+site+"-")
    try:
        with os.fdopen(fd,"w") as writer:
            json.dump(result,writer,indent=2,ensure_ascii=False)
            writer.write("\n")
        os.chmod(tmp,0o600)
        os.replace(tmp,file)
    finally:Path(tmp).unlink(missing_ok=True)
    return result

if __name__=="__main__":
    p=argparse.ArgumentParser()
    p.add_argument("--site",choices=sorted(SITES),required=True)
    p.add_argument("--skip-probes",action="store_true")
    a=p.parse_args()
    try:
        r=run(a.site,a.skip_probes)
        print(json.dumps({"contract":r["contract"],"site":a.site,
              "nginx_running":r["nginx_running"],
              "nginx_classifier_enabled":r["nginx_classifier_enabled"],
              "nginx_config_binding":r["host_nginx_configuration"]["state"],
              "sampled_n":r["sampled_traffic"]["sampled_n"],
              "synthetic_ttfb_seconds":{k:v.get("ttfb_seconds") for k,v in r["synthetic_class_probe"].items()},
              "report":str(REPORT_ROOT/(a.site+"-latest.json"))}))
    except Exception as exc:
        print(json.dumps({"state":"FAIL_CLOSED","site":a.site,"reason":str(exc)[:200]}))
        raise SystemExit(2)
