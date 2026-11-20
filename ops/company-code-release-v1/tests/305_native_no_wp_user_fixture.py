from pathlib import Path
import json,base64,hashlib,time,secrets
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization
fields=['contract','company','environment','site_uuid','canonical_origin','host_id','grant_id','actor_user_id','method','route','body_sha256','issued','expires','nonce']
key=Ed25519PrivateKey.generate()
body='{}'
now=int(time.time())
def make(route,method,body):
    data={
        'contract':'mad4b.original-305-signed-native-cli.v1',
        'company':'wayfatours','environment':'production',
        'site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
        'canonical_origin':'https://wayfatours.com',
        'host_id':'srv2030908','grant_id':'1'*32,'actor_user_id':1,
        'method':method,'route':route,'body_sha256':hashlib.sha256(body.encode()).hexdigest(),
        'issued':now,'expires':now+60,'nonce':secrets.token_hex(16),
    }
    assert list(data)==fields
    data['signature']=base64.b64encode(key.sign('\n'.join(str(data[x]) for x in fields).encode())).decode()
    return data
root=Path(__import__('os').environ.get('MAD4B_305_TEST_OUTPUT','/tmp/mad4b-305-no-user-tests'))
root.mkdir(exist_ok=True)
payload={
  'proof':make('/mad4b/v1/central-execution-lanes/wayfatours/commands','POST',body),
  'get_proof':make('/mad4b/v1/central-execution-lanes/wayfatours/commands/'+'b'*32,'GET',''),
  'body':body,'public_key_b64':base64.b64encode(key.public_key().public_bytes(
      serialization.Encoding.Raw,serialization.PublicFormat.Raw)).decode()
}
(root/'fixture.json').write_text(json.dumps(payload)+'\n')
print('created ephemeral signed synthetic fixture with fresh key')
