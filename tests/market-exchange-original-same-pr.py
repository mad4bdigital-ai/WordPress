#!/usr/bin/env python3
"""First-party market source only, no remote supplier/WordPress mutation."""
from pathlib import Path
import subprocess
ROOT=Path(__file__).resolve().parents[1]
SOURCE='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-market-content-exchange.php'
BLOB='5d36607ed992c326d35178a2422097cf1244f16f'
TEST="wp-content/plugins/mad4b-site-control-plane/tests/market-growth-competitor-dmc-runtime.php"
blob=subprocess.check_output(["git","hash-object",SOURCE],cwd=ROOT,text=True).strip()
assert blob==BLOB,"Market exchange source changed; new review required"
assert Path(SOURCE).name in (ROOT/TEST).read_text(encoding="utf-8")
r=subprocess.run(["php",TEST],cwd=ROOT,capture_output=True,text=True,timeout=75,check=False)
out=r.stdout+"\\n"+r.stderr
assert r.returncode==0 and "PASS general competitor market rules" in out,out[-1200:]
print("PASS: MARKET_EXCHANGE_SAME_PR original bounded competitor/market cases",blob[:12])
