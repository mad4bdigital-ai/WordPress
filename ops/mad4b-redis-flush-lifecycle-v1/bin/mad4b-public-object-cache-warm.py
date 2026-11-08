#!/usr/bin/env python3
"""Post-start Redis object-cache warm-up. Uses only anonymous WPML home/blog pages."""
import argparse
import hashlib
import json
import os
import secrets
from pathlib import Path
import subprocess
import sys
import time
from urllib.parse import unquote, urlsplit

CONFIG_ROOT = Path("/etc/mad4b/cache-warm")
STATE_ROOT = Path("/var/lib/mad4b-cache-warm")
PHP_ROUTES = r'''
$site=home_url("/");
$front=(int)get_option("page_on_front");
$posts=(int)get_option("page_for_posts");
$langs=[];
$wpml=apply_filters("wpml_active_languages",null,["skip_missing"=>0]);
if(is_array($wpml) && $wpml){
    foreach($wpml as $code=>$row){
        $langs[]=["code"=>(string)$code,"engine"=>"wpml"];
    }
}else if(function_exists("pll_languages_list")){
    foreach((array)pll_languages_list(["fields"=>"slug","hide_empty"=>false]) as $code){
        $langs[]=["code"=>(string)$code,"engine"=>"polylang"];
    }
}else{
    $langs[]=["code"=>(string)determine_locale(),"engine"=>"wordpress"];
}
$out=[];
foreach($langs as $item){
    $code=$item["code"];
    $engine=$item["engine"];
    if($engine==="wpml"){
        do_action("wpml_switch_language",$code);
        $front_id=$front?(int)apply_filters("wpml_object_id",$front,"page",false,$code):0;
        $blog_id=$posts?(int)apply_filters("wpml_object_id",$posts,"page",false,$code):0;
        $fallback_home=home_url("/");
    }else if($engine==="polylang"){
        $front_id=$front?(int)pll_get_post($front,$code):0;
        $blog_id=$posts?(int)pll_get_post($posts,$code):0;
        $fallback_home=function_exists("pll_home_url")?pll_home_url($code):home_url("/");
    }else{
        $front_id=$front;
        $blog_id=$posts;
        $fallback_home=home_url("/");
    }
    // Use the actual published translation permalink, not an assumed locale
    // prefix; some languages have a distinct homepage path or redirect.
    if($front){
        if($front_id && get_post_status($front_id)==="publish"){
            $home=get_permalink($front_id);
            if($home)$out[]=["language"=>$code,"type"=>"home","url"=>$home];
        }
    }else if($fallback_home){
        // Sites showing latest posts on the front page have no static page ID.
        $out[]=["language"=>$code,"type"=>"home","url"=>$fallback_home];
    }
    if($posts && $blog_id && get_post_status($blog_id)==="publish"){
        $blog=get_permalink($blog_id);
        if($blog)$out[]=["language"=>$code,"type"=>"blog","url"=>$blog];
    }
}
if(function_exists("do_action"))do_action("wpml_switch_language",null);
echo "MAD4B_CACHE_WARM_ROUTES=".wp_json_encode(["home"=>$site,"external_object_cache"=>wp_using_ext_object_cache(),"proof_version"=>(defined("MAD4B_CACHE_WARM_PROOF_VERSION")?MAD4B_CACHE_WARM_PROOF_VERSION:null),"routes"=>$out],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
'''

def run(args, timeout=30):
    return subprocess.run(args, text=True, capture_output=True, timeout=timeout)

def single_container(project, service):
    p = run(["docker", "ps", "--filter", f"label=com.docker.compose.project={project}",
             "--filter", f"label=com.docker.compose.service={service}",
             "--format", "{{.ID}}"], timeout=12)
    if p.returncode:
        raise RuntimeError(f"docker discovery for {service} failed")
    rows = [x.strip() for x in p.stdout.splitlines() if x.strip()]
    if len(rows) != 1:
        raise RuntimeError(f"expected one healthy {service} container; discovered {len(rows)}")
    cid = rows[0]
    inspect = run(["docker", "inspect", cid], timeout=12)
    if inspect.returncode:
        raise RuntimeError(f"could not inspect {service}")
    data = json.loads(inspect.stdout)[0]
    state = data.get("State", {})
    if state.get("Status") != "running" or state.get("Health", {}).get("Status") != "healthy":
        raise RuntimeError(f"{service} is not healthy")
    return cid, state.get("StartedAt", "")

