#!/bin/sh
set -eu
[ "$1" = "-l" ] || exit 2
[ "$#" -eq 2 ] && [ -f "$2" ] || exit 2
command -v docker >/dev/null 2>&1 || exit 3
docker inspect etg-woo-lab-php >/dev/null 2>&1 || exit 4
cat "$2" | docker exec -i -u www-data etg-woo-lab-php php -l
