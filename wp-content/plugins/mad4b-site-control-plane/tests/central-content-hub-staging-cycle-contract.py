#!/usr/bin/env python3
"""Static fail-closed contract for registered Content Hub Staging upgrade test."""
import ast
from pathlib import Path
repo=Path(__file__).resolve().parents[4]
p=repo/"ops/central-rollout/content-hub-local-git-staging-cycle.py"
s=p.read_text()
ast.parse(s)
required=[
  'SOURCE = Path("/opt/mad4b/operations/central-release/local-git-sources/mad4b-content-hub")',
  'EXPECTED_COMMIT = "03b2c26d27ef9d74e59b492b06c900ee0e741d0e"',
  'EXPECTED_ARTIFACT = "cb22e6eb0703c807390b416642c9ce1f050194f44d5584372b48ccc43fd4d550"',
  '"rollout_scope":"staging_verified_only"',
  'if state["url"]!="https://tours.dev.mad4b.com"',
  'if file_manifest(LIVE)!=manifest',
  'shutil.copytree(LIVE,backup',
  'os.rename(LIVE,old)',
  'os.rename(old,LIVE)',
  'smoke(manifest,state)',
  'emit_readback(manifest,state)',
  'if receipt.exists():raise RuntimeError("receipt already exists - refuse replay")',
]
for rule in required:
    assert rule in s, "missing safety rule: "+rule
for invalid in ['"company_production"','wayfatours-production','--allow-root','shell=True']:
    assert invalid not in s, "canary should never target commercial production "+invalid
print("MAD4B_CONTENT_HUB_REGISTERED_STAGING_CYCLE_CONTRACT: PASS")
