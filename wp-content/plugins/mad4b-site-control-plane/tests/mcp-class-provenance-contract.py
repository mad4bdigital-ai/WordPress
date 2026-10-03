#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
config = json.loads((ROOT / "config/certified-providers.json").read_text("utf-8"))
refresh = (ROOT / "tests/refresh-runtime-integrity-baseline.py").read_text("utf-8")
runtime = (ROOT / "includes/class-mad4b-scp-mcp-class-provenance.php").read_text("utf-8")
catalog = (ROOT / "includes/class-mad4b-scp-mcp-catalog-diagnostics.php").read_text("utf-8")

required = {
    "includes/Core/McpAdapter.php",
    "includes/Domain/Tools/McpToolValidator.php",
    "includes/Domain/Tools/RegisterAbilityAsMcpTool.php",
    "includes/Domain/Utils/McpAnnotationMapper.php",
    "includes/Domain/Utils/McpValidator.php",
    "includes/Domain/Utils/SchemaTransformer.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/Tool.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolInputSchema.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolOutputSchema.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolAnnotations.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolExecution.php",
}
mcp = config["providers"]["mcp_adapter"]
critical = mcp.get("critical_files") or {}
missing = sorted(required - set(critical))
if missing:
    raise SystemExit(f"MCP class provenance baseline missing critical files: {missing}")
for path in required:
    value = critical[path]
    if not isinstance(value, str) or len(value) != 64 or any(ch not in "0123456789abcdef" for ch in value.lower()):
        raise SystemExit(f"Invalid certified SHA-256 for {path}")

for path in required:
    if path not in refresh:
        raise SystemExit(f"Integrity refresh does not preserve {path}")
    if path not in runtime:
        raise SystemExit(f"Runtime provenance mapping does not cover {path}")

for marker in (
    "MAD4B_SCP_MCP_Class_Provenance::BLOCKER",
    "runtime_class_provenance",
    "validator_reason",
    "official_schema_validation",
):
    if marker not in catalog:
        raise SystemExit(f"Catalog diagnostics missing class/validator invariant: {marker}")

print(f"mad4b.mcp-class-provenance.v1: PASS ({len(required)} critical files)")