def routes_from_wordpress(php_id, expected_home, max_urls, require_proof=True):
    # Route discovery must obey the same bounded WP-CLI concurrency retry as
    # Redis readback. Never launch a parallel executor when staging is busy.
    try:
        output = wordpress_eval(php_id, PHP_ROUTES, timeout=55)
    except WPCLIBusy:
        raise
    except RuntimeError as error:
        raise RuntimeError("WPML route discovery failed: " + str(error)) from error
    prefix = "MAD4B_CACHE_WARM_ROUTES="
    rows = [line[len(prefix):] for line in output.splitlines() if line.startswith(prefix)]
    if len(rows) != 1:
        raise RuntimeError("WordPress did not return exactly one route inventory")
    doc = json.loads(rows[0])
    if doc.get("home", "").rstrip("/") != expected_home.rstrip("/"):
        raise RuntimeError("WordPress source identity differs from expected_home")
    if doc.get("external_object_cache") is not True:
        raise RuntimeError("WordPress external Object Cache is not active; refusing false warm-up success")
    if require_proof and doc.get("proof_version") != "1.0.0":
        raise RuntimeError("MAD4B WordPress cache-warm proof MU-plugin is missing/incompatible")
    expected = urlsplit(expected_home)
    unique = set()
    safe = []
    for rec in doc["routes"]:
        if rec.get("type") not in ("home", "blog"):
            continue
        uri = rec.get("url", "")
        parsed = urlsplit(uri)
        path = unquote(parsed.path).lower()
        if (parsed.scheme != "https" or parsed.hostname != expected.hostname or parsed.port not in (None,443)
           or parsed.username or parsed.password or parsed.query or parsed.fragment
           or not parsed.path.startswith("/") or any(x in path for x in
               ("/wp-admin", "/wp-json", "/cart", "/checkout", "/account",
                "/booking", "/reserve", "/order-pay", "/my-account"))):
            raise RuntimeError("Unsafe or off-company warm URL rejected")
        if uri not in unique:
            safe.append(rec)
            unique.add(uri)
    safe.sort(key=lambda r: (0 if r["type"] == "home" else 1, r["language"]))
    if not safe or len(safe) > max_urls:
        raise RuntimeError(f"Route inventory count not allowed: {len(safe)}")
    return safe


class WPCLIBusy(RuntimeError):
    """Temporary source WP-CLI capacity exhaustion; no cache state changed."""


def wordpress_eval(php_id, source, timeout=35):
    # Respect the source control plane's WP-CLI concurrency limit.
    # Never bypass STAGING_WPCLI_BUSY via another executor.
    for attempt in range(3):
        result = run(["docker", "exec", php_id, "wp", "eval", source, "--allow-root"], timeout=timeout)
        if result.returncode == 0:
            return result.stdout.strip()
        combined = (result.stderr + "\n" + result.stdout)
        if "STAGING_WPCLI_BUSY" in combined:
            if attempt == 2:
                raise WPCLIBusy("source WordPress CLI capacity exhausted after bounded retry")
            time.sleep((attempt + 1) * 10)
            continue
        raise RuntimeError("WP-CLI cache readback failed: " + combined[-200:])
    raise RuntimeError("WP-CLI contention was not resolved")

