#!/usr/bin/env python3
"""Fail-closed PHP-token equivalence to exact previously-reviewed source blob.

This is *not* a general similarity claim. Comments and whitespace are the only
ignored PHP tokens; source behavior still requires the native runtime fixture.
"""
import base64
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ("wp-content/plugins/mad4b-site-control-plane/includes/"
        "class-mad4b-scp-staging-source-selector.php")
PRIOR_REVIEWED_SHA = "528943e1b569598b58261716e1cd0852594d2b80"
PHP = r"""
$input = json_decode(stream_get_contents(STDIN), true);
if (!is_array($input) || count($input) !== 3) exit(3);
$digests = array();
foreach ($input as $bytes) {
    $source = base64_decode($bytes, true);
    if (!is_string($source)) exit(4);
    $parts = array();
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))
                continue;
            $parts[] = array($token[0],$token[1]);
        } else $parts[] = array(0,$token);
    }
    $digests[] = hash('sha256',json_encode($parts));
}
echo json_encode($digests);
"""


def main():
    old = subprocess.run(
        ["git", "cat-file", "blob", PRIOR_REVIEWED_SHA],
        cwd=ROOT, capture_output=True, check=True
    ).stdout
    current = (ROOT / PATH).read_bytes()
    # Negative control must differ by a PHP code token rather than whitespace.
    mutated = current.replace(b"<?php", b"<?php $unexpected_write = true;", 1)
    assert mutated != current and old != b""
    raw = json.dumps([
        base64.b64encode(x).decode("ascii") for x in (old, current, mutated)
    ])
    result = subprocess.run(["php", "-r", PHP], input=raw,
                            text=True, capture_output=True, cwd=ROOT, check=True)
    digests = json.loads(result.stdout)
    if len(digests) != 3 or digests[0] != digests[1] or digests[1] == digests[2]:
        raise AssertionError("Prior reviewed staging selector source is not execution-token equivalent")
    print("STAGING_SELECTOR_REVIEWED_TOKEN_EQUIVALENCE: PASS"
          " previous reviewed blob, current exact source and negative code mutation")


if __name__ == "__main__":
    main()
