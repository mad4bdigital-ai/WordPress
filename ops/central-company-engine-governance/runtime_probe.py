#!/usr/bin/env python3
"""Exact, read-only native Core registry readback from enrolled central Staging."""
import json
import subprocess
from pathlib import Path

CONTAINER = "etg-commerce-staging-php"
ORIGIN = "https://tours.dev.mad4b.com"
OPERATIONS = ("company.plugin-sync.request","wordpress.runtime.converge","wordpress.taxonomy.evolve")
MARK = "MAD4B_ENGINE_GOVERNANCE_READBACK:"
PHP = r'''
$names = array("company.plugin-sync.request","wordpress.runtime.converge","wordpress.taxonomy.evolve");
$map = array();
foreach ($names as $id) {
    $v = class_exists("MAD4B_SCP_Operation_Registry") ? MAD4B_SCP_Operation_Registry::operation($id) : null;
    $map[$id] = is_array($v) ? "mapped" : (is_wp_error($v) ? $v->get_error_code() : "registry_unavailable");
}
echo "MAD4B_ENGINE_GOVERNANCE_READBACK:" . wp_json_encode(array(
    "contract"=>"mad4b.central-company-core-registry-readback.v1",
    "environment"=>wp_get_environment_type(),
    "home"=>home_url(),
    "operation_registry_loaded"=>class_exists("MAD4B_SCP_Operation_Registry"),
    "operations"=>$map,
    "authorizing"=>false,
    "mutation_performed"=>false
));
'''
def probe(run=subprocess.run):
    args = ["docker","exec",CONTAINER,"sh","-lc",
        "cd /var/www/html && wp --allow-root eval " +
        "'" + PHP.replace("'", "'\\''") + "'" + " --url=" + ORIGIN]
    result = run(args, capture_output=True, text=True, timeout=55, check=False)
    if result.returncode != 0:
        raise RuntimeError("native_registry_probe_failed")
    records = [x.split(MARK,1)[1] for x in result.stdout.splitlines() if x.startswith(MARK)]
    if len(records) != 1:
        raise RuntimeError("native_registry_probe_ambiguous")
    data=json.loads(records[0])
    if (data.get("contract") != "mad4b.central-company-core-registry-readback.v1"
            or data.get("environment") != "staging"
            or data.get("home","").rstrip("/") != ORIGIN.rstrip("/")
            or data.get("operation_registry_loaded") is not True
            or data.get("mutation_performed") is not False
            or data.get("authorizing") is not False
            or set(data.get("operations",{})) != set(OPERATIONS)):
        raise RuntimeError("native_registry_readback_binding_invalid")
    return data

if __name__ == "__main__":
    print(json.dumps(probe(),indent=2,sort_keys=True))