def proof_mac(php_id, nonce):
    if len(nonce) != 32 or any(ch not in "0123456789abcdef" for ch in nonce):
        raise ValueError("Invalid receipt nonce")
    output=wordpress_eval(php_id, "echo 'PROOF_MAC='.hash_hmac('sha256','"+nonce+"',wp_salt('auth'));")
    if not output.startswith("PROOF_MAC="):
        raise RuntimeError("WordPress did not sign receipt")
    mac=output[len("PROOF_MAC="):]
    if len(mac)!=64 or any(ch not in "0123456789abcdef" for ch in mac):
        raise RuntimeError("Malformed WordPress proof MAC")
    return mac

def receipt_readback(php_id, nonce):
    source=("$n='"+nonce+"'; $v=wp_cache_get('receipt_'.$n,'mad4b_cache_warm_proof',true,$hit); "
            "wp_cache_delete('receipt_'.$n,'mad4b_cache_warm_proof'); "
            "echo ($hit&&$v==='reached_wp')?'RECEIPT_PASS':'RECEIPT_MISSING';")
    return wordpress_eval(php_id,source).endswith("RECEIPT_PASS")

def redis_flush_epoch(redis_id):
    """Read-only identity. Partial warm receipts are invalid after FLUSHDB.

    No Redis key enumeration, changes, MONITOR or SLOWLOG. No secrets printed.
    """
    script=r"""export REDISCLI_AUTH="$(awk '/^[[:space:]]*requirepass[[:space:]]+/ {print $2;exit}' /usr/local/etc/redis/redis.conf)" 
redis-cli --no-auth-warning INFO commandstats | awk -F, '
/^cmdstat_flushdb:/{print $1; db=1}
/^cmdstat_flushall:/{print $1; all=1}
END{if(!db)print "cmdstat_flushdb:calls=0"; if(!all)print "cmdstat_flushall:calls=0"}'
redis-cli --no-auth-warning INFO server | grep '^run_id:'"""
    report=run(["docker","exec",redis_id,"sh","-c",script],timeout=15)
    if report.returncode:
        raise RuntimeError("Redis flush epoch is unavailable; refusing unsupported warm progress")
    import re
    calls=re.search(r"(?m)^cmdstat_flushdb:calls=(\d+)",report.stdout)
    flushall=re.search(r"(?m)^cmdstat_flushall:calls=(\d+)",report.stdout)
    runid=re.search(r"(?m)^run_id:([0-9a-f]+)",report.stdout)
    if not calls or not flushall or not runid:
        raise RuntimeError("Redis flush epoch is incomplete")
    return hashlib.sha256((runid.group(1)+"|"+calls.group(1)+"|"+flushall.group(1)).encode()).hexdigest()


def cache_sentinel(php_id, site, signature, route_digest, write=False):
    # The TTL also bounds maintenance: when a cache reset or expiration removes
    # this key, a new verified warm cycle is required.
    key="lifecycle_"+site
    value=signature+"|"+route_digest
    if write:
        script=("$k='"+key+"';$v='"+value+"'; "
                "$ok=wp_cache_set($k,$v,'mad4b_cache_warm_state',2592000); "
                "$got=wp_cache_get($k,'mad4b_cache_warm_state',true,$hit); "
                "echo ($ok&&$hit&&$got===$v)?'SENTINEL_PASS':'SENTINEL_FAIL';")
    else:
        script=("$k='"+key+"';$v='"+value+"'; "
                "$got=wp_cache_get($k,'mad4b_cache_warm_state',true,$hit); "
                "echo ($hit&&$got===$v)?'SENTINEL_PASS':'SENTINEL_MISSING';")
    return wordpress_eval(php_id,script).endswith("SENTINEL_PASS")

