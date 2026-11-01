#!/usr/bin/env python3
"""End-to-end loopback HTTPS-terminator backend acceptance (HTTP bound to loopback only)."""
import base64, json, os, pathlib, socket, subprocess, tempfile, time, urllib.request, urllib.error, importlib.util
from cryptography.hazmat.primitives.asymmetric import rsa, padding
from cryptography.hazmat.primitives import serialization, hashes
root=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("rr",root/"tools/runtime-runner-worker.py")
rr=importlib.util.module_from_spec(spec);spec.loader.exec_module(rr)
site=rsa.generate_private_key(public_exponent=65537,key_size=2048)
worker=rsa.generate_private_key(public_exponent=65537,key_size=2048)
with tempfile.TemporaryDirectory() as td:
 d=pathlib.Path(td)
 (d/"site.pub").write_bytes(site.public_key().public_bytes(serialization.Encoding.PEM,serialization.PublicFormat.SubjectPublicKeyInfo))
 (d/"worker.key").write_bytes(worker.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
 with socket.socket() as s:
  s.bind(("127.0.0.1",0))
  port=s.getsockname()[1]
 cmd=["python3",str(root/"tools/runtime-runner-gateway.py"),"--site-public-key",str(d/"site.pub"),
  "--worker-private-key",str(d/"worker.key"),"--nonce-dir",str(d/"nonces"),
  "--enrolled-site","staging-test-site","--port",str(port)]
 srv=subprocess.Popen(cmd,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  url="http://127.0.0.1:"+str(port)
  for _ in range(40):
   try:
    info=json.load(urllib.request.urlopen(url+"/v1/discovery",timeout=1))
    break
   except (OSError,urllib.error.URLError):time.sleep(.1)
  else:raise AssertionError("gateway not reachable")
  assert info["contract"]=="mad4b.runner-discovery.v1" and not info["execution_authorized"]
  t=int(time.time())
  req={"contract":rr.CONTRACT,"site":"staging-test-site","operation":"runtime.status.read",
    "nonce":"cd"*16,"issued_at":t-1,"expires_at":t+60,
    "target":"isolated-diagnostic","plan_sha256":"a"*64}
  payload={"request":req,"signature":base64.b64encode(site.sign(rr.canonical(req),padding.PKCS1v15(),hashes.SHA256())).decode()}
  wire=json.dumps(payload).encode()
  def post():
   return urllib.request.urlopen(urllib.request.Request(url+"/v1/execute",data=wire,headers={"Content-Type":"application/json"}),timeout=30)
  result=json.load(post())
  worker.public_key().verify(base64.b64decode(result["signature"]),rr.canonical(result["receipt"]),padding.PKCS1v15(),hashes.SHA256())
  assert result["receipt"]["result"]=="ok" and result["receipt"]["site"]=="staging-test-site"
  try:post()
  except urllib.error.HTTPError as e:assert e.code==403
  else:raise AssertionError("replay accepted")
  print("PASS: LOOPBACK_GATEWAY_SIGNED_DOCKER_RECEIPT_AND_REPLAY")
 finally:
  srv.terminate()
  try:srv.wait(timeout=3)
  except subprocess.TimeoutExpired:srv.kill()
