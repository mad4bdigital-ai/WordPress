#!/usr/bin/env bash
set -euo pipefail
work="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
container="${MAD4B_AUTHORITY_QA_DB_CONTAINER:?Set authorized QA DB container}"
network="${MAD4B_AUTHORITY_QA_DOCKER_NETWORK:?Set isolated QA network}"
suffix=$(openssl rand -hex 4)
db=mad4b_authority_qa_$suffix
usr=authority_qa_$suffix
pwfile=$work/qa-password-$suffix
sqlfile=$work/qa-provision-$suffix.sql
unsetfile=$work/qa-cleanup-$suffix.sql
umask 077
openssl rand -hex 32 > "$pwfile"
cleanup() {
  printf "DROP DATABASE IF EXISTS %s; DROP USER IF EXISTS '%s'@'%%';\n" "$db" "$usr" > "$unsetfile"
  docker cp "$unsetfile" "$container:/tmp/qa-cleanup-$suffix.sql" >/dev/null
  docker exec "$container" sh -c "MYSQL_PWD=\$(cat /run/secrets/root_password) mariadb -u root < /tmp/qa-cleanup-$suffix.sql" >/dev/null
  docker exec "$container" rm -f "/tmp/qa-cleanup-$suffix.sql" "/tmp/qa-provision-$suffix.sql"
  rm -f "$pwfile" "$sqlfile" "$unsetfile"
}
trap cleanup EXIT
python3 - "$db" "$usr" "$pwfile" "$sqlfile" <<'PY'
import sys
from pathlib import Path
database,user,pwfile,out=sys.argv[1:]
pw=Path(pwfile).read_text().strip()
Path(out).write_text(f"CREATE DATABASE {database}; CREATE USER '{user}'@'%' IDENTIFIED BY '{pw}'; GRANT ALL PRIVILEGES ON {database}.* TO '{user}'@'%';\n")
PY
docker cp "$sqlfile" "$container:/tmp/qa-provision-$suffix.sql" >/dev/null
docker exec "$container" sh -c "MYSQL_PWD=\$(cat /run/secrets/root_password) mariadb -u root < /tmp/qa-provision-$suffix.sql" >/dev/null
docker run --rm --network "$network" --cap-drop=ALL --security-opt=no-new-privileges --pids-limit=100 --read-only --tmpfs /tmp:rw,nosuid,nodev,size=32m -e MAD4B_AUTHORITY_QA_ONLY=1 -e AUTH_QA_DB="$db" -e AUTH_QA_DB_USER="$usr" -v "$work:/work:ro" -v "$pwfile:/run/secrets/qa_password:ro" "${MAD4B_AUTHORITY_QA_PHP_IMAGE:-wordpress:php8.3-fpm}" php /work/test-ledger-single-owner.php
