#!/usr/bin/env bash
set -euo pipefail

# ETG DFSB governed Staging deployment transaction.
# The plugin is self-contained for installed-build provenance. A detached
# receipt is optional release/package evidence and is no longer required for
# runtime exact-build closure.

usage() {
  cat >&2 <<'EOF'
usage:
  deploy-staging-atomic.sh PACKAGE_ZIP WP_ROOT EXPECTED_GIT_SHA EXPECTED_TREE_SHA EXPECTED_VERSION RUN_ID [RECEIPT]

Required environment guards:
  ETG_DEPLOY_ENVIRONMENT=staging
  ETG_DEPLOY_ORIGIN=https://staging.egypttourgates.com

RECEIPT is optional. When supplied it is validated and persisted as additional
package-level evidence. When omitted the installed-content manifest embedded in
the plugin is the canonical runtime provenance source.
EOF
  exit 64
}

[[ $# -eq 6 || $# -eq 7 ]] || usage

PACKAGE_ZIP=$1
WP_ROOT=${2%/}
EXPECTED_GIT_SHA=$3
EXPECTED_TREE_SHA=$4
EXPECTED_VERSION=$5
RUN_ID=$6
RECEIPT=${7:-}

[[ "${ETG_DEPLOY_ENVIRONMENT:-}" == 'staging' ]] || { echo 'refusing non-staging environment' >&2; exit 65; }
[[ "${ETG_DEPLOY_ORIGIN:-}" == 'https://staging.egypttourgates.com' ]] || { echo 'refusing non-staging origin' >&2; exit 65; }
[[ $EXPECTED_GIT_SHA =~ ^[0-9a-f]{40}$ ]] || { echo 'invalid expected git sha' >&2; exit 65; }
[[ $EXPECTED_TREE_SHA =~ ^[0-9a-f]{40}$ ]] || { echo 'invalid expected tree sha' >&2; exit 65; }
[[ $RUN_ID =~ ^[0-9]+$ ]] || { echo 'invalid run id' >&2; exit 65; }
[[ -f "$PACKAGE_ZIP" ]] || { echo 'package missing' >&2; exit 66; }
[[ -z "$RECEIPT" || -f "$RECEIPT" ]] || { echo 'optional receipt path supplied but file missing' >&2; exit 66; }
[[ -d "$WP_ROOT/wp-content/plugins" ]] || { echo 'wordpress plugin root missing' >&2; exit 66; }
[[ -f "$WP_ROOT/wp-config.php" ]] || { echo 'wordpress root guard failed' >&2; exit 66; }

for cmd in php unzip sha256sum awk grep mktemp mv cp mkdir rm; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "required command missing: $cmd" >&2; exit 69; }
done

TMP_IDENTITY=$(mktemp)
trap 'rm -f "$TMP_IDENTITY"' EXIT
unzip -p "$PACKAGE_ZIP" 'etg-dynamic-filter-seo-bridge/build-identity.json' > "$TMP_IDENTITY"
[[ -s $TMP_IDENTITY ]] || { echo 'embedded identity missing from package' >&2; exit 67; }
IDENTITY_SHA=$(sha256sum "$TMP_IDENTITY" | awk '{print $1}')

php -r '
$d=json_decode(file_get_contents($argv[1]),true);
if (!is_array($d)) { fwrite(STDERR,"identity json invalid\n"); exit(1); }
$expected=array(
  "contract"=>"etg.dfsb.embedded-build-identity.v1",
  "git_sha"=>$argv[2],
  "tree_sha"=>$argv[3],
  "plugin_version"=>$argv[4],
);
if ($d !== $expected) { fwrite(STDERR,"embedded identity fields mismatch\n"); exit(1); }
' "$TMP_IDENTITY" "$EXPECTED_GIT_SHA" "$EXPECTED_TREE_SHA" "$EXPECTED_VERSION"

CONTENT_ROOT="$WP_ROOT/wp-content"
PLUGIN_DIR="$CONTENT_ROOT/plugins/etg-dynamic-filter-seo-bridge"
DEPLOY_ROOT="$CONTENT_ROOT/mad4b/deployments/etg-dfsb/$EXPECTED_GIT_SHA/$RUN_ID"
EXTRACT_ROOT="$DEPLOY_ROOT/extract"
STAGED_PLUGIN="$EXTRACT_ROOT/etg-dynamic-filter-seo-bridge"
BACKUP_PLUGIN="$DEPLOY_ROOT/previous-plugin"
PROVENANCE_DIR="$CONTENT_ROOT/mad4b/provenance/etg-dfsb/$EXPECTED_GIT_SHA"
PROVENANCE_FILE="$PROVENANCE_DIR/etg-dfsb-provenance.txt"
PROVENANCE_TMP="$PROVENANCE_DIR/.etg-dfsb-provenance.txt.$RUN_ID.tmp"

[[ ! -e "$DEPLOY_ROOT" ]] || { echo 'deployment transaction already exists' >&2; exit 68; }
mkdir -p "$EXTRACT_ROOT"
unzip -q "$PACKAGE_ZIP" -d "$EXTRACT_ROOT"
[[ -f "$STAGED_PLUGIN/includes/Diagnostics/BuildIdentity.php" ]] || { echo 'staged plugin structure invalid' >&2; exit 68; }
[[ -f "$STAGED_PLUGIN/build-identity.json" ]] || { echo 'staged identity missing' >&2; exit 68; }
[[ -f "$STAGED_PLUGIN/installed-content-manifest.json" ]] || { echo 'staged installed-content manifest missing' >&2; exit 68; }

PACKAGE_SHA=$(sha256sum "$PACKAGE_ZIP" | awk '{print $1}')
RECEIPT_PACKAGE_SHA=''
RECEIPT_INSTALLED=false

receipt_value() {
  local key=$1 count
  count=$(grep -c "^${key}=" "$RECEIPT" || true)
  [[ $count -eq 1 ]] || { echo "receipt key count invalid: $key=$count" >&2; exit 67; }
  awk -F= -v wanted="$key" '$1 == wanted { sub(/^[^=]*=/, ""); print; exit }' "$RECEIPT"
}

if [[ -n "$RECEIPT" ]]; then
  CONTRACT=$(receipt_value contract)
  RECEIPT_GIT_SHA=$(receipt_value git_sha)
  RECEIPT_TREE_SHA=$(receipt_value tree_sha)
  RECEIPT_VERSION=$(receipt_value plugin_version)
  RECEIPT_PACKAGE_SHA=$(receipt_value package_sha256)
  RECEIPT_IDENTITY_CONTRACT=$(receipt_value embedded_identity_contract)
  RECEIPT_IDENTITY_SHA=$(receipt_value embedded_identity_sha256)

  [[ $CONTRACT == 'etg.dfsb.release-provenance.v6' ]] || { echo 'receipt contract mismatch' >&2; exit 67; }
  [[ $RECEIPT_IDENTITY_CONTRACT == 'etg.dfsb.embedded-build-identity.v1' ]] || { echo 'identity contract mismatch' >&2; exit 67; }
  [[ $RECEIPT_GIT_SHA == "$EXPECTED_GIT_SHA" ]] || { echo 'receipt git sha mismatch' >&2; exit 67; }
  [[ $RECEIPT_TREE_SHA == "$EXPECTED_TREE_SHA" ]] || { echo 'receipt tree sha mismatch' >&2; exit 67; }
  [[ $RECEIPT_VERSION == "$EXPECTED_VERSION" ]] || { echo 'receipt version mismatch' >&2; exit 67; }
  [[ $RECEIPT_PACKAGE_SHA =~ ^[0-9a-f]{64}$ ]] || { echo 'receipt package sha invalid' >&2; exit 67; }
  [[ $RECEIPT_IDENTITY_SHA =~ ^[0-9a-f]{64}$ ]] || { echo 'receipt identity sha invalid' >&2; exit 67; }
  [[ $PACKAGE_SHA == "$RECEIPT_PACKAGE_SHA" ]] || { echo 'package digest mismatch' >&2; exit 67; }
  [[ $IDENTITY_SHA == "$RECEIPT_IDENTITY_SHA" ]] || { echo 'embedded identity digest mismatch' >&2; exit 67; }

  mkdir -p "$PROVENANCE_DIR"
  cp "$RECEIPT" "$PROVENANCE_TMP"
  chmod 0644 "$PROVENANCE_TMP" 2>/dev/null || true
  mv "$PROVENANCE_TMP" "$PROVENANCE_FILE"
  RECEIPT_INSTALLED=true
fi

rollback() {
  local status=$?
  set +e
  if [[ -d "$PLUGIN_DIR" ]]; then
    rm -rf "$PLUGIN_DIR"
  fi
  if [[ -d "$BACKUP_PLUGIN" ]]; then
    mv "$BACKUP_PLUGIN" "$PLUGIN_DIR"
  fi
  if [[ "$RECEIPT_INSTALLED" == true ]]; then
    rm -f "$PROVENANCE_FILE"
  fi
  echo "deployment rollback completed after verification failure" >&2
  exit "$status"
}

if [[ -d "$PLUGIN_DIR" ]]; then
  mv "$PLUGIN_DIR" "$BACKUP_PLUGIN"
fi
if ! mv "$STAGED_PLUGIN" "$PLUGIN_DIR"; then
  if [[ -d "$BACKUP_PLUGIN" ]]; then
    mv "$BACKUP_PLUGIN" "$PLUGIN_DIR"
  fi
  if [[ "$RECEIPT_INSTALLED" == true ]]; then
    rm -f "$PROVENANCE_FILE"
  fi
  echo 'plugin swap failed; previous plugin restored' >&2
  exit 68
fi

trap rollback ERR

php -r '
define("WP_CONTENT_DIR", $argv[1]);
define("ETG_DFSB_DIR", rtrim($argv[2],"/\\") . DIRECTORY_SEPARATOR);
define("ETG_DFSB_VERSION", $argv[3]);
require $argv[2] . "/includes/Diagnostics/BuildIdentity.php";
$r=\ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect();
$checks=array(
  !empty($r["valid"]),
  ($r["git_sha"] ?? "") === $argv[4],
  ($r["tree_sha"] ?? "") === $argv[5],
  ($r["plugin_version"] ?? "") === $argv[3],
  !empty($r["installed_content_present"]),
  !empty($r["installed_content_valid"]),
  ($r["installed_content_reason"] ?? "") === "ok",
  ($r["provenance_mode"] ?? "") === "self_contained_installed_manifest",
  !empty($r["provenance_complete"]),
  ($r["exact_build_reason"] ?? "") === "ok",
);
if (in_array(false,$checks,true)) {
  fwrite(STDERR,json_encode($r,JSON_UNESCAPED_SLASHES) . "\n");
  exit(1);
}
if ($argv[6] === "with-receipt") {
  if (empty($r["package_provenance_valid"]) || ($r["package_sha256"] ?? "") !== $argv[7]) {
    fwrite(STDERR,json_encode($r,JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
  }
} else {
  if (!empty($r["package_provenance_required"])) {
    fwrite(STDERR,"detached receipt unexpectedly required\n");
    exit(1);
  }
}
echo json_encode($r,JSON_UNESCAPED_SLASHES) . "\n";
' "$CONTENT_ROOT" "$PLUGIN_DIR" "$EXPECTED_VERSION" "$EXPECTED_GIT_SHA" "$EXPECTED_TREE_SHA" "$([[ "$RECEIPT_INSTALLED" == true ]] && echo with-receipt || echo self-contained)" "$RECEIPT_PACKAGE_SHA"

trap - ERR

echo "ETG_DFSB_STAGING_DEPLOYMENT=PASS"
echo "git_sha=$EXPECTED_GIT_SHA"
echo "tree_sha=$EXPECTED_TREE_SHA"
echo "package_sha256=$PACKAGE_SHA"
echo "embedded_identity_sha256=$IDENTITY_SHA"
echo "provenance_mode=self_contained_installed_manifest"
echo "detached_receipt_installed=$RECEIPT_INSTALLED"
