#!/usr/bin/env python3
"""Non-distributable, exact-pinned PUBLIC key; reject a secret/key path in plugin."""
from pathlib import Path
import hashlib,subprocess
ROOT=Path(__file__).resolve().parents[2]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
key=Path(__file__).with_name('central-release-issuer-public.pem')
if (PLUGIN/'config/central-release-issuer-public.pem').exists():raise SystemExit('ISSUER_KEY_IN_DISTRIBUTABLE_PACKAGE_DENIED')
raw=key.read_bytes()
if hashlib.sha256(raw).hexdigest()!='eb7e753bc93cb0151f483e546bb8a2d09de85915a30699db4f4f179a360f7f01':raise SystemExit('PUBLIC_ISSUER_KEY_SOURCE_DRIFT')
if b'PRIVATE KEY' in raw or b'-----BEGIN PUBLIC KEY-----' not in raw:raise SystemExit('ISSUER_KEY_NOT_PUBLIC')
r=subprocess.run(['openssl','pkey','-pubin','-noout'],input=raw,capture_output=True,timeout=10)
if r.returncode:raise SystemExit('PUBLIC_ISSUER_KEY_PARSE_FAILED')
print('ORIGINAL305_PUBLIC_ISSUER_KEY_SOURCE_ONLY: PASS')
