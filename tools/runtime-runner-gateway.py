#!/usr/bin/env python3
"""Optional loopback-only runner gateway. TLS is REQUIRED at external ingress.

This process MUST stay bound to loopback behind a separately configured TLS
reverse proxy. Never expose an unauthenticated arbitrary-command endpoint.
All executable requests are RSA-signed and pinned to the enrolled site.
"""
import argparse, importlib.util, json, pathlib, sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives import serialization

def load_worker(path):
    spec=importlib.util.spec_from_file_location("mad4b_runtime_worker",path)
    mod=importlib.util.module_from_spec(spec)
    sys.modules[spec.name]=mod
    spec.loader.exec_module(mod)
    return mod

class GatewayHandler(BaseHTTPRequestHandler):
    def log_message(self,fmt,*args):
        # No input payloads or bearer material in HTTP logs.
        pass
    def respond(self,status,payload):
        body=json.dumps(payload,separators=(",",":"),sort_keys=True).encode()
        self.send_response(status)
        self.send_header("Content-Type","application/json")
        self.send_header("Cache-Control","no-store")
        self.send_header("Content-Length",str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def do_GET(self):
        if self.path!="/v1/discovery":
            return self.respond(404,{"error":"not_found"})
        return self.respond(200,{"contract":"mad4b.runner-discovery.v1",
          "backends":{"isolated_vps_runner":{"available":True},
                      "ssh_host_runner":{"available":False},
                      "isolated_docker_runner":{"available":True}},
          "execution_authorized":False})
    def do_POST(self):
        if self.path!="/v1/execute":
            return self.respond(404,{"error":"not_found"})
        try:
            length=int(self.headers.get("Content-Length","0"))
            if not 1<=length<=4096:
                return self.respond(413,{"error":"request_size"})
            data=json.loads(self.rfile.read(length))
            result=self.server.worker.process(data,self.server.site_key,self.server.worker_key,
                self.server.nonce_dir,self.server.enrolled_site)
            return self.respond(200,result)
        except Exception:
            return self.respond(403,{"error":"request_denied"})
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--site-public-key",required=True)
    ap.add_argument("--worker-private-key",required=True)
    ap.add_argument("--nonce-dir",required=True)
    ap.add_argument("--enrolled-site",required=True)
    ap.add_argument("--port",type=int,default=8765)
    ap.add_argument("--bind",default="127.0.0.1")
    args=ap.parse_args()
    if args.bind not in ("127.0.0.1","::1") or not 1024<=args.port<=65535:
        raise SystemExit("loopback_only")
    worker=load_worker(pathlib.Path(__file__).with_name("runtime-runner-worker.py"))
    pub=serialization.load_pem_public_key(pathlib.Path(args.site_public_key).read_bytes())
    key=serialization.load_pem_private_key(pathlib.Path(args.worker_private_key).read_bytes(),password=None)
    if not isinstance(pub,rsa.RSAPublicKey) or not isinstance(key,rsa.RSAPrivateKey):
        raise SystemExit("invalid_key_type")
    server=ThreadingHTTPServer((args.bind,args.port),GatewayHandler)
    server.worker=worker
    server.site_key=pub
    server.worker_key=key
    server.nonce_dir=pathlib.Path(args.nonce_dir)
    server.enrolled_site=args.enrolled_site
    server.serve_forever(poll_interval=0.2)
if __name__=="__main__":
    main()