def warm_one(row, timeout, php_id):
    nonce=secrets.token_hex(16)
    mac=proof_mac(php_id,nonce)
    # The nonce/MAC are not printed, only the final proof result.
    cmd = ["curl", "--silent", "--show-error", "--connect-timeout", "5",
           "--max-time", str(timeout), "--max-redirs", "0",
           "--request", "GET",
           "--cookie", "mad4b_origin_cache_warm=1",
           "--user-agent", "MAD4B-Redis-ObjectWarm/3.0",
           "--header", "X-MAD4B-Cache-Warm: 1",
           "--header", "X-MAD4B-Warm-Nonce: "+nonce,
           "--header", "X-MAD4B-Warm-Mac: "+mac,
           "--output", "/dev/null",
           "--write-out", "STATUS=%{http_code} TTFB=%{time_starttransfer} TOTAL=%{time_total}",
           row["url"]]
    result=run(cmd,timeout=timeout+6)
    if result.returncode:
        raise RuntimeError(f"curl failed for {row['language']} {row['type']}: {result.stderr[-150:]}")
    status=result.stdout.strip()
    if not status.startswith("STATUS=200 "):
        raise RuntimeError(f"non-200 warm route {row['language']} {row['type']}: {status}")
    for attempt in range(3):
        if receipt_readback(php_id,nonce):
            return status
        if attempt<2:
            time.sleep(attempt+1)
    raise RuntimeError(f"WordPress/Redis receipt missing for {row['language']} {row['type']} (HTML cache false-positive?)")

