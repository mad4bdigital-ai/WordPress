#!/bin/sh
# Run candidate Control Plane read-only Abilities in the installed PHP runtime
# with a mock WordPress context. Does not install candidate files on any site.
set -eu
REPO=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SRC="$REPO/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-source-release-review.php"
BOOT="$REPO/tests/native-source-central-review/bootstrap.php"
VERIFY="$REPO/tests/native-source-central-review/acceptance.php"
{
  cat "$BOOT"
  cat "$SRC"
  printf '\n?>\n'
  cat "$VERIFY"
} | docker exec -i php-wkgtkzta35wkkgouwt6ruljc php
