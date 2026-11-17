#!/usr/bin/env python3
"""Generate an isolated Nginx shadow candidate; never edit production in place."""
import argparse,hashlib,json,pathlib,sys
SOURCE=pathlib.Path(__file__).resolve().parent
EDITS=SOURCE/'wayfa-shadow-edits-v1.json'
def build(text,doc):
 if doc['contract']!='mad4b.cart-cache.loopback-shadow-source.v1':
  raise ValueError('unsupported contract')
 if 'mad4b_cart_shell_probe_eligible' in text:
  raise ValueError('candidate already installed')
 marker=doc['marker']
 if text.count(marker)!=1:raise ValueError('missing unique marker')
 text=text.replace(marker,doc['insert_before_marker']+marker,1)
 for edit in doc['replacements']:
  old,new=edit['before'],edit['after']
  if text.count(old)!=1:raise ValueError('missing or duplicated original anchor')
  text=text.replace(old,new,1)
 if text.count('fastcgi_no_cache $wayfa_html_no_cache_path $wayfa_html_no_cache_request')!=1:
  raise ValueError('private write veto changed')
 if text.count('listen 127.0.0.1:18081')!=1:
  raise ValueError('missing loopback listener')
 return text
def main():
 a=argparse.ArgumentParser()
 a.add_argument('--input',required=True)
 a.add_argument('--output',required=True)
 x=a.parse_args()
 origin=pathlib.Path(x.input).resolve()
 out=pathlib.Path(x.output).resolve()
 if origin==out or origin.is_symlink() or out.is_symlink():
  raise SystemExit('refuse to overwrite or follow symlink')
 if str(out).startswith('/etc/nginx') or str(out).startswith('/opt/wayfa-rebuild-20261102/stack'):
  raise SystemExit('refuse direct production output')
 doc=json.loads(EDITS.read_text())
 original=origin.read_bytes()
 if hashlib.sha256(original).hexdigest()!=doc['original_sha256']:
  raise SystemExit('exact source preimage mismatch: manual review required')
 candidate=build(original.decode(),doc).encode()
 if hashlib.sha256(candidate).hexdigest()!=doc['proposed_sha256']:
  raise SystemExit('generated Nginx source did not match verified candidate')
 out.write_bytes(candidate)
 print('CANDIDATE_VERIFIED '+hashlib.sha256(candidate).hexdigest())
if __name__=='__main__':
 main()
