#!/usr/bin/env python3
from pathlib import Path
import json
root=Path(__file__).resolve().parents[1]
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8");plugin=(root/"includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8");oauth=(root/"includes/class-mad4b-scp-oauth-resource-bridge.php").read_text(encoding="utf-8");policy=(root/"includes/class-mad4b-scp-egress-policy.php").read_text(encoding="utf-8");cfg=json.loads((root/"config/egress-policy.json").read_text(encoding="utf-8"))
if "class-mad4b-scp-egress-policy.php" not in main: raise SystemExit("FAIL bootstrap")
if "MAD4B_SCP_Egress_Policy::boot()" not in plugin: raise SystemExit("FAIL runtime hook")
for m in ["MAD4B_SCP_Egress_Policy::mark_request( 'oauth_discovery'","MAD4B_SCP_Egress_Policy::mark_request( 'oauth_jwks'"]:
 if m not in oauth: raise SystemExit("FAIL OAuth integration "+m)
for m in ["sslverify","redirection","reject_unsafe_urls","mad4b_egress_dns_answer_changed","mad4b_egress_proxy_untrusted","FILTER_FLAG_NO_PRIV_RANGE","mad4b_egress_tls_verification_failed"]:
 if m not in policy: raise SystemExit("FAIL invariant "+m)
if cfg.get("contract")!="mad4b.egress-policy.v1" or cfg.get("default_unknown_purpose")!="deny": raise SystemExit("FAIL config")
for p in ("oauth_discovery","oauth_jwks","remote_media_discovery","remote_media_import"):
 r=cfg["purposes"][p]
 if r.get("require_https") is not True or r.get("sslverify_required") is not True or r.get("max_redirects")!=0: raise SystemExit("FAIL purpose "+p)
print("mad4b.egress-policy.contract.v1: PASS")
