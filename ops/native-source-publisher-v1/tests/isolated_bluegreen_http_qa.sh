#!/usr/bin/env bash
# Isolated HTTP A/B/A experiment. Never touches production Docker/Traefik.
set -euo pipefail
test "$#" -eq 3
plan="$1"; candidate="$2"; out="$3"
test -f "$plan" && test -d "$out"
mapfile -t bound < <(python3 - "$plan" "$candidate" <<'PY'
import json,re,subprocess,sys
doc=json.load(open(sys.argv[1]))
assert doc['contract']=='mad4b.immutable-runtime.candidate.v1'
assert doc['state']=='BUILD_CANDIDATE_NOT_AUTHORIZED_FOR_TRAFFIC'
base=doc['base_image_id']
assert re.fullmatch(r'sha256:[a-f0-9]{64}',base)
img=json.loads(subprocess.check_output(['docker','image','inspect',sys.argv[2]],text=True))[0]
assert img['Id']!=base and re.fullmatch(r'sha256:[a-f0-9]{64}',img['Id'])
labels=img['Config']['Labels']
assert labels['org.mad4b.runtime.plan']==doc['runtime_plan_sha256']
assert labels['org.mad4b.runtime.site']==doc['site']['site_uuid']
assert len(doc['layers'])==1
slug=doc['layers'][0]['plugin']
assert re.fullmatch(r'[a-z][a-z0-9-]{2,90}',slug)
for value in (base,img['Id'],slug,doc['runtime_plan_sha256']):print(value)
PY
)
test "${#bound[@]}" -eq 4
base="${bound[0]}"; image="${bound[1]}"
plugin="${bound[2]}"; plan_sha="${bound[3]}"
qa=$(mktemp -d "$out/bluegreen-http-qa-XXXXXX")
chmod 0700 "$qa"
short=$(basename "$qa" | cut -c 1-28 | tr 'A-Z_' 'a-z-')
network="mad4b-rtqa-$short"
a="$network-a";b="$network-b";proxy="$network-proxy"
cleanup() {
  docker rm -f "$proxy" "$a" "$b" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
python3 - "$qa" "$plugin" <<'PY'
from pathlib import Path
import sys
path=Path(sys.argv[1]);slug=sys.argv[2]
target='/opt/mad4b/immutable-runtime/wp-content/plugins/'+slug
content='''<?php
if(isset($_GET['slow']))sleep(3);
$dir='PLUGIN_PATH';
header('Content-Type: application/json');
echo json_encode(['slot'=>is_dir($dir)?'B':'A',
 'candidate_code_present'=>is_dir($dir),'qa_only'=>true]),"\n";
'''.replace('PLUGIN_PATH',target)
(path/'health.php').write_text(content)
PY
mkdir "$qa/nginx"
configure(){
 cat > "$qa/nginx/default.conf.next" <<NGINX
server {
  listen 8080;
  access_log off;
  location / {
    proxy_pass http://$1:8080;
    proxy_http_version 1.1;
    proxy_read_timeout 15s;
  }
}
NGINX
 mv "$qa/nginx/default.conf.next" "$qa/nginx/default.conf"
}
configure "$a"
docker network create --internal "$network" >/dev/null
for slot in a b;do
 if [ "$slot" = a ];then name="$a";image_ref="$base"
 else name="$b";image_ref="$image";fi
 docker run -d --name "$name" --network "$network" --read-only \
  --cap-drop ALL --security-opt no-new-privileges --pids-limit 100 --memory 256m \
  -v "$qa":/qa:ro --entrypoint php "$image_ref" -S 0.0.0.0:8080 -t /qa >/dev/null
done
sleep 1
for name in "$a" "$b";do
 docker ps --format '{{.Names}}' | grep -Fxq "$name"
done
docker run -d --name "$proxy" --network "$network" --read-only \
 --cap-drop ALL --cap-add CHOWN --cap-add SETUID --cap-add SETGID \
 --security-opt no-new-privileges --pids-limit 100 --memory 128m \
 --tmpfs /var/cache/nginx:rw,size=16m --tmpfs /var/run:rw,size=8m \
 --tmpfs /tmp:rw,size=8m -v "$qa/nginx":/etc/nginx/conf.d:ro \
 --entrypoint nginx nginx:1.28-alpine -g 'daemon off;' >/dev/null
sleep 1
if ! docker ps --format '{{.Names}}' | grep -Fxq "$proxy";then
 docker logs "$proxy" 2>&1 | tail -20;exit 13
fi
docker exec "$proxy" wget -qO- http://127.0.0.1:8080/health.php > "$qa/phase-a.json"
docker exec "$proxy" wget -qO- 'http://127.0.0.1:8080/health.php?slow=1' > "$qa/inflight-a.json" &
inflight=$!
sleep 0.7
configure "$b"
docker exec "$proxy" nginx -s reload >/dev/null
sleep 0.5
docker exec "$proxy" wget -qO- http://127.0.0.1:8080/health.php > "$qa/phase-b.json"
wait "$inflight"
configure "$a"
docker exec "$proxy" nginx -s reload >/dev/null
sleep 0.5
docker exec "$proxy" wget -qO- http://127.0.0.1:8080/health.php > "$qa/rollback-a.json"
python3 - "$qa" "$plan_sha" "$base" "$image" <<'PY'
from pathlib import Path
import json,sys,hashlib,datetime
root=Path(sys.argv[1])
names=('phase-a','phase-b','inflight-a','rollback-a')
data={name:json.loads((root/(name+'.json')).read_text()) for name in names}
slots=[data[name]['slot'] for name in names]
assert slots==['A','B','A','A'],slots
report={
 'contract':'mad4b.immutable-runtime.offline-bluegreen-http-qa.v1',
 'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
 'runtime_plan_sha256':sys.argv[2],
 'base_image_id':sys.argv[3],
 'candidate_image_id':sys.argv[4],
 'qa_network_internal':True,
 'published_host_ports':0,
 'production_traefik_changed':False,
 'production_wp_changed':False,
 'http_a_b_inflight_a_rollback_a':slots,
 'inflight_old_request_completed':True,
 'new_candidate_request_completed':True,
 'real_wordpress_boot_proven':False,
 'php_fpm_cutover_proven':False,
 'original313_certified':False,
 'responses':data,
}
path=root/'qa-result.json'
path.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
path.chmod(0o600)
print(json.dumps({'report':str(path),
 'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),
 'http_sequence':slots,'production_modified':False}))
PY
