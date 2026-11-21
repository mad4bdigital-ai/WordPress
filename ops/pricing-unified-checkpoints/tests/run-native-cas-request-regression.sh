#!/usr/bin/env bash
set -euo pipefail
C="$1"
W=/opt/mad4b-company-provisioning/worktrees/form6-guide-language-20261119
docker cp "$W/ops/pricing-unified-checkpoints/tests/verify-native-pricing-cas-request.php" "$C":/tmp/verify-native-pricing-cas-request.php >/dev/null
for mode in get unsigned stale; do
  if [ "$mode" = get ]; then expected=native_pricing_cas_post_required
  elif [ "$mode" = unsigned ]; then expected=native_pricing_cas_nonce_missing
  else expected="Pricing Controls has changed since this page was opened"
  fi
  out="$(docker exec -e MAD4B_CAS_TEST_MODE="$mode" "$C" sh -c 'cd /var/www/html && wp eval-file /tmp/verify-native-pricing-cas-request.php --allow-root' 2>&1)" || :
  if [[ "$out" != *"$expected"* ]] || [[ "$out" == *SECURITY_FAILURE_NATIVE_GUARD_RETURNED* ]]; then
    echo "FAIL native CAS mode=$mode";printf '%s\n' "$out"|tail -5;exit 1
  fi
  echo "PASS native CAS $mode -> $expected"
done
out="$(docker exec -e MAD4B_CAS_TEST_MODE=valid "$C" sh -c 'cd /var/www/html && wp eval-file /tmp/verify-native-pricing-cas-request.php --allow-root' 2>&1)" || :
if [[ "$out" != *SIGNED_NATIVE_CAS_ADMISSION_NO_WRITE* ]]; then
 echo 'FAIL signed native CAS admission'; printf '%s\n' "$out"|tail -5;exit 1
fi
echo 'PASS signed POST admission (no vendor Save executed)'
