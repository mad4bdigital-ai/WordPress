#!/usr/bin/env bash
# Staging review-metadata writer. Actual persistent write is performed
# ONLY by isolated Docker UID 33 with network disabled, no Linux capabilities,
# no Docker socket, and no access to WordPress or privileged host files.
# This must never be repurposed as a privileged GitHub/root-code writer.
set -euo pipefail
if [[ $# -ne 0 ]]; then echo "NO_ARGUMENTS_ACCEPTED" >&2; exit 2; fi
repo=/opt/staging/mad4b-all-open-prs-zero-gap-20261101
ref=refs/remotes/origin/feature/central-local-native-handoff-20261101
output=/opt/staging/mad4b-container-parity-20261101/proposals
writer=/opt/staging/mad4b-container-parity-20261101/write-reviewed-parity-proposal.php
image=wordpress:php8.5-fpm
[[ "$(id -u)" -eq 0 ]] || { echo "HOST_READ_ATTESTATION_REQUIRES_OPERATOR" >&2; exit 2; }
[[ -f "$writer" && ! -L "$writer" && -d "$output" && ! -L "$output" ]] || exit 2
[[ "$(stat -c '%u:%a' "$writer")" == "0:644" ]] || exit 2
source_hash=$(git -C "$repo" show "$ref:ops/mad4b-broker/container/write-reviewed-parity-proposal.php" | sha256sum | cut -d' ' -f1)
installed_hash=$(sha256sum "$writer" | cut -d' ' -f1)
[[ "$source_hash" == "$installed_hash" ]] || { echo "REVIEW_WRITER_SOURCE_DRIFT" >&2; exit 2; }
[[ "$(stat -c '%u:%a' "$output")" == "33:700" ]] || exit 2
[[ "$(docker inspect -f '{{.Id}}' "$image" 2>/dev/null)" != "" ]] || exit 2

# This host phase performs only fixed-path READS of original evidence.
# It does not edit the checkout, issue GitHub API writes, or execute Shell Jobs.
python3 - "$repo" "$ref" <<'PY' |
import hashlib,json,pathlib,subprocess,sys
repo,ref=sys.argv[1:]
head=subprocess.check_output(['git','-C',repo,'rev-parse',ref],text=True).strip()
files={
'ops/company-readiness-workflow/executor/vps-native-operator.py':'/opt/mad4b-company-transport/native-operator-v1/vps-native-operator.py',
'ops/mad4b-broker/vps-native-central-auto-dispatch.py':'/opt/mad4b-company-transport/broker-v1/vps-native-central-auto-dispatch.py',
'ops/mad4b-broker/vps-native-central-dispatch-worker.py':'/opt/mad4b-company-transport/broker-v1/vps-native-central-dispatch-worker.py',
'ops/mad4b-broker/systemd/mad4b-native-central-dispatch.service':'/etc/systemd/system/mad4b-native-central-dispatch.service',
'ops/mad4b-broker/systemd/mad4b-native-central-dispatch.timer':'/etc/systemd/system/mad4b-native-central-dispatch.timer'}
entries=[]
for filename,live in files.items():
    result=subprocess.run(['git','-C',repo,'show',ref+':'+filename],capture_output=True)
    source_hash=hashlib.sha256(result.stdout).hexdigest() if result.returncode==0 else None
    target=pathlib.Path(live)
    if not target.is_file() or target.is_symlink():
        raise RuntimeError("ORIGINAL_STAGING_ARTIFACT_MISSING_OR_SYMLINK")
    entries.append({'path':filename,'source_sha256':source_hash,
                    'staging_sha256':hashlib.sha256(target.read_bytes()).hexdigest()})
obj={'contract':'mad4b.container-parity-review-proposal.v1',
 'site':'https://tours.dev.mad4b.com','environment':'staging',
 'scope':'review_only','source_branch':'feature/central-local-native-handoff-20261101',
 'source_head':head,'entries':entries}
sys.stdout.write(json.dumps(obj,separators=(',',':')))
PY
docker run --rm -i --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --pids-limit 64 --memory 128m \
  --user 33:33 \
  --mount "type=bind,source=$writer,target=/app/write-reviewed-parity-proposal.php,readonly" \
  --mount "type=bind,source=$output,target=/workspace/proposals" \
  "$image" php /app/write-reviewed-parity-proposal.php
