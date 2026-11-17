#!/usr/bin/env python3
"""Version exactly one authenticated WPML public route in the native cache.

No wildcard, query, guessed URL or remote request is accepted. This is
required before capturing a new page (fresh market/CSS headers), and after
publishing its independent page certificate (fresh Ready:1 public HTML).
"""
import hashlib,importlib.util,json,pathlib,re,socket,stat,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location("public_queue",ROOT/"public-route-queue.py")
queue=importlib.util.module_from_spec(spec);spec.loader.exec_module(queue)
ROOTS={
 "source":("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content","tours.dev.mad4b.com"),
 "wayfa":("/opt/wayfa-rebuild-20261102/site-copy/wp-content","wayfatours.com")
}
def sha(file):
    return hashlib.sha256(file.read_bytes()).hexdigest()
def validate(site,root,catalog,route,mode,measured=None):
    if site not in ROOTS or str(root)!=ROOTS[site][0]:
        raise ValueError("untrusted-company-root")
    host=ROOTS[site][1]
    trusted=queue.safe_catalog(catalog,site)
    if route not in trusted or route["type"] not in queue.TYPES:
        raise ValueError("untrusted-or-unqualified-public-route")
    path=route["path"]
    if path in ("/","/"+route["language"]+"/") or len(path)>350:
        raise ValueError("home-or-out-of-scope-route")
    if mode not in ("before_capture","after_promotion"):
        raise ValueError("invalid-cache-release-phase")
    if mode=="before_capture":return path
    if not isinstance(measured,dict):
        raise ValueError("missing-post-publication-measured-market")
    cur=measured.get("currency")
    market=measured.get("market_country")
    if (not isinstance(cur,str) or not re.fullmatch(r"[A-Z]{3}|NONE",cur)
        or not isinstance(market,str) or not re.fullmatch(r"static|base|[A-Z]{2}",market)):
        raise ValueError("market-or-currency-proof-invalid")
    cache_key=hashlib.sha256((host+"|"+route["language"]+"|"+path
                              +"|"+market+"|"+cur).encode()).hexdigest()
    cert=root/"mad4b-public-critical-css-v1/certs"/(cache_key+".json")
    if cert.is_symlink() or not cert.is_file() or cert.stat().st_size>8192:
        raise ValueError("certificate-not-published")
    proof=json.loads(cert.read_text())
    if (proof.get("contract")!="mad4b.public-critical-css.route-certificate.v1"
        or proof.get("status")!="certified"
        or proof.get("host")!=host
        or proof.get("path")!=path
        or proof.get("language")!=route["language"]
        or proof.get("type")!=route["type"]
        or proof.get("object_id")!=route["id"]
        or proof.get("market_country")!=market
        or proof.get("currency")!=cur
        or proof.get("dependencies",{}).get("route_generation")!=route["revision"]
        or proof.get("css_epoch")!=measured.get("css_epoch")
        or proof.get("runtime_sha")!=measured.get("runtime_sha")
        or proof.get("runtime_sha")!=sha(root/"mu-plugins/422-mad4b-public-critical-css-runtime.php")
        or proof.get("critical_cap_px")!=767
        or proof.get("evidence",{}).get("decision")!="passed_evidence"):
        raise ValueError("published-route-certificate-revision-or-market-drift")
    css_name=proof.get("css_file")
    critical_name=proof.get("critical_file")
    if (not isinstance(css_name,str) or not re.fullmatch(r"[a-f0-9]{32}[.]css",css_name)
        or not isinstance(critical_name,str) or not re.fullmatch(r"[a-f0-9]{64}[.]css",critical_name)):
        raise ValueError("certificate-CSS-identity-invalid")
    source=root/"litespeed/css"/css_name
    if source.is_symlink() or not source.is_file():
        source=root/"litespeed/.mad4b-asset-retention/css"/css_name
    asset=root/"mad4b-public-critical-css-v1/assets"/critical_name
    for f,key in [(source,"css_sha"),(asset,"critical_sha")]:
        if f.is_symlink() or not f.is_file() or sha(f)!=proof.get(key):
            raise ValueError("published-CSS-file-drift")
    return path
def rotate(root,path):
    endpoint=root/".mad4b-purge/origin.sock"
    if endpoint.is_symlink() or not endpoint.exists() or not stat.S_ISSOCK(endpoint.stat().st_mode):
        raise ValueError("native-origin-generation-socket-unavailable")
    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as client:
        client.settimeout(9)
        client.connect(str(endpoint))
        client.sendall(json.dumps([path],separators=(",",":")).encode()+b"\n")
        line=client.makefile("rb").readline(4096)
    reply=json.loads(line)
    if (reply.get("status")!="ok" or reply.get("paths")!=1
        or reply.get("versioned")!=1):
        raise ValueError("selective-cache-generation-not-acknowledged")
    return reply
def main():
    if len(sys.argv)!=8:raise ValueError("usage: mode site wp-root catalog route measured-or-minus receipt")
    mode,site,root,cat,route,state,receipt=sys.argv[1:]
    target=Path(root)
    cat=queue.read(cat);route=queue.read(route)
    measured=None if state=="-" else queue.read(state)
    path=validate(site,target,cat,route,mode,measured)
    result=rotate(target,path)
    Path(receipt).write_text(json.dumps({
        "contract":"mad4b.public-ccss.route-cache-release.v1",
        "site":site,"mode":mode,"path":path,"result":result
    },sort_keys=True,indent=2)+"\n")
    print("PUBLIC_CCSS_SELECTIVE_CACHE_RELEASE",site,mode,path,result.get("status"))
if __name__=="__main__":
    try:main()
    except (OSError,ValueError,KeyError,TypeError,IndexError,json.JSONDecodeError) as e:
        print("HOLD_PUBLIC_CCSS_CACHE_RELEASE",str(e),file=sys.stderr);sys.exit(4)