def write_state(path,state):
    STATE_ROOT.mkdir(parents=True,exist_ok=True)
    tmp=path.with_suffix(".tmp")
    with open(tmp,"w") as f:
        json.dump(state,f,separators=(",",":"))
        f.write("\n")
        f.flush()
        os.fsync(f.fileno())
    os.chmod(tmp,0o600)
    os.replace(tmp,path)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--site", required=True, help="enrolled site config basename")
    ap.add_argument("--dry-run",action="store_true")
    ap.add_argument("--limit",type=int,default=0,help="probe N URLs without recording completion")
    ap.add_argument("--force",action="store_true")
    opts=ap.parse_args()
    if not opts.site or not all(ch.islower() or ch.isdigit() or ch=="-" for ch in opts.site) or len(opts.site)>64:
        raise RuntimeError("invalid site")
    config=json.loads((CONFIG_ROOT/(opts.site+".json")).read_text())
    project=config["project"]
    php_project=config.get("php_project",project)
    redis_project=config.get("redis_project",project)
    php_service=config.get("php_service","php")
    redis_service=config.get("redis_service","redis")
    expected_home=config["expected_home"]
    max_urls=min(100,max(1,int(config.get("max_urls",36))))
    batch_size=min(4,max(1,int(config.get("batch_size",4))))
    pause=min(5.0,max(.5,float(config.get("inter_request_seconds",1))))
    timeout=min(45,max(10,int(config.get("request_timeout_seconds",25))))
    if opts.limit<0 or opts.limit>max_urls:
        raise RuntimeError("limit outside valid route range")
    php,php_started=single_container(php_project,php_service)
    redis,redis_started=single_container(redis_project,redis_service)
    signature=hashlib.sha256((redis+"|"+redis_started+"|"+php+"|"+php_started).encode()).hexdigest()

    # Critical P0: validation always precedes SKIP, including when the
    # Docker lifetime signature did not change.
    urls=routes_from_wordpress(php,expected_home,max_urls)
    route_digest=hashlib.sha256(json.dumps(urls,sort_keys=True,separators=(",",":")).encode()).hexdigest()
    print(f"CHECK: {opts.site} services=healthy wp_object_cache=on receipt=ready languages={len(set(x['language'] for x in urls))} routes={len(urls)}",flush=True)
    if opts.dry_run:
        for row in urls:
            print(f"PLAN {row['language']} {row['type']} {row['url']}",flush=True)
        return 0

    state_path=STATE_ROOT/(opts.site+".json")
    try:
        state=json.loads(state_path.read_text()) if state_path.exists() else {}
    except (ValueError,OSError):
        state={}
    epoch=redis_flush_epoch(redis)
    matches=(state.get("signature")==signature and state.get("route_digest")==route_digest)
    epoch_previous=state.get("redis_flush_epoch")
    epoch_matches=(epoch_previous==epoch)
    if not opts.force and not opts.limit and matches and state.get("complete") is True:
        if (epoch_matches or epoch_previous is None) and cache_sentinel(php,opts.site,signature,route_digest,write=False):
            if epoch_previous is None:
                state["redis_flush_epoch"]=epoch
                write_state(state_path,state)
            print(f"SKIP: {opts.site} validated WordPress/Redis sentinel and current FLUSHDB epoch",flush=True)
            return 0
        print("REWARM: Redis flush epoch changed or persistent sentinel absent",flush=True)

    completed=set()
    if not opts.force and not opts.limit and matches and state.get("complete") is False and epoch_matches:
        completed=set(state.get("completed",[]))
    elif matches and state.get("complete") is False and state.get("completed"):
        print("REWARM: old partial receipts cannot be trusted after Redis FLUSHDB or epoch migration",flush=True)
    pending=[row for row in urls if row["url"] not in completed]
    if opts.limit:
        pending=urls[:opts.limit]
    else:
        pending=pending[:batch_size]
    if not pending and not opts.limit:
        if len(completed)!=len(urls):
            raise RuntimeError("Partial state has no remaining routes; cannot mark complete")
        # All route receipts are already verified; retry only the Redis sentinel
        # after a transient failure, not all public pages again.
        if redis_flush_epoch(redis)!=epoch:
            raise RuntimeError("Redis FLUSHDB changed before sentinel write")
        if not cache_sentinel(php,opts.site,signature,route_digest,write=True):
            raise RuntimeError("Cannot persist/readback company Redis warm sentinel")
        state["complete"]=True
        state["completed_at_utc"]=time.strftime("%Y-%m-%dT%H:%M:%SZ",time.gmtime())
        write_state(state_path,state)
        print(f"WARM_COMPLETE: {opts.site} {len(urls)}/{len(urls)} recovered sentinel",flush=True)
        return 0

    state={"site":opts.site,"signature":signature,"route_digest":route_digest,
           "redis_flush_epoch":epoch,
           "route_count":len(urls),"completed":sorted(completed),"complete":False,
           "updated_at_utc":time.strftime("%Y-%m-%dT%H:%M:%SZ",time.gmtime())}
    for i,row in enumerate(pending):
        if i:time.sleep(pause)
        status=warm_one(row,timeout,php)
        if redis_flush_epoch(redis)!=epoch:
            raise RuntimeError("Redis FLUSHDB occurred during warm; refuse false completion")
        print(f"PASS {row['language']} {row['type']} {status} RECEIPT=verified",flush=True)
        if not opts.limit:
            completed.add(row["url"])
            state["completed"]=sorted(completed)
            state["updated_at_utc"]=time.strftime("%Y-%m-%dT%H:%M:%SZ",time.gmtime())
            write_state(state_path,state)
    if opts.limit:
        print("PROBE_ONLY: state unchanged",flush=True)
        return 0
    if len(completed)<len(urls):
        print(f"WARM_PROGRESS: {opts.site} {len(completed)}/{len(urls)}; resume next timer",flush=True)
        return 0
    if redis_flush_epoch(redis)!=epoch:
        raise RuntimeError("Redis FLUSHDB changed before sentinel write")
    if not cache_sentinel(php,opts.site,signature,route_digest,write=True):
        raise RuntimeError("Cannot persist/readback company Redis warm sentinel")
    state["complete"]=True
    state["completed_at_utc"]=time.strftime("%Y-%m-%dT%H:%M:%SZ",time.gmtime())
    write_state(state_path,state)
    print(f"WARM_COMPLETE: {opts.site} {len(urls)}/{len(urls)} with Redis sentinel",flush=True)
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except WPCLIBusy as e:
        # Deferred is neither a verified SKIP nor a cache failure. Preserve
        # on-disk completion and retry on the existing systemd timer.
        print("WARM_DEFERRED: WPCLI_BUSY; no state or sentinel changed; " + str(e), flush=True)
        sys.exit(75)
    except Exception as e:
        print("WARM_FAILED: " + str(e), file=sys.stderr, flush=True)
        sys.exit(1)
